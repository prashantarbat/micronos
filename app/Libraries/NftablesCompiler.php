<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS nftables Ruleset Compiler
 * ===================================
 * Compiles database-defined firewall rules, NAT rules, interfaces, aliases,
 * Squid TPROXY redirects, and VPN pass-throughs into a high-performance,
 * atomic Linux nftables configuration file (/etc/nftables.conf).
 */
class NftablesCompiler
{
    /**
     * Virtual device the NAT64 / DNS64 plugin brings up (tayga's tun device).
     * Fixed by micrond's plugin RPC (`NAT64_TUN`).
     */
    protected const NAT64_DEVICE = 'nat64';

    /**
     * Virtual device the IPv6 tunnel plugin brings up (6in4 / SIT).
     * Fixed by micrond's plugin RPC (`TUNNEL_IFACE`).
     */
    protected const TUNNEL_DEVICE = 'sit1';

    protected ?BaseConnection $db;

    /**
     * Interface addressing for dynamic tokens ({lan:network}, {wan:address}),
     * keyed by lower-case interface id and name.
     *
     * @var array<string, array{network: ?string, address: ?string}>
     */
    protected array $ifaceAddr = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Compiles full nftables ruleset from database records.
     *
     * @return string Valid nftables ruleset text
     */
    public function compile(): string
    {
        $interfaces = $this->fetchInterfaces();
        $addresses = $this->fetchAddresses();
        $addrGroups = $this->fetchAddressGroups();
        $services = $this->fetchServices();
        $serviceGroups = $this->fetchServiceGroups();
        $fwRules = $this->fetchFirewallRules();
        $dnatRules = $this->fetchDnatRules();
        $snatPools = $this->fetchSnatPools();
        $natNptv6 = $this->fetchNatNptv6();
        $natSettings = $this->fetchNatSettings();
        $pbrRules = $this->fetchPbrRules();
        $schedules = $this->fetchSchedules();
        // Policies bound to a disabled schedule are paused: drop them before
        // anything (filter chain, policy SNAT) is generated from them.
        $fwRules = array_values(array_filter(
            $fwRules,
            fn ($r) => $this->isScheduleActive($r['schedule'] ?? null, $schedules)
        ));
        $multiWan = $this->compileMultiWan();

        // Policy-Based Routing rules are enforced in two halves: a forward-chain
        // mark picks the packet out (part of this ruleset), and a routing policy
        // sends the marked packet down the chosen table (kernel `ip rule` /
        // `ip route` state, applied separately by micrond). The table a rule
        // selects is resolved here so both halves agree on the number.
        $vrfs = $this->fetchVrfs();

        // Map every logical interface id/name to the concrete kernel device so
        // filter, DNAT and SNAT rules reference eth0 rather than the friendly
        // name "WAN" (which nftables would happily compile but never match).
        $deviceMap = $this->deviceMap($interfaces);
        $this->ifaceAddr = $this->interfaceAddresses($interfaces);

        // Layer 4 DoS / DDoS attack mitigation policies (paused when their
        // schedule is disabled, time-restricted via meta day/hour otherwise)
        $dosPolicies   = array_values(array_filter(
            $this->fetchDosPolicies(),
            fn ($p) => $this->isScheduleActive($p['schedule'] ?? null, $schedules)
        ));
        $dosMitigation = $this->compileDosPolicies($dosPolicies, $deviceMap, $schedules);

        // Identify WAN/external devices for security and outbound NAT
        $wanDevices = [];
        foreach ($interfaces as $iface) {
            $id   = strtolower($iface['id'] ?? '');
            $name = strtolower($iface['name'] ?? '');
            $type = strtolower($iface['interface_type'] ?? '');
            $dev  = $iface['physical_device'] ?? '';
            if (($id === 'wan' || $name === 'wan' || $type === 'wan' || str_starts_with($id, 'wan') || str_starts_with($name, 'wan')) && $dev !== '') {
                $wanDevices[] = $dev;
            }
        }
        $wanDevices = array_values(array_unique(array_filter($wanDevices)));

        // Identify LAN/internal devices for management access and plug-and-play forwarding
        $lanDevices = [];
        foreach ($interfaces as $iface) {
            $id   = strtolower($iface['id'] ?? '');
            $name = strtolower($iface['name'] ?? '');
            $type = strtolower($iface['interface_type'] ?? '');
            $dev  = $iface['physical_device'] ?? '';
            if (($id === 'lan' || $name === 'lan' || $type === 'lan' || str_starts_with($id, 'lan') || str_starts_with($name, 'lan') || str_starts_with($id, 'br') || str_starts_with($dev, 'br')) && $dev !== '') {
                $lanDevices[] = $dev;
            }
        }
        // Fallback: any non-WAN physical interface is considered LAN
        if (empty($lanDevices)) {
            foreach ($interfaces as $iface) {
                $dev = $iface['physical_device'] ?? '';
                if ($dev !== '' && empty($iface['is_vpn']) && !in_array($dev, $wanDevices, true)) {
                    $lanDevices[] = $dev;
                }
            }
        }
        $lanDevices = array_values(array_unique(array_filter($lanDevices)));

        $adminAccessByDevice = [];
        foreach ($interfaces as $iface) {
            if ((int) ($iface['enabled'] ?? 1) !== 1) {
                continue;
            }
            $device = trim((string) ($iface['physical_device'] ?? ''));
            if ($device === '') {
                continue;
            }
            $access = $this->interfaceAdminAccess($iface);
            if ($access !== []) {
                $adminAccessByDevice[$device] = array_values(array_unique(array_merge(
                    $adminAccessByDevice[$device] ?? [],
                    $access
                )));
            }
        }

        // Master IPv6 switch (System > Networking). When off, no IPv6 packet is
        // ever accepted — the stack is considered completely disabled.
        $ipv6 = $this->ipv6Enabled();

        $lines = [];
        $lines[] = '#!/usr/sbin/nft -f';
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled nftables Ruleset';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';
        $lines[] = 'flush ruleset';
        $lines[] = '';

        // 1. Define Filter Table
        $lines[] = 'table inet micron_filter {';
        
        // Dynamic Sets
        $lines[] = '    # Dynamic Bogon/Martian Address Sets';
        $lines[] = '    set bogons {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        $lines[] = '        elements = { 0.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 224.0.0.0/4, 240.0.0.0/4 }';
        $lines[] = '    }';
        $lines[] = '';

        // Geography sets — one per country used by a `geoip` address object.
        // Policies reference them as `ip saddr @geo_us`.
        foreach ($this->geoIpSetDeclarations($addresses) as $line) {
            $lines[] = $line;
        }

        // Domain sets — one per FQDN address object (wildcards included).
        // Policies reference them as `ip daddr @fqdn_example_com`.
        foreach ($this->fqdnSetDeclarations($addresses) as $line) {
            $lines[] = $line;
        }

        // Internet-service sets — one per service used by an
        // `internet-service` address object. Policies reference them as
        // `ip daddr @isdb_microsoft_365`.
        foreach ($this->internetServiceSetDeclarations($addresses) as $line) {
            $lines[] = $line;
        }

        // Source-tracking maps — one per load-balanced Multi-WAN group with
        // sticky connections enabled. Each records the gateway mark a source
        // address was assigned so its next connection reuses that gateway.
        foreach ($multiWan['maps'] as $line) {
            $lines[] = $line;
        }

        // DoS / DDoS Mitigation Sets (Dynamic blacklist tracking)
        foreach ($dosMitigation['sets'] as $line) {
            $lines[] = $line;
        }

        // DoS Mitigation Chain
        foreach ($dosMitigation['chain'] as $line) {
            $lines[] = $line;
        }

        // Input Chain
        $lines[] = '    chain input {';
        $lines[] = '        type filter hook input priority filter; policy drop;';
        $lines[] = '';
        $lines[] = '        # Connection tracking (Stateful Inspection)';
        $lines[] = '        ct state established,related accept';
        $lines[] = '        ct state invalid drop';
        if (!empty($dosMitigation['chain'])) {
            $lines[] = '';
            $lines[] = '        # DDoS Mitigation & Rate Limiting';
            $lines[] = '        jump dos_mitigation;';
        }
        $lines[] = '';
        $lines[] = '        # Loopback interface';
        $lines[] = '        iifname "lo" accept';
        $lines[] = '';
        $lines[] = '        # ICMP Rate Limiting';
        $lines[] = '        ip protocol icmp icmp type { destination-unreachable, time-exceeded } limit rate 50/second accept';
        if ($ipv6) {
            $lines[] = '        ip6 nexthdr icmpv6 icmpv6 type != echo-request accept';
        } else {
            $lines[] = '        meta nfproto ipv6 drop comment "IPv6 disabled system-wide"';
        }
        $lines[] = '';

        $sysSettings  = $this->fetchSystemSettings();
        $sshPort      = (int) ($sysSettings['ssh_port'] ?? 22);
        if ($sshPort <= 0 || $sshPort > 65535) {
            $sshPort = 22;
        }
        $webguiPort   = (int) ($sysSettings['webgui_port'] ?? 443);
        if ($webguiPort <= 0 || $webguiPort > 65535) {
            $webguiPort = 443;
        }
        $webguiProto  = strtolower((string) ($sysSettings['webgui_protocol'] ?? 'https'));
        $redirectHttp = (int) ($sysSettings['webgui_redirect_http'] ?? 1);

        // Per-interface administrative access. These explicit accepts precede
        // the WAN drop below, so a selected WAN service is opt-in, not exposed
        // by the default policy.
        foreach ($adminAccessByDevice as $device => $access) {
            $tcpPorts = [];
            if (in_array('http', $access, true)) {
                $tcpPorts[] = 80;
                if ($webguiProto === 'http') {
                    $tcpPorts[] = $webguiPort;
                }
            }
            if (in_array('https', $access, true)) {
                $tcpPorts[] = 443;
                $tcpPorts[] = 8443;
                if ($webguiProto === 'https') {
                    $tcpPorts[] = $webguiPort;
                }
            }
            if (in_array('ssh', $access, true)) {
                $tcpPorts[] = $sshPort;
            }
            $tcpPorts = array_values(array_unique($tcpPorts));
            sort($tcpPorts);
            if ($tcpPorts !== []) {
                $ports = count($tcpPorts) === 1 ? (string) $tcpPorts[0] : '{ ' . implode(', ', $tcpPorts) . ' }';
                $lines[] = '        iifname "' . $device . '" tcp dport ' . $ports . ' accept comment "Administrative access: ' . implode(',', $access) . '"';
            }
            if (in_array('snmp', $access, true)) {
                $lines[] = '        iifname "' . $device . '" udp dport 161 accept comment "Administrative access: SNMP"';
            }
            if (in_array('ping', $access, true)) {
                $lines[] = '        iifname "' . $device . '" ip protocol icmp icmp type echo-request limit rate 50/second accept comment "Administrative access: PING"';
                if ($ipv6) {
                    $lines[] = '        iifname "' . $device . '" ip6 nexthdr icmpv6 icmpv6 type echo-request accept comment "Administrative access: IPv6 PING"';
                }
            }
        }
        if ($adminAccessByDevice !== []) {
            $lines[] = '';
        }

        // In-Browser Clientless Gateway (ZTNA) Settings
        $ztnaSettings = $this->fetchZtnaSettings();
        $ztnaEnabled  = (bool) ((int) ($ztnaSettings['enabled'] ?? 0) === 1);
        $ztnaPort     = (int) ($ztnaSettings['external_port'] ?? 443);
        if ($ztnaPort <= 0 || $ztnaPort > 65535) {
            $ztnaPort = 443;
        }
        // Let's Encrypt validates the gateway hostname over plain HTTP on port 80.
        // WAN tcp/80 is redirected to the gateway's own ACME listener (see the
        // nat prerouting chain), never to the management UI on :80.
        $ztnaAcme = $ztnaEnabled && (int) ($ztnaSettings['acme_enabled'] ?? 0) === 1;

        // Gateway access "controlled by firewall rules": instead of a blanket
        // accept, every interface's traffic to the gateway port is checked
        // against the operator's This-Firewall policies in the ztna_gateway
        // chain, which ends in a logged default deny. The jump sits before the
        // WAN catch-all drop, so WAN sources can be allowed by policy.
        $ztnaControlled = $ztnaEnabled && ($ztnaSettings['access_mode'] ?? 'open') === 'firewall';
        $ztnaChain = $ztnaControlled
            ? $this->compileZtnaGatewayChain($fwRules, $ztnaPort, $addresses, $addrGroups, $services, $serviceGroups, $deviceMap, $schedules)
            : [];
        if ($ztnaControlled) {
            $lines[] = '        # Clientless Gateway (ZTNA): access decided by firewall policies (chain ztna_gateway)';
            $lines[] = '        tcp dport ' . $ztnaPort . ' jump ztna_gateway comment "ZTNA Clientless Gateway access policy"';
            $lines[] = '';
        }

        // HA cluster: VRRP adverts, conntrackd state sync and config sync,
        // each limited to the configured peer address.
        foreach ($this->haClusterRules() as $rule) {
            $lines[] = '        ' . $rule;
        }

        // WAN Security & Services
        if (!empty($wanDevices)) {
            $wanIifs = count($wanDevices) === 1 ? '"' . reset($wanDevices) . '"' : '{ "' . implode('", "', $wanDevices) . '" }';
            $lines[] = '        # Allow DHCP client on WAN (incoming offer/ack from upstream modem/ISP)';
            $lines[] = '        iifname ' . $wanIifs . ' udp dport 68 accept comment "WAN DHCP Client Reply"';
            $lines[] = '';

            // If ZTNA Clientless Gateway is enabled, permit inbound connections from WAN to gateway port
            if ($ztnaEnabled) {
                $lines[] = '        # Clientless Gateway (ZTNA) In-Browser Remote Access';
                if (!$ztnaControlled) {
                    $lines[] = '        iifname ' . $wanIifs . ' tcp dport ' . $ztnaPort . ' accept comment "ZTNA Clientless Gateway (WAN Ingress)"';
                }
                if ($ztnaAcme) {
                    // Only connections the prerouting redirect produced: port 8480
                    // itself is not opened on WAN.
                    $lines[] = '        iifname ' . $wanIifs . ' tcp dport ' . ZtnaCompiler::ACME_PORT . ' ct status dnat accept comment "ZTNA Gateway Let\'s Encrypt HTTP-01 (WAN tcp/80)"';
                }
                $lines[] = '';
            }

            $lines[] = '        # WAN Security: Drop all unauthorized management probes and port scans from WAN';
            $baseStealth = [80, 443, 8443, 22, 23];
            $extraStealth = [];
            if ($sshPort !== 22 && $sshPort > 0 && $sshPort <= 65535) {
                $extraStealth[] = $sshPort;
            }
            if ($webguiPort !== 443 && $webguiPort !== 8443 && $webguiPort !== 80 && $webguiPort > 0 && $webguiPort <= 65535) {
                $extraStealth[] = $webguiPort;
            }
            $stealthPorts = array_values(array_unique(array_merge($baseStealth, $extraStealth)));
            if (!empty($stealthPorts)) {
                $lines[] = '        iifname ' . $wanIifs . ' tcp dport { ' . implode(', ', $stealthPorts) . ' } counter drop comment "Stealth Rule: Drop WAN Management Probing"';
            }
            $lines[] = '        iifname ' . $wanIifs . ' counter drop comment "WAN Security: Drop unsolicited inbound WAN traffic"';
            $lines[] = '';
        }

        // Core Network Services: DNS and DHCP remain LAN-only regardless of
        // Administrative Access selections.
        if (!empty($lanDevices)) {
            $lanIifs = count($lanDevices) === 1 ? '"' . reset($lanDevices) . '"' : '{ "' . implode('", "', $lanDevices) . '" }';
            $lines[] = '        # Core Network Services (DHCP server, DNS) - ALLOWED ON LAN ONLY';
            $lines[] = '        iifname ' . $lanIifs . ' udp dport { 53, 67 } accept comment "Allow DNS and DHCPv4 from LAN only"';
            $lines[] = '        iifname ' . $lanIifs . ' tcp dport 53 accept comment "Allow DNS TCP from LAN only"';
            $lines[] = '';
            foreach ($this->pluginServicePorts() as $port => $label) {
                $lines[] = '        iifname ' . $lanIifs . ' tcp dport ' . $port . ' accept comment "' . $label . ' (LAN only)"';
            }
            if ($this->pluginServicePorts() !== []) {
                $lines[] = '';
            }
        } else {
            // Fallback when interface roles are not yet differentiated
            $lines[] = '        udp dport { 53, 67 } accept comment "DNS & DHCPv4"';
            $lines[] = '        tcp dport 53 accept comment "DNS TCP"';
            foreach ($this->pluginServicePorts() as $port => $label) {
                $lines[] = '        tcp dport ' . $port . ' accept comment "' . $label . '"';
            }
            $lines[] = '';
        }

        $lines[] = '        # VPN Daemons (WireGuard, strongSwan IPsec IKEv2, OpenVPN)';
        $wgPorts = $this->fetchWireguardPorts();
        if (count($wgPorts) === 1 && $wgPorts[0] === 51820) {
            $lines[] = '        udp dport 51820 accept comment "WireGuard wg0"';
        } elseif (count($wgPorts) === 1) {
            $lines[] = '        udp dport ' . $wgPorts[0] . ' accept comment "WireGuard VPN Daemon"';
        } else {
            $lines[] = '        udp dport { ' . implode(', ', $wgPorts) . ' } accept comment "WireGuard VPN Daemons"';
        }
        $lines[] = '        udp dport { 500, 4500 } accept comment "strongSwan IKEv2 / NAT-T"';
        $lines[] = '        ip protocol esp accept comment "IPsec ESP Encapsulation"';
        $ovpnServices = $this->fetchOpenvpnPorts();
        if (count($ovpnServices) === 1 && $ovpnServices[0]['port'] === 1194 && $ovpnServices[0]['proto'] === 'udp') {
            $lines[] = '        udp dport 1194 accept comment "OpenVPN Server"';
            foreach ($ovpnServices as $ovpn) {
                $lines[] = '        ' . $ovpn['proto'] . ' dport ' . $ovpn['port'] . ' accept comment "OpenVPN Server"';
            }
        }

        if ($ztnaEnabled && !$ztnaControlled) {
            $lines[] = '        # Zero-Trust Clientless Gateway (HTML5 RDP/SSH/VNC & Web Reverse Proxy)';
            $lines[] = '        tcp dport ' . $ztnaPort . ' accept comment "ZTNA Clientless Gateway Reverse Proxy"';
        }

        $lines[] = '';
        $lines[] = '        # Dynamic Routing Protocols (FRRouting OSPF, BGP, RIP, BFD)';
        $lines[] = '        ip protocol ospf accept comment "Routing: OSPFv2 (Protocol 89)"';
        if ($ipv6) {
            $lines[] = '        ip6 nexthdr ospf accept comment "Routing: OSPFv3 (Protocol 89)"';
        }
        $lines[] = '        tcp dport 179 accept comment "Routing: BGP (TCP 179)"';
        $lines[] = '        udp dport 520 accept comment "Routing: RIP (UDP 520)"';
        if ($ipv6) {
            $lines[] = '        udp dport 521 accept comment "Routing: RIPng (UDP 521)"';
        }
        $lines[] = '        udp dport { 3784, 3785, 4784 } accept comment "Routing: BFD"';

        // User-Defined Firewall Policies (Local Appliance Ingress / Input)
        $inputPolicies = [];
        foreach ($fwRules as $rule) {
            $compiled = $this->compileInputFirewallRule($rule, $addresses, $addrGroups, $services, $serviceGroups, $deviceMap, $schedules);
            if (!empty($compiled)) {
                if (is_array($compiled)) {
                    foreach ($compiled as $l) {
                        $inputPolicies[] = '        ' . $l;
                    }
                } else {
                    $inputPolicies[] = '        ' . $compiled;
                }
            }
        }
        if ($inputPolicies !== []) {
            $lines[] = '';
            $lines[] = '        # User-Defined Firewall Policies (Local Appliance / Input)';
            foreach ($inputPolicies as $l) {
                $lines[] = $l;
            }
        }
        $lines[] = '    }';
        $lines[] = '';

        if ($ztnaControlled) {
            foreach ($ztnaChain as $l) {
                $lines[] = $l;
            }
            $lines[] = '';
        }

        // Forward Chain
        $lines[] = '    chain forward {';
        $lines[] = '        type filter hook forward priority filter; policy drop;';
        $lines[] = '';
        $lines[] = '        # Established & Related connections';
        $lines[] = '        ct state established,related accept';
        $lines[] = '        ct state invalid drop';
        if (!empty($dosMitigation['chain'])) {
            $lines[] = '';
            $lines[] = '        # DDoS Mitigation & Rate Limiting';
            $lines[] = '        jump dos_mitigation;';
        }
        if (! $ipv6) {
            $lines[] = '        meta nfproto ipv6 drop comment "IPv6 disabled system-wide"';
        }


        // Plug-and-Play Router: Default allow routed outbound traffic from LAN
        if (!empty($lanDevices)) {
            $lanIifs = count($lanDevices) === 1 ? '"' . reset($lanDevices) . '"' : '{ "' . implode('", "', $lanDevices) . '" }';
            $lines[] = '        # Standard Router Plug-and-Play: Allow all outbound forwarded traffic from LAN';
            $lines[] = '        iifname ' . $lanIifs . ' accept comment "Allow LAN Outbound to Internet"';
            $lines[] = '';
        }
        $lines[] = '';

        // Plugin-owned virtual interfaces. The NAT64/DNS64 translator device
        // and the 6in4 tunnel device only exist while the plugin that creates
        // them is installed and enabled, so the accept is emitted only then.
        // Emitting it unconditionally would punch a permanent hole for a device
        // that may never come up; never emitting it would silently blackhole
        // routed traffic through a plugin the operator did turn on.
        $pluginDevices = $this->pluginForwardDevices();
        if ($pluginDevices !== []) {
            $lines[] = '        # Plugin virtual interfaces (present only while their plugin is enabled)';
            foreach ($pluginDevices as $device => $label) {
                $lines[] = '        iifname "' . $device . '" accept comment "' . $label . ' ingress"';
                $lines[] = '        oifname "' . $device . '" accept comment "' . $label . ' egress"';
            }
            $lines[] = '';
        }
        // Multi-WAN — tag traffic with the gateway it should leave through.
        // Runs before Policy-Based Routing so an explicit PBR match still wins.
        foreach ($multiWan['rules'] as $line) {
            $lines[] = '        ' . $line;
        }

        // Policy-Based Routing — mark matched packets so the routing policy
        // rules below the ruleset can steer them.
        foreach ($this->compilePbrMarks($pbrRules, $deviceMap, $vrfs, $addresses, $addrGroups) as $line) {
            $lines[] = '        ' . $line;
        }

        $lines[] = '        # User-Defined Firewall Rules & Policies';
        foreach ($fwRules as $rule) {
            // "This Firewall" policies govern traffic *to* the appliance (input
            // chain / ztna_gateway); nothing is forwarded to an interface
            // called "local".
            if (in_array(strtolower(trim((string) ($rule['dst_interface'] ?? ''))), ['local', 'self'], true)) {
                continue;
            }
            $compiled = $this->compileFirewallRule($rule, $addresses, $addrGroups, $services, $serviceGroups, $deviceMap, $dnatRules, $schedules);
            if (is_array($compiled)) {
                foreach ($compiled as $line) {
                    $lines[] = '        ' . $line;
                }
            } elseif (!empty($compiled)) {
                $lines[] = '        ' . $compiled;
            }
        }
        $lines[] = '';
        $lines[] = '        # WireGuard & VPN tunnel interfaces (fallback after explicit user policies)';
        $lines[] = '        iifname "wg+" accept comment "WireGuard Tunnel Ingress"';
        $lines[] = '        oifname "wg+" accept comment "WireGuard Tunnel Egress"';
        $lines[] = '        iifname "tun+" accept comment "OpenVPN TUN Ingress"';
        $lines[] = '        oifname "tun+" accept comment "OpenVPN TUN Egress"';
        $lines[] = '        iifname "tap+" accept comment "OpenVPN TAP Ingress"';
        $lines[] = '        oifname "tap+" accept comment "OpenVPN TAP Egress"';
        $lines[] = '        iifname "vti+" accept comment "IPsec VTI Tunnel Ingress"';
        $lines[] = '        oifname "vti+" accept comment "IPsec VTI Tunnel Egress"';
        $lines[] = '        iifname "ipsec+" accept comment "IPsec Tunnel Ingress"';
        $lines[] = '        oifname "ipsec+" accept comment "IPsec Tunnel Egress"';
        $lines[] = '        iifname "xfrm+" accept comment "IPsec XFRM Tunnel Ingress"';
        $lines[] = '        oifname "xfrm+" accept comment "IPsec XFRM Tunnel Egress"';
        $lines[] = '        iifname "ppp+" accept comment "PPP Road Warrior Ingress"';
        $lines[] = '        oifname "ppp+" accept comment "PPP Road Warrior Egress"';
        $lines[] = '        meta secpath exists accept comment "IPsec Policy-Based Decrypted Traffic"';
        $lines[] = '';
        $lines[] = '        # Cleanup Rule: Catch-all rulebase terminator (Zero Trust Deny All by Default)';
        $lines[] = '        counter drop comment "Default Deny All / Cleanup Rule"';
        $lines[] = '    }';
        $lines[] = '';

        // Output Chain
        $lines[] = '    chain output {';
        $lines[] = '        type filter hook output priority filter; policy accept;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        // 2. Define NAT Table
        $lines[] = 'table inet micron_nat {';
        $lines[] = '    chain prerouting {';
        $lines[] = '        type nat hook prerouting priority dstnat; policy accept;';
        $lines[] = '';
        
        // ── Prerouting: Destination NAT ───────────────────────────────
        // DNAT rules only take effect once referenced by an enabled policy
        // (referenced-object semantics), so build that referenced set first.
        // Every enabled Destination NAT rule is applied. A policy still has to
        // accept the translated connection in the forward chain — a policy's
        // dnat_name adds that match (see compileFirewallRule).
        $activeDnat = [];
        foreach ($dnatRules as $dnat) {
            if (!empty($dnat['name']) && (int) ($dnat['enabled'] ?? 1) === 1
                && $this->isScheduleActive($dnat['schedule'] ?? null, $schedules)) {
                $activeDnat[$dnat['name']] = $dnat;
            }
        }
        foreach ($activeDnat as $dnat) {
            $lines[] = '        ' . $this->compileDnatRule($dnat, $deviceMap, $schedules);
        }

        // After the operator's own port forwards, so a forward of WAN tcp/80
        // keeps working (Let's Encrypt validation then fails and says so).
        if ($ztnaAcme && !empty($wanDevices)) {
            $acmeIifs = count($wanDevices) === 1 ? '"' . reset($wanDevices) . '"' : '{ "' . implode('", "', $wanDevices) . '" }';
            $lines[] = '        iifname ' . $acmeIifs . ' tcp dport 80 redirect to :' . ZtnaCompiler::ACME_PORT . ' comment "ZTNA Gateway Let\'s Encrypt HTTP-01"';
        }

        // NPTv6 — stateless IPv6 prefix translation (RFC 6296), inbound half
        foreach ($natNptv6 as $npt) {
            if (empty($npt['internal_prefix']) || empty($npt['external_prefix'])) {
                continue;
            }
            $dev = $this->resolveDevice($npt['interface_id'] ?? '', $deviceMap);
            $iface = ($dev !== '' && $dev !== 'any') ? 'iifname "' . $dev . '" ' : '';
            $cmt = !empty($npt['description']) ? ' comment "NPTv6: ' . addslashes($npt['description']) . '"' : '';
            $lines[] = '        ' . $iface . 'ip6 daddr ' . $npt['external_prefix']
                . ' dnat ip6 prefix to ' . $npt['internal_prefix'] . $cmt;
        }

        // NAT reflection / hairpin: LAN clients hitting the public address are
        // redirected to the internal host on every internal interface.
        $reflection = (int) ($natSettings['nat_reflection'] ?? 1) === 1;
        if ($reflection) {
            foreach ($activeDnat as $dnat) {
                if ((int) ($dnat['nat_reflection'] ?? 1) !== 1) {
                    continue;
                }
                $dnatDev = $this->resolveDevice($dnat['interface_id'] ?? '', $deviceMap);
                foreach ($this->internalDevices($interfaces, $dnatDev) as $dev) {
                    $target = 'dnat to ' . $dnat['mapped_ip'] . (!empty($dnat['mapped_port']) ? ':' . (int) $dnat['mapped_port'] : '');
                    $lines[] = '        iifname "' . $dev . '" ' . $this->dnatDaddr((string) $dnat['external_ip']) . ' ' . $this->dnatPortClause($dnat) . $target . ' comment "NAT reflection: ' . addslashes($dnat['name']) . '"';
                }
            }
        }
        $lines[] = '    }';
        $lines[] = '';

        $lines[] = '    chain postrouting {';
        $lines[] = '        type nat hook postrouting priority srcnat; policy accept;';
        $lines[] = '';

        $outboundMode = strtolower($natSettings['outbound_mode'] ?? 'hybrid');
        // 'hybrid' is the legacy name for automatic outbound NAT — keep it working.
        $autoMasq   = in_array($outboundMode, ['automatic', 'auto', 'hybrid'], true);

        // Bi-directional Destination NAT rules — outbound half, so the host's
        // egress is seen as coming from the published public address.
        foreach ($activeDnat as $dnat) {
            if (empty($dnat['bidirectional']) || empty($dnat['external_ip']) || empty($dnat['mapped_ip'])) {
                continue;
            }
            $ext = (string) $dnat['external_ip'];
            if ($this->isToken($ext) && $this->resolveToken($ext) === null) {
                $dnatDev = $this->resolveDevice($dnat['interface_id'] ?? '', $deviceMap);
                $lines[] = '        ' . ($dnatDev !== '' && $dnatDev !== 'any' ? 'oifname "' . $dnatDev . '" ' : '')
                    . 'ip saddr ' . $dnat['mapped_ip'] . ' masquerade comment "DNAT 1:1 return: ' . addslashes($dnat['name']) . '"';
                continue;
            }
            $lines[] = '        ip saddr ' . $dnat['mapped_ip'] . ' snat to ' . ($this->isToken($ext) ? $this->resolveToken($ext) : $ext) . ' comment "DNAT 1:1 return: ' . addslashes($dnat['name']) . '"';
        }

        // Hairpin return path: traffic to a reflected rule is masqueraded so the
        // internal server replies back through the firewall.
        if ($reflection) {
            foreach ($activeDnat as $dnat) {
                if ((int) ($dnat['nat_reflection'] ?? 1) !== 1) {
                    continue;
                }
                $dnatDev = $this->resolveDevice($dnat['interface_id'] ?? '', $deviceMap);
                foreach ($this->internalDevices($interfaces, $dnatDev) as $dev) {
                    $lines[] = '        oifname "' . $dev . '" ip daddr ' . $dnat['mapped_ip'] . ' masquerade comment "NAT reflection return: ' . addslashes($dnat['name']) . '"';
                }
            }
        }

        // Policy-Based Source NAT (interface masquerade or SNAT pool).
        // Emitted before the catch-all WAN masquerade so specific rules win.
        $snatPoolMap = [];
        foreach ($snatPools as $snatPool) {
            if (!empty($snatPool['name'])) {
                $snatPoolMap[$snatPool['name']] = $snatPool;
            }
        }
        foreach ($fwRules as $rule) {
            if (empty($rule['nat_enabled'])) {
                continue;
            }
            $srcDev = $this->resolveDevice($rule['src_interface'] ?? '', $deviceMap);
            $dstDev = $this->resolveDevice($rule['dst_interface'] ?? '', $deviceMap);

            $matchParts = [];
            if ($dstDev !== '' && $dstDev !== 'any' && $dstDev !== 'all') {
                $matchParts[] = 'oifname "' . $dstDev . '"';
            } elseif ($srcDev !== '' && $srcDev !== 'any' && $srcDev !== 'all') {
                $matchParts[] = 'iifname "' . $srcDev . '"';
            }

            // If neither interface was specified, cannot formulate a targeted NAT rule
            if (empty($matchParts)) {
                continue;
            }

            $prefix = '        ' . implode(' ', $matchParts);
            $name = $rule['name'] ?? ('Rule #' . ($rule['id'] ?? ''));
            $snatPoolName = trim((string) ($rule['snat_pool_name'] ?? ''));
            if ($snatPoolName !== '' && isset($snatPoolMap[$snatPoolName]) && $this->snatPoolRange($snatPoolMap[$snatPoolName]) !== '') {
                $lines[] = $prefix . ' snat to ' . $this->snatPoolRange($snatPoolMap[$snatPoolName])
                    . ' comment "Policy NAT (SNAT pool ' . addslashes($snatPoolName) . '): ' . addslashes($name) . '"';
            } else {
                $lines[] = $prefix . ' masquerade comment "Policy NAT: ' . addslashes($name) . '"';
            }
        }

        // NPTv6 — stateless IPv6 prefix translation, outbound half
        foreach ($natNptv6 as $npt) {
            if (empty($npt['internal_prefix']) || empty($npt['external_prefix'])) {
                continue;
            }
            $lines[] = '        ip6 saddr ' . $npt['internal_prefix']
                . ' snat ip6 prefix to ' . $npt['external_prefix'] . ' comment "NPTv6 outbound"';
        }

        // Automatic WAN Outbound NAT / Masquerade — the catch-all, emitted last
        // so every explicit rule above takes precedence (suppressed in
        // Manual/Disabled outbound modes).
        if ($autoMasq) {
            foreach ($interfaces as $iface) {
                // A WAN role not assigned to a port yet has no device to NAT on.
                $dev = trim((string) ($iface['physical_device'] ?? ''));
                if (str_contains(strtoupper($iface['id'] ?? ''), 'WAN') && $dev !== '') {
                    $lines[] = "        oifname \"{$dev}\" masquerade comment \"WAN Outbound NAT ({$dev})\"";
                }
            }
            foreach ($wanDevices as $wdev) {
                $alreadyAdded = false;
                foreach ($lines as $existing) {
                    if (str_contains($existing, "oifname \"{$wdev}\" masquerade")) {
                        $alreadyAdded = true;
                        break;
                    }
                }
                if (!$alreadyAdded) {
                    $lines[] = "        oifname \"{$wdev}\" masquerade comment \"WAN Outbound NAT ({$wdev})\"";
                }
            }
        }
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        // 3. QoS Traffic Shaper & Classification Rules (DSCP marking with Schedules)
        $qosRules = array_values(array_filter(
            $this->fetchQosRules(),
            fn ($r) => $this->isScheduleActive($r['schedule'] ?? null, $schedules)
        ));
        if (!empty($qosRules)) {
            $lines[] = 'table inet micron_mangle {';
            $lines[] = '    chain qos_classification {';
            $lines[] = '        type filter hook prerouting priority -150; policy accept;';
            $lines[] = '';
            foreach ($qosRules as $qr) {
                $lines[] = '        ' . $this->compileQosRule($qr, $schedules);
            }
            $lines[] = '    }';
            $lines[] = '}';
            $lines[] = '';
        }

        // 4. Suricata IDS/IPS Inline NFQUEUE Diversion
        // The engine's compiled `nfq:` block tells Suricata which Netfilter queue
        // to read, but something must put packets into that queue. This table is
        // the other half — without it "IPS" mode inspects nothing. It runs at
        // priority 10, after the filter table's `priority filter` chains, so the
        // scanner only ever sees traffic the firewall already accepted.
        $ids = $this->fetchIdsSettings();
        if (!empty($ids['enabled']) && ($ids['mode'] ?? '') === 'ips') {
            $queueNum  = max(0, (int) ($ids['nfqueue_num'] ?? 0));
            $failOpen  = ($ids['queue_fail_mode'] ?? 'open') === 'open';
            $queueRule = $failOpen ? "queue num {$queueNum} bypass" : "queue num {$queueNum}";

            $lines[] = '# 4. Suricata Inline IPS — NFQUEUE Diversion (queue ' . $queueNum . ', '
                . ($failOpen ? 'fail-open' : 'fail-closed') . ')';
            $lines[] = 'table inet micron_ids {';
            foreach (['ips_forward' => 'forward', 'ips_input' => 'input'] as $chain => $hook) {
                $lines[] = "    chain {$chain} {";
                $lines[] = "        type filter hook {$hook} priority 10; policy accept;";
                $lines[] = '        ' . $queueRule . ';';
                $lines[] = '    }';
            }
            $lines[] = '}';
            $lines[] = '';
        }

        // NOTE: the Policy-Based Routing half of the feature is kernel routing
        // state (`ip rule` / `ip route`), not nftables syntax. It is NOT part of
        // this ruleset — mixing shell lines into an nftables file would make
        // `nft -f` fail. See compileRoutingPolicy() / renderRoutingPolicy().

        return implode("\n", $lines);
    }

    /**
     * The Policy-Based Routing half of the configuration, as structured data.
     *
     * nftables cannot create routing policy rules or per-table routes, so this
     * is applied by micrond after the nftables ruleset. Returning structured
     * entries (rather than a command string) keeps the daemon free to run each
     * `ip` invocation directly, with no shell involved.
     *
     * @return array{rules:list<array>,routes:list<array>}
     */
    public function compileRoutingPolicy(): array
    {
        $pbrRules = $this->fetchPbrRules();
        $vrfs     = $this->fetchVrfs();

        $rules  = [];
        $routes = [];

        // Multi-WAN load balancing is applied through the same mechanism: each
        // gateway gets its own fwmark, routing table and default route.
        foreach ($this->compileMultiWan()['balancers'] as $balancer) {
            foreach ($balancer['members'] as $member) {
                $gateway = $this->findGateway($member['id']);
                if ($gateway === null) {
                    continue;
                }

                $nexthop = trim((string) ($gateway['gateway_ip'] ?? ''));
                if (! filter_var($nexthop, FILTER_VALIDATE_IP)) {
                    continue;
                }

                $table   = $this->wanTableId($member['mark']);
                $rules[] = [
                    'mark'     => $member['mark'],
                    'table'    => $table,
                    'priority' => 50,
                    'label'    => 'Multi-WAN ' . $balancer['name'],
                ];

                $exists = false;
                foreach ($routes as $route) {
                    if ($route['table'] === $table) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $routes[] = [
                        'table'   => $table,
                        'nexthop' => $nexthop,
                        'label'   => 'Multi-WAN ' . $balancer['name'],
                    ];
                }
            }
        }

        foreach ($pbrRules as $rule) {
            $table = $this->pbrTableId($rule, $vrfs);
            if ($table === 0 || strtolower((string) ($rule['action'] ?? 'permit')) === 'deny') {
                continue;
            }

            $label = $this->pbrLabel($rule);

            $rules[] = [
                'mark'     => $this->pbrMark($rule, $vrfs),
                'table'    => $table,
                'priority' => (int) ($rule['priority'] ?? 100),
                'label'    => $label,
            ];

            $nexthop = trim((string) ($rule['set_nexthop'] ?? ''));
            if ($nexthop !== '' && filter_var($nexthop, FILTER_VALIDATE_IP)) {
                // Only one default route can exist per table, so the first rule
                // that names a next-hop for a table wins.
                $exists = false;
                foreach ($routes as $route) {
                    if ($route['table'] === $table) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $routes[] = [
                        'table'   => $table,
                        'nexthop' => $nexthop,
                        'label'   => $label,
                    ];
                }
            }
        }

        return ['rules' => $rules, 'routes' => $routes];
    }

    /**
     * Renders the routing policy as the exact `ip` commands it becomes, for the
     * admin to review before applying.
     *
     * @return list<string>
     */
    public function renderRoutingPolicy(): array
    {
        $policy = $this->compileRoutingPolicy();
        $lines  = [];

        foreach ($policy['rules'] as $rule) {
            $lines[] = sprintf(
                'ip rule add fwmark %d lookup %d priority %d comment "%s"',
                $rule['mark'],
                $rule['table'],
                $rule['priority'],
                $rule['label'],
            );
        }

        foreach ($policy['routes'] as $route) {
            $lines[] = sprintf(
                'ip route replace default via %s table %d comment "%s"',
                $route['nexthop'],
                $route['table'],
                $route['label'],
            );
        }

        return $lines;
    }

    /**
     * Compiles a firewall policy rule for the input chain (traffic terminating on the router).
     *
     * Applies to rules where dst_interface is 'any' or refers to a local router interface,
     * and destination address matches local router or 'all'/'any'.
     */
    /**
     * The ztna_gateway chain: policies whose Outgoing Interface is This
     * Firewall (`local`) and whose service covers the gateway port, in policy
     * order, then a logged default deny.
     *
     * Destination addresses and the destination interface are not matched
     * here: the jump already selected traffic to the gateway itself. Only the
     * source side (interface, addresses, schedule) and the action carry over.
     *
     * @return list<string>
     */
    protected function compileZtnaGatewayChain(
        array $fwRules,
        int $port,
        array $addresses,
        array $addrGroups,
        array $services,
        array $serviceGroups,
        array $deviceMap,
        array $schedules
    ): array {
        $gatewaySvc = '__ztna_gateway_' . $port;
        $svcWithGateway = array_merge($services, [[
            'name' => $gatewaySvc, 'protocol' => 'TCP', 'tcp_port' => (string) $port, 'udp_port' => '',
        ]]);

        $lines   = [];
        $lines[] = '    chain ztna_gateway {';
        $lines[] = '        # Policies with Outgoing Interface "This Firewall" covering tcp/' . $port . ', in order';
        foreach ($fwRules as $rule) {
            if (! in_array(strtolower(trim((string) ($rule['dst_interface'] ?? ''))), ['local', 'self'], true)) {
                continue;
            }
            if (! $this->servicesCoverTcpPort($rule['services'] ?? null, $services, $serviceGroups, $port)) {
                continue;
            }

            $scoped = $rule;
            $scoped['dst_interface'] = 'any';
            $scoped['destinations']  = '[]';
            $scoped['services']      = json_encode([$gatewaySvc]);

            $compiled = $this->compileInputFirewallRule($scoped, $addresses, $addrGroups, $svcWithGateway, $serviceGroups, $deviceMap, $schedules);
            foreach ((array) $compiled as $l) {
                if (is_string($l) && $l !== '') {
                    $lines[] = '        ' . $l;
                }
            }
        }
        $lines[] = '        log prefix "MICRON-ZTNA-DENY: " level info counter drop comment "ZTNA Gateway: default deny (no policy allowed this source)"';
        $lines[] = '    }';

        return $lines;
    }

    /** Whether a policy's services (or service groups) include TCP $port, or ALL. */
    protected function servicesCoverTcpPort($items, array $services, array $serviceGroups, int $port): bool
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [$items];
        }
        if (! is_array($items) || $items === []) {
            return false;
        }

        $svcMap = array_column($services, null, 'name');
        $grpMap = array_column($serviceGroups, null, 'name');

        $names = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if (isset($grpMap[$item])) {
                $members = $grpMap[$item]['members'] ?? [];
                $members = is_string($members) ? (json_decode($members, true) ?: []) : (array) $members;
                array_push($names, ...array_map('strval', $members));
            } else {
                $names[] = $item;
            }
        }

        foreach ($names as $name) {
            if (strtoupper($name) === 'ALL') {
                return true;
            }
            $svc = $svcMap[$name] ?? null;
            if ($svc === null) {
                continue;
            }
            $proto = strtoupper((string) ($svc['protocol'] ?? 'TCP'));
            if ($proto === 'IP') {
                return true;
            }
            if (! in_array($proto, ['TCP', 'TCP/UDP'], true)) {
                continue;
            }
            foreach (preg_split('/[\s,]+/', (string) ($svc['tcp_port'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $spec) {
                [$lo, $hi] = array_pad(explode('-', $spec, 2), 2, null);
                $lo = (int) $lo;
                $hi = $hi === null ? $lo : (int) $hi;
                if ($port >= $lo && $port <= $hi) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function compileInputFirewallRule(
        array $r,
        array $addresses = [],
        array $addrGroups = [],
        array $services = [],
        array $serviceGroups = [],
        array $deviceMap = [],
        array $schedules = []
    ) {
        $dstIf = strtolower(trim((string) ($r['dst_interface'] ?? 'any')));
        $named = static fn ($v): bool => ! in_array(strtolower(trim((string) $v)), ['', 'any', 'all'], true);

        // If an explicit non-local egress interface is requested (e.g. 'wan'), it belongs in forward only
        if ($named($dstIf)) {
            $resolvedDst = $this->resolveDevice($dstIf, $deviceMap);
            if (!isset($this->ifaceAddr[$dstIf]) && !isset($this->ifaceAddr[$resolvedDst]) && $dstIf !== 'local' && $dstIf !== 'self') {
                return null;
            }
        }

        $parts = [];

        // Source interface match
        $srcIf = $this->resolveDevice($r['src_interface'] ?? 'any', $deviceMap);
        if (!empty($srcIf) && $srcIf !== 'any') {
            $parts[] = "iifname \"{$srcIf}\"";
        }

        // Resolve Source Addresses
        $srcIps = $this->resolveAddressValues($r['sources'] ?? null, $addresses, $addrGroups);
        if (in_array('@unresolved', $srcIps, true)) {
            return null;
        }
        if (!empty($srcIps) && !in_array('0.0.0.0/0', $srcIps, true) && !in_array('all', $srcIps, true)) {
            $cleanIps = array_values(array_filter($srcIps, static fn ($v) => $v !== '@local'));
            if ($cleanIps !== []) {
                $parts[] = count($cleanIps) === 1 ? "ip saddr {$cleanIps[0]}" : "ip saddr { " . implode(', ', $cleanIps) . " }";
            }
        }

        // Resolve Destination Addresses
        $dstIps = $this->resolveAddressValues($r['destinations'] ?? null, $addresses, $addrGroups);
        if (in_array('@unresolved', $dstIps, true)) {
            return null;
        }
        if (!empty($dstIps) && !in_array('0.0.0.0/0', $dstIps, true) && !in_array('all', $dstIps, true)) {
            $local = in_array('@local', $dstIps, true);
            $cleanIps = array_values(array_filter($dstIps, static fn ($v) => $v !== '@local'));
            if ($cleanIps === [] && $local) {
                $parts[] = "fib daddr type local";
            } elseif ($cleanIps !== []) {
                $parts[] = count($cleanIps) === 1 ? "ip daddr {$cleanIps[0]}" : "ip daddr { " . implode(', ', $cleanIps) . " }";
            }
        }

        // Schedule
        $schedClause = trim($this->compileScheduleClause($r['schedule'] ?? null, $schedules));
        if (!empty($schedClause)) {
            $parts[] = $schedClause;
        }

        // Services
        $svcMatches = $this->resolveServiceMatches($r['services'] ?? null, $services, $serviceGroups);

        $action = strtolower($r['action'] ?? 'accept');
        if (in_array($action, ['pass', 'accept', 'allow'])) {
            $actionStr = 'accept';
        } elseif (in_array($action, ['block', 'deny', 'drop'])) {
            $actionStr = 'drop';
        } elseif ($action === 'reject') {
            $actionStr = 'reject';
        } else {
            $actionStr = 'drop';
        }

        $logPart = '';
        if (!empty($r['log_traffic']) && $r['log_traffic'] !== 'none') {
            $logPart = 'log prefix "MICRON-FW: " level info ';
        }

        $commentName = !empty($r['name']) ? $r['name'] : (!empty($r['description']) ? $r['description'] : '');
        $comment = $commentName !== '' ? ' comment "' . addslashes($commentName) . '"' : '';

        // If there are no interface or address or service constraints, don't emit an unconditional catch-all in input
        if (empty($parts) && empty($svcMatches)) {
            return null;
        }

        if (!empty($svcMatches)) {
            $ruleLines = [];
            foreach ($svcMatches as $svcMatch) {
                $ruleParts = $parts;
                if (!empty($svcMatch)) {
                    $ruleParts[] = $svcMatch;
                }
                $ruleLines[] = implode(' ', array_filter($ruleParts)) . ' ' . $logPart . $actionStr . $comment;
            }
            return $ruleLines;
        }

        return implode(' ', array_filter($parts)) . ' ' . $logPart . $actionStr . $comment;
    }

    /**
     * Compiles a single firewall rule or policy into nftables rule(s)
     */
    protected function compileFirewallRule(
        array $r,
        array $addresses = [],
        array $addrGroups = [],
        array $services = [],
        array $serviceGroups = [],
        array $deviceMap = [],
        array $dnatRules = [],
        array $schedules = []
    ) {
        $parts = [];

        // A structured policy carries interface / object references; a legacy
        // flat rule only carries addresses and ports.
        //
        // `src_interface` / `dst_interface` default to 'any' in the schema, so
        // 'any' alone does not make a row a policy: treating it as one dropped
        // the legacy source/destination/port matchers and turned a flat rule
        // (e.g. the factory "LAN → Web GUI on 443") into an unconditional
        // accept.
        $named = static fn ($v): bool => ! in_array(strtolower(trim((string) $v)), ['', 'any', 'all'], true);
        $isPolicy = !empty($r['sources']) || !empty($r['destinations'])
            || $named($r['src_interface'] ?? '') || $named($r['dst_interface'] ?? '');

        if ($isPolicy) {
            // Source & Destination Interfaces (resolved to kernel devices)
            $srcIf = $this->resolveDevice($r['src_interface'] ?? 'any', $deviceMap);
            $dstIf = $this->resolveDevice($r['dst_interface'] ?? 'any', $deviceMap);

            if (!empty($srcIf) && $srcIf !== 'any') {
                $parts[] = "iifname \"{$srcIf}\"";
            }
            if (!empty($dstIf) && $dstIf !== 'any') {
                $parts[] = "oifname \"{$dstIf}\"";
            }

            // Resolve Source Addresses
            $srcIps = $this->resolveAddressValues($r['sources'] ?? null, $addresses, $addrGroups);
            $dstIps = $this->resolveAddressValues($r['destinations'] ?? null, $addresses, $addrGroups);
            foreach (['saddr' => $srcIps, 'daddr' => $dstIps] as $side => $ips) {
                if (in_array('@unresolved', $ips, true)) {
                    return '# skipped: ' . addslashes((string) ($r['name'] ?? $r['id'] ?? 'policy'))
                        . ' — an interface subnet it references has no static address yet';
                }
                if (empty($ips) || in_array('0.0.0.0/0', $ips, true) || in_array('all', $ips, true)) {
                    continue;
                }
                $local = in_array('@local', $ips, true);
                $ips   = array_values(array_filter($ips, static fn ($v) => $v !== '@local'));
                if ($ips === [] && $local) {
                    $parts[] = "fib {$side} type local";
                    continue;
                }
                $parts[] = count($ips) === 1 ? "ip {$side} {$ips[0]}" : "ip {$side} { " . implode(', ', $ips) . " }";
            }

            // A Destination NAT policy must match the *mapped* (post-DNAT)
            // address, because prerouting has already rewritten the destination
            // by the time the forward chain sees the packet.
            $dnatName = trim((string) ($r['dnat_name'] ?? ''));
            if ($dnatName !== '') {
                foreach ($dnatRules as $dnat) {
                    if (($dnat['name'] ?? '') === $dnatName && !empty($dnat['mapped_ip'])) {
                        $parts[] = 'ip daddr ' . $dnat['mapped_ip'];
                        break;
                    }
                }
            }

            // Schedule matching (Recurring / One-Time)
            $schedClause = trim($this->compileScheduleClause($r['schedule'] ?? null, $schedules));
            if (!empty($schedClause)) {
                $parts[] = $schedClause;
            }

            // Resolve Services
            $svcMatches = $this->resolveServiceMatches($r['services'] ?? null, $services, $serviceGroups);

            $action = strtolower($r['action'] ?? 'accept');
            if (in_array($action, ['pass', 'accept', 'allow'])) {
                $actionStr = 'accept';
            } elseif (in_array($action, ['block', 'deny', 'drop'])) {
                $actionStr = 'drop';
            } elseif ($action === 'reject') {
                $actionStr = 'reject';
            } else {
                $actionStr = 'drop';
            }

            $logPart = '';
            if (!empty($r['log_traffic']) && $r['log_traffic'] !== 'none') {
                $logPart = 'log prefix "MICRON-FW: " level info ';
            }

            $comment = !empty($r['name']) ? ' comment "' . addslashes($r['name']) . '"' : (!empty($r['description']) ? ' comment "' . addslashes($r['description']) . '"' : '');

            // If we have distinct protocol port groups (e.g. TCP ports and UDP ports), generate individual lines
            if (!empty($svcMatches)) {
                $ruleLines = [];
                foreach ($svcMatches as $svcMatch) {
                    $ruleParts = $parts;
                    if (!empty($svcMatch)) {
                        $ruleParts[] = $svcMatch;
                    }
                    $ruleLines[] = implode(' ', array_filter($ruleParts)) . ' ' . $logPart . $actionStr . $comment;
                }
                return $ruleLines;
            }

            return implode(' ', array_filter($parts)) . ' ' . $logPart . $actionStr . $comment;
        }

        // Legacy firewall rule compilation fallback
        $iface = $this->resolveDevice($r['interface_id'] ?? ($r['interface'] ?? ''), $deviceMap);
        if (!empty($iface) && $iface !== 'any') {
            $parts[] = "iifname \"{$iface}\"";
        }

        $proto = strtolower($r['protocol'] ?? 'any');
        if (!empty($proto) && $proto !== 'any') {
            $parts[] = "ip protocol {$proto}";
        }

        $saddr = (string) ($r['source_address'] ?? ($r['source_value'] ?? ($r['source_ip'] ?? '')));
        $daddr = (string) ($r['destination_address'] ?? ($r['dest_value'] ?? ($r['dest_ip'] ?? '')));
        foreach (['saddr' => $saddr, 'daddr' => $daddr] as $side => $value) {
            $match = $this->addressMatch($side, $value);
            if ($match === null) {
                return '# skipped: ' . addslashes((string) ($r['description'] ?? $r['id'] ?? 'rule'))
                    . ' — ' . $value . ' has no static address yet';
            }
            if ($match !== '') {
                $parts[] = $match;
            }
        }

        $dport = $r['dest_port'] ?? '';
        if (!empty($dport) && $dport !== 'any') {
            $parts[] = "th dport {$dport}";
        }

        $action = strtolower($r['action'] ?? 'accept');
        if (in_array($action, ['pass', 'accept', 'allow'])) {
            $actionStr = 'accept';
        } elseif (in_array($action, ['block', 'deny', 'drop'])) {
            $actionStr = 'drop';
        } else {
            $actionStr = 'drop';
        }

        if (!empty($r['log_enabled']) || !empty($r['log'])) {
            $parts[] = 'log prefix "MICRON-FW: " level info';
        }

        $parts[] = $actionStr;

        $descr = !empty($r['description']) ? ' comment "' . addslashes($r['description']) . '"' : '';
        return implode(' ', $parts) . $descr;
    }

    /**
     * Resolves an array or JSON string of address/group names into IP CIDRs or ranges
     */
    protected function resolveAddressValues($items, array $addresses, array $addrGroups): array
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [$items];
        }
        if (!is_array($items) || empty($items)) {
            return [];
        }

        // Map names to address rows
        $addrMap = [];
        foreach ($addresses as $a) {
            $addrMap[$a['name']] = $a;
        }

        // Map names to group rows
        $grpMap = [];
        foreach ($addrGroups as $g) {
            $grpMap[$g['name']] = $g;
        }

        $resolved = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === 'all' || $item === 'any') {
                return ['all'];
            }
            if ($this->isToken($item)) {
                $resolved[] = $this->resolveToken($item)
                    ?? (str_ends_with($item, ':address}') ? '@local' : '@unresolved');
                continue;
            }

            // Check if it's an address group
            if (isset($grpMap[$item])) {
                $members = is_string($grpMap[$item]['members']) ? json_decode($grpMap[$item]['members'], true) : $grpMap[$item]['members'];
                if (is_array($members)) {
                    $subIps = $this->resolveAddressValues($members, $addresses, $addrGroups);
                    foreach ($subIps as $sip) {
                        $resolved[] = $sip;
                    }
                }
                continue;
            }

            // Check if it's an address object
            if (isset($addrMap[$item])) {
                $type = $addrMap[$item]['type'] ?? 'ipmask';
                $val = trim($addrMap[$item]['value'] ?? '');
                if ($type === 'geoip' && !empty($val)) {
                    // Geography: reference the country's named set instead of
                    // expanding thousands of prefixes inline.
                    foreach (GeoIp::codes($val) as $cc) {
                        $resolved[] = '@' . GeoIp::setName($cc);
                    }
                } elseif ($type === 'fqdn' && !empty($val)) {
                    // Domains (exact or wildcard) become named sets that the
                    // resolver keeps current, so the rule never matches a
                    // stale single address.
                    foreach (Fqdn::split($val) as $domain) {
                        $resolved[] = '@' . Fqdn::setName($domain);
                    }
                } elseif ($type === 'internet-service' && !empty($val)) {
                    // A named service catalogue entry → its named set.
                    foreach (InternetService::keys($val) as $key) {
                        $resolved[] = '@' . InternetService::setName($key);
                    }
                } elseif (!empty($val) && $this->isToken($val)) {
                    $resolved[] = $this->resolveToken($val)
                        ?? (str_ends_with($val, ':address}') ? '@local' : '@unresolved');
                } elseif (!empty($val)) {
                    $resolved[] = $val;
                }
                continue;
            }

            // Literal IP or CIDR passed directly
            if (filter_var(explode('/', $item)[0], FILTER_VALIDATE_IP)) {
                $resolved[] = $item;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Resolves an array or JSON string of service/group names into nftables protocol/port clauses
     */
    protected function resolveServiceMatches($items, array $services, array $serviceGroups): array
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [$items];
        }
        if (!is_array($items) || empty($items)) {
            return [];
        }

        $svcMap = [];
        foreach ($services as $s) {
            $svcMap[$s['name']] = $s;
        }

        $grpMap = [];
        foreach ($serviceGroups as $g) {
            $grpMap[$g['name']] = $g;
        }

        // Expand service groups into individual service names
        $expandedSvcNames = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if (isset($grpMap[$item])) {
                $members = is_string($grpMap[$item]['members']) ? json_decode($grpMap[$item]['members'], true) : $grpMap[$item]['members'];
                if (is_array($members)) {
                    foreach ($members as $m) {
                        $expandedSvcNames[] = $m;
                    }
                }
            } else {
                $expandedSvcNames[] = $item;
            }
        }

        $tcpPorts = [];
        $udpPorts = [];
        $hasIcmp = false;
        $hasAll = false;

        foreach ($expandedSvcNames as $sName) {
            if (strtoupper($sName) === 'ALL') {
                $hasAll = true;
                break;
            }
            if (!isset($svcMap[$sName])) {
                continue;
            }
            $s = $svcMap[$sName];
            $proto = strtoupper($s['protocol'] ?? 'TCP');

            if ($proto === 'TCP') {
                if (!empty($s['tcp_port'])) {
                    $tcpPorts[] = $s['tcp_port'];
                }
            } elseif ($proto === 'UDP') {
                if (!empty($s['udp_port'])) {
                    $udpPorts[] = $s['udp_port'];
                }
            } elseif ($proto === 'TCP/UDP') {
                if (!empty($s['tcp_port'])) {
                    $tcpPorts[] = $s['tcp_port'];
                }
                if (!empty($s['udp_port'])) {
                    $udpPorts[] = $s['udp_port'];
                }
            } elseif ($proto === 'ICMP') {
                $hasIcmp = true;
            } elseif ($proto === 'IP') {
                $hasAll = true;
            }
        }

        if ($hasAll) {
            return []; // No port restriction means match all IP
        }

        $matches = [];
        if (!empty($tcpPorts)) {
            $tcpPorts = array_values(array_unique($tcpPorts));
            $matches[] = count($tcpPorts) === 1 ? "tcp dport {$tcpPorts[0]}" : "tcp dport { " . implode(', ', $tcpPorts) . " }";
        }
        if (!empty($udpPorts)) {
            $udpPorts = array_values(array_unique($udpPorts));
            $matches[] = count($udpPorts) === 1 ? "udp dport {$udpPorts[0]}" : "udp dport { " . implode(', ', $udpPorts) . " }";
        }
        if ($hasIcmp) {
            $matches[] = "ip protocol icmp";
        }

        return $matches;
    }

    /**
     * Compiles a Destination NAT rule into a prerouting dnat statement.
     */
    protected function compileDnatRule(array $dnat, array $deviceMap, array $schedules = []): string
    {
        $dev = $this->resolveDevice($dnat['interface_id'] ?? '', $deviceMap);
        $iface = ($dev !== '' && $dev !== 'any') ? 'iifname "' . $dev . '" ' : '';
        $schedClause = $this->compileScheduleClause($dnat['schedule'] ?? null, $schedules);
        $target = 'dnat to ' . $dnat['mapped_ip'] . (!empty($dnat['mapped_port']) ? ':' . (int) $dnat['mapped_port'] : '');

        return $iface . $schedClause . $this->dnatDaddr((string) $dnat['external_ip']) . ' ' . $this->dnatPortClause($dnat)
            . $target . ' comment "DNAT: ' . addslashes($dnat['name']) . '"';
    }

    /**
     * Destination match for a DNAT external address. `{wan:address}` follows
     * the interface; on a DHCP/PPPoE interface (no static address) it matches
     * any address of this firewall instead of a stale literal.
     */
    protected function dnatDaddr(string $external): string
    {
        $m = $this->addressMatch('daddr', $external);

        return ($m === null || $m === '') ? 'fib daddr type local' : $m;
    }

    /**
     * Protocol/port match for a Destination NAT rule, e.g. "tcp dport 443 ".
     */
    protected function dnatPortClause(array $dnat): string
    {
        $proto = strtolower((string) ($dnat['protocol'] ?? 'tcp'));
        if ($proto === '' || $proto === 'any' || empty($dnat['external_port'])) {
            return '';
        }
        $pp = $proto === 'tcp/udp' ? 'th' : $proto;
        return $pp . ' dport ' . (int) $dnat['external_port'] . ' ';
    }

    /**
     * Every enabled interface device except WAN devices and the given device.
     *
     * @return list<string>
     */
    protected function internalDevices(array $interfaces, string $excludeDev): array
    {
        $devs = [];
        foreach ($interfaces as $iface) {
            $dev = trim((string) ($iface['physical_device'] ?? '')); // unassigned roles have none
            if ($dev === '' || $dev === $excludeDev) {
                continue;
            }
            if (str_contains(strtoupper($iface['id'] ?? ''), 'WAN')) {
                continue;
            }
            $devs[$dev] = $dev;
        }
        return array_values($devs);
    }

    /**
     * Static IPv4 addressing per interface, for dynamic tokens.
     *
     * @return array<string, array{network: ?string, address: ?string}>
     */
    protected function interfaceAddresses(array $interfaces): array
    {
        $map = [];
        foreach ($interfaces as $iface) {
            $entry = ['network' => null, 'address' => null];
            if (($iface['ip_assignment_type'] ?? '') === 'static' && ! empty($iface['ipv4_address'])) {
                $c = \App\Libraries\Network\NetworkPlan::cidr($iface['ipv4_address'] . '/' . \App\Libraries\Network\NetworkPlan::prefix($iface['ipv4_netmask'] ?? 24));
                if ($c !== null) {
                    $entry = ['network' => $c[0] . '/' . $c[1], 'address' => $c[2]];
                }
            }
            foreach ([$iface['id'] ?? '', $iface['name'] ?? ''] as $key) {
                $key = strtolower(trim((string) $key));
                if ($key !== '') {
                    $map[$key] = $entry;
                }
            }
        }

        return $map;
    }

    /** True when $value is a {iface:network|address} token. */
    protected function isToken(string $value): bool
    {
        return preg_match(\App\Libraries\Network\NetworkPlan::TOKEN_RE, trim($value)) === 1;
    }

    /**
     * Resolve a dynamic token to the interface's current subnet or address.
     * Null when the interface has no static IPv4 address (DHCP / PPPoE) —
     * callers then fall back to a match that needs no literal address.
     */
    protected function resolveToken(string $value): ?string
    {
        if (! preg_match(\App\Libraries\Network\NetworkPlan::TOKEN_RE, trim($value), $m)) {
            return null;
        }

        return $this->ifaceAddr[strtolower($m[1])][$m[2]] ?? null;
    }

    /**
     * nft match for one address side (saddr/daddr) of a value that may be a
     * token. Returns null when the rule must be skipped (an unresolvable
     * subnet token), '' for "no match needed".
     */
    protected function addressMatch(string $side, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === 'any' || $value === '0.0.0.0/0') {
            return '';
        }
        if ($this->isToken($value)) {
            $resolved = $this->resolveToken($value);
            if ($resolved !== null) {
                return "ip {$side} {$resolved}";
            }
            // A dynamic address is still "an address of this firewall".
            return str_ends_with($value, ':address}') ? "fib {$side} type local" : null;
        }

        return "ip {$side} {$value}";
    }

    /**
     * Builds a lowercase lookup of interface id/name to kernel device.
     *
     * @return array<string,string>
     */
    protected function deviceMap(array $interfaces): array
    {
        $map = [];
        foreach ($interfaces as $iface) {
            $dev = !empty($iface['physical_device']) ? $iface['physical_device'] : ($iface['name'] ?? '');
            foreach ([$iface['id'] ?? '', $iface['name'] ?? ''] as $key) {
                $key = strtolower(trim((string) $key));
                if ($key !== '') {
                    $map[$key] = $dev;
                }
            }
        }
        return $map;
    }

    /**
     * Resolves a logical interface id/name ("WAN", "wan") to its kernel
     * device ("eth0"). Unknown references pass through unchanged so a literal
     * device name still works.
     */
    protected function resolveDevice($ref, array $deviceMap): string
    {
        $ref = trim((string) $ref);
        $lower = strtolower($ref);
        if ($lower === '' ) {
            return '';
        }
        if ($lower === 'any') {
            return 'any';
        }
        return $deviceMap[$lower] ?? $lower;
    }

    /**
     * Formats an SNAT pool as a single address or a start-end range.
     */
    protected function snatPoolRange(array $snatPool): string
    {
        $start = trim((string) ($snatPool['start_ip'] ?? ''));
        $end   = trim((string) ($snatPool['end_ip'] ?? ''));
        // A pool that is "the interface address" follows it; with no static
        // address the caller falls back to masquerade (empty range).
        if ($this->isToken($start)) {
            $start = (string) $this->resolveToken($start);
            $end   = $this->isToken($end) ? (string) $this->resolveToken($end) : $end;
        }
        return ($end !== '' && $end !== $start) ? $start . '-' . $end : $start;
    }

    /**
     * nftables set declarations for every country referenced by a `geoip`
     * address object. Elements are inlined when a local feed exists
     * (writable/geoip/<cc>.netset); otherwise micrond populates the set from
     * the appliance GeoIP database at apply time.
     *
     * @return list<string>
     */
    protected function geoIpSetDeclarations(array $addresses): array
    {
        $lines = [];
        $seen  = [];

        foreach ($addresses as $address) {
            if (($address['type'] ?? '') !== 'geoip') {
                continue;
            }

            foreach (GeoIp::codes((string) ($address['value'] ?? '')) as $cc) {
                if (isset($seen[$cc])) {
                    continue;
                }
                $seen[$cc] = true;

                $networks = GeoIp::networks($cc);

                $lines[] = '    # Geography: ' . GeoIp::COUNTRIES[$cc] . ' (' . $cc . ')';
                $lines[] = '    set ' . GeoIp::setName($cc) . ' {';
                $lines[] = '        type ipv4_addr';
                $lines[] = '        flags interval';
                if ($networks !== []) {
                    $lines[] = '        elements = { ' . implode(', ', $networks) . ' }';
                } else {
                    $lines[] = '        # populated from the MicronOS GeoIP database by micrond';
                }
                $lines[] = '    }';
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * nftables set declarations for every domain referenced by an `fqdn`
     * address object. Elements are inlined from a local feed or a live
     * resolution when available; otherwise micrond populates the set at apply
     * time (wildcards in particular are resolved by the daemon).
     *
     * @return list<string>
     */
    protected function fqdnSetDeclarations(array $addresses): array
    {
        $lines = [];
        $seen  = [];

        foreach ($addresses as $address) {
            if (($address['type'] ?? '') !== 'fqdn') {
                continue;
            }

            foreach (Fqdn::split((string) ($address['value'] ?? '')) as $domain) {
                $setName = Fqdn::setName($domain);
                if (isset($seen[$setName])) {
                    continue;
                }
                $seen[$setName] = true;

                $networks = Fqdn::networks($domain);

                $lines[] = '    # Domain: ' . $domain . (Fqdn::isWildcard($domain) ? ' (wildcard)' : '');
                $lines[] = '    set ' . $setName . ' {';
                $lines[] = '        type ipv4_addr';
                $lines[] = '        flags interval';
                if ($networks !== []) {
                    $lines[] = '        elements = { ' . implode(', ', $networks) . ' }';
                } else {
                    $lines[] = '        # populated from DNS by micrond';
                }
                $lines[] = '    }';
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * nftables set declarations for every service referenced by an
     * `internet-service` address object. Elements are inlined from the local
     * service feed when present; otherwise micrond populates the set from the
     * service catalogue at apply time.
     *
     * @return list<string>
     */
    protected function internetServiceSetDeclarations(array $addresses): array
    {
        $lines = [];
        $seen  = [];

        foreach ($addresses as $address) {
            if (($address['type'] ?? '') !== 'internet-service') {
                continue;
            }

            foreach (InternetService::keys((string) ($address['value'] ?? '')) as $key) {
                $setName = InternetService::setName($key);
                if (isset($seen[$setName])) {
                    continue;
                }
                $seen[$setName] = true;

                $networks = InternetService::networks($key);
                $label    = InternetService::label($key);

                $lines[] = '    # Internet service: ' . $label . ' (' . $key . ')';
                $lines[] = '    set ' . $setName . ' {';
                $lines[] = '        type ipv4_addr';
                $lines[] = '        flags interval';
                if ($networks !== []) {
                    $lines[] = '        elements = { ' . implode(', ', $networks) . ' }';
                } else {
                    $lines[] = '        # populated from the MicronOS Internet Service Database by micrond';
                }
                $lines[] = '    }';
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * Whether IPv6 is enabled appliance-wide (System > Networking).
     * IPv6 is off unless an administrator switched it on, so a missing row
     * or setting compiles the IPv4-only ruleset.
     */
    protected function ipv6Enabled(): bool
    {
        try {
            $row = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
            return (int) ($row['ipv6_enabled'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function fetchSystemSettings(): array
    {
        try {
            if ($this->db && $this->db->tableExists('system_settings')) {
                $row = $this->db->table('system_settings')->where('id', 1)->get()->getRowArray();
                if (is_array($row)) {
                    return $row;
                }
            }
        } catch (\Throwable) {}

        return [
            'ssh_port'             => 22,
            'webgui_port'          => 443,
            'webgui_protocol'      => 'https',
            'webgui_redirect_http' => 1,
            'ssh_enabled'          => 1,
        ];
    }

    private function interfaceAdminAccess(array $interface): array
    {
        $configured = $interface['admin_access'] ?? null;
        if (is_array($configured)) {
            $access = $configured;
        } elseif (is_string($configured) && trim($configured) !== '') {
            $decoded = json_decode($configured, true);
            $access = is_array($decoded) ? $decoded : [];
        } else {
            $id = strtolower((string) ($interface['id'] ?? ''));
            $name = strtolower((string) ($interface['name'] ?? ''));
            if (str_starts_with($id, 'lan') || str_starts_with($name, 'lan')) {
                $access = ['http', 'https', 'ping', 'ssh'];
            } elseif (str_starts_with($id, 'opt') || str_starts_with($name, 'opt')) {
                $access = ['http', 'https', 'ping'];
            } elseif (str_starts_with($id, 'wan') || str_starts_with($name, 'wan')) {
                $access = ['ping'];
            } else {
                $access = [];
            }
        }

        $allowed = ['http', 'https', 'ping', 'ssh', 'snmp'];
        $access = array_map(static fn ($service): string => strtolower((string) $service), $access);

        return array_values(array_unique(array_intersect($allowed, $access)));
    }

    protected function fetchWireguardPorts(): array
    {
        $ports = [];
        try {
            if ($this->db && $this->db->tableExists('wireguard_tunnels')) {
                $rows = $this->db->table('wireguard_tunnels')->where('enabled', 1)->select('listen_port')->get()->getResultArray();
                foreach ($rows as $r) {
                    $p = (int) ($r['listen_port'] ?? 0);
                    if ($p > 0 && $p <= 65535) {
                        $ports[] = $p;
                    }
                }
            }
        } catch (\Throwable) {}
        if (empty($ports)) {
            $ports = [51820];
        }
        return array_values(array_unique($ports));
    }

    protected function fetchOpenvpnPorts(): array
    {
        $ports = [];
        try {
            if ($this->db && $this->db->tableExists('openvpn_servers')) {
                $rows = $this->db->table('openvpn_servers')->where('enabled', 1)->select('listen_port, protocol')->get()->getResultArray();
                foreach ($rows as $r) {
                    $p = (int) ($r['listen_port'] ?? 0);
                    $proto = strtolower(trim((string) ($r['protocol'] ?? 'udp')));
                    if (!in_array($proto, ['udp', 'tcp'], true)) {
                        $proto = 'udp';
                    }
                    if ($p > 0 && $p <= 65535) {
                        $ports[] = ['port' => $p, 'proto' => $proto];
                    }
                }
            }
        } catch (\Throwable) {}
        if (empty($ports)) {
            $ports = [['port' => 1194, 'proto' => 'udp']];
        }
        return $ports;
    }

    protected function fetchInterfaces(): array
    {
        try {
            return \App\Libraries\VpnInterfaceProvider::getUnifiedInterfaces($this->db);
        } catch (\Throwable $e) {
            return [
                ['id' => 'WAN', 'physical_device' => 'eth0', 'enabled' => 1],
                ['id' => 'LAN', 'physical_device' => 'eth1', 'enabled' => 1]
            ];
        }
    }

    protected function fetchFirewallRules(): array
    {
        try {
            $builder = $this->db->table('firewall_rules')->where('enabled', 1);
            $fields = array_map(fn($f) => $f->name, $this->db->getFieldData('firewall_rules'));
            if (in_array('sequence', $fields, true)) {
                $builder->orderBy('sequence', 'ASC');
            } elseif (in_array('position', $fields, true)) {
                $builder->orderBy('position', 'ASC');
            }
            return $builder->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchAddresses(): array
    {
        try {
            return $this->db->table('firewall_addresses')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchAddressGroups(): array
    {
        try {
            return $this->db->table('firewall_address_groups')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchServices(): array
    {
        try {
            return $this->db->table('firewall_services')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchServiceGroups(): array
    {
        try {
            return $this->db->table('firewall_service_groups')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchDnatRules(): array
    {
        try {
            return $this->db->table('nat_dnat')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchSnatPools(): array
    {
        try {
            return $this->db->table('nat_snat_pools')->where('enabled', 1)->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchNatNptv6(): array
    {
        try {
            return $this->db->table('nat_nptv6')->where('enabled', 1)->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function fetchNatSettings(): array
    {
        try {
            $row = $this->db->table('nat_settings')->where('id', 'global')->get()->getRowArray();
            return $row ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Enabled Policy-Based Routing rules, highest priority first. */
    protected function fetchPbrRules(): array
    {
        try {
            return $this->db->table('routing_pbr_rules')
                ->where('enabled', 1)
                ->orderBy('priority', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Enabled VRFs, used to resolve a PBR rule's `set_vrf` to a table id. */
    protected function fetchVrfs(): array
    {
        try {
            return $this->db->table('routing_vrfs')->where('enabled', 1)->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ==================================================================
    // Multi-WAN — Gateways, Gateway Groups & Sticky Connections
    // ==================================================================
    /** Enabled Multi-WAN gateways, ordered by priority. */
    protected function fetchWanGateways(): array
    {
        try {
            return $this->db->table('wan_gateways')
                ->where('enabled', 1)
                ->orderBy('priority', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Enabled Multi-WAN gateway groups, ordered by name. */
    protected function fetchGatewayGroups(): array
    {
        try {
            return $this->db->table('wan_gateway_groups')
                ->where('enabled', 1)
                ->orderBy('name', 'ASC')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Normalises a group's stored member list.
     *
     * Members are stored as JSON `[{id, tier, weight}]`; older rows may hold a
     * bare list of ids. Entries that do not name a known, enabled gateway are
     * dropped, so a stale membership cannot produce a rule pointing nowhere.
     *
     * @param  array<string,array> $gateways gateway id => row
     * @return list<array{id:string,tier:int,weight:int}>
     */
    protected function gatewayGroupMembers(array $group, array $gateways): array
    {
        $raw = $group['members'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $members = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $entry = ['id' => $entry];
            }
            if (! is_array($entry)) {
                continue;
            }

            $id = (string) ($entry['id'] ?? '');
            if ($id === '' || ! isset($gateways[$id])) {
                continue;
            }

            $members[] = [
                'id'     => $id,
                'tier'   => max(1, (int) ($entry['tier'] ?? 1)),
                'weight' => max(1, (int) ($entry['weight'] ?? 1)),
            ];
        }

        return $members;
    }

    /**
     * The fwmark a gateway is steered by.
     *
     * Sits in its own band (20200 + index) so it can never collide with a
     * Policy-Based Routing mark, which occupies 10001-10252. Both bands are
     * distinct from the kernel's own reserved marks.
     */
    protected function gatewayMark(int $index): int
    {
        return 20000 + $index;
    }

    /**
     * The nftables name of a group's source-tracking map.
     */
    protected function stickyMapName(string $groupId): string
    {
        return 'micron_sticky_' . preg_replace('/[^A-Za-z0-9_]/', '_', $groupId);
    }

    /**
     * Multi-WAN load balancing, as structured data.
     *
     * A group whose strategy is `load-balance` or `round-robin` spreads new
     * connections across its members. Two pieces make that work:
     *
     *  1. `ct mark set meta mark` copies the chosen gateway onto the connection
     *     so every packet of the same flow keeps the same exit — a flow is
     *     never split across two upstreams, which would break TCP and NAT.
     *  2. `meta mark set ct mark` restores that choice for reply packets and
     *     later packets of the flow.
     *
     * With sticky connections enabled the chosen gateway is additionally
     * recorded against the source address in a map that honours `sticky_timeout`,
     * so the host's *next* connection also reuses the same gateway. A timeout of
     * 0 leaves the map with no lifetime, so the entry is dropped as soon as the
     * states that referred to it expire.
     *
     * `failover` groups are deliberately not load balanced: a failover group is
     * driven by gateway health, which micrond owns, so its members are left
     * alone here.
     *
     * @return array{maps:list<string>,rules:list<string>,gateways:array<string,int>,balancers:list<array>}
     */
    public function compileMultiWan(): array
    {
        $gatewayRows = $this->fetchWanGateways();
        $groups      = $this->fetchGatewayGroups();

        $gateways = [];
        foreach ($gatewayRows as $row) {
            $gateways[(string) $row['id']] = $row;
        }

        // A stable mark per gateway, shared by every group that uses it.
        $marks    = [];
        $index    = 1;
        foreach ($gatewayRows as $row) {
            $marks[(string) $row['id']] = $this->gatewayMark($index++);
        }

        $maps      = [];
        $balancers = [];

        // The rule list is built in phases, and the phase order matters:
        //   1. every sticky reuse rule, so a known source is never re-balanced
        //      by a later group's assignment rule;
        //   2. flow affinity, so follow-up packets of a flow keep its gateway;
        //   3. assignment, which only ever sees traffic no rule above claimed.
        $reuse      = [];
        $affinity   = [];
        $assignment = [];

        foreach ($groups as $group) {
            $strategy = strtolower((string) ($group['strategy'] ?? 'failover'));
            if (! in_array($strategy, ['load-balance', 'round-robin'], true)) {
                continue;
            }

            $members = $this->gatewayGroupMembers($group, $gateways);
            if (count($members) < 2) {
                continue;
            }

            // Round-robin spreads one connection per member; load balancing
            // spreads in proportion to each member's weight.
            $weighted = $strategy === 'load-balance';

            $pool   = [];
            $values = [];
            $i      = 0;
            foreach ($members as $member) {
                $mark = $marks[$member['id']];
                $n    = $weighted ? $member['weight'] : 1;
                $pool[]   = ['id' => $member['id'], 'mark' => $mark, 'weight' => $n];
                for ($w = 0; $w < $n; $w++) {
                    $values[$i++] = $mark;
                }
            }

            $mod    = count($values);
            $sticky = (int) ($group['sticky_enabled'] ?? 0) === 1;
            $timeout = max(0, (int) ($group['sticky_timeout'] ?? 0));
            $mapName = $this->stickyMapName((string) $group['id']);
            $label   = (string) ($group['name'] ?? $group['id']);

            if ($sticky) {
                $maps[] = '    map ' . $mapName . ' {';
                $maps[] = '        type ipv4_addr : mark';
                $maps[] = $timeout > 0
                    ? '        flags dynamic, timeout'
                    : '        flags dynamic';
                if ($timeout > 0) {
                    $maps[] = '        timeout ' . $timeout . 's';
                }
                $maps[] = '    }';
                $maps[] = '';

                // Reuse the gateway this source was assigned, if any.
                $reuse[] = 'ip saddr @' . $mapName . ' meta mark 0 meta mark set ip saddr map @' . $mapName
                    . ' comment "Multi-WAN sticky: ' . addslashes($label) . '"';
            }

            // Reply and follow-up packets of a balanced flow: reuse the choice
            // the flow was born with.
            $affinity[] = 'ct state established,related meta mark 0 meta mark set ct mark'
                . ' comment "Multi-WAN flow affinity: ' . addslashes($label) . '"';

            // New flow with no assignment yet: advance the round robin, or
            // draw at random in proportion to the members' weights.
            $selector = $weighted ? 'numgen random mod ' . $mod : 'numgen inc mod ' . $mod;
            $map      = $this->numgenMap($values);

            // The egress interfaces are recorded for the UI and for the routing
            // policy, but deliberately not matched here: at forward-chain time
            // the outbound interface is not yet chosen, and the traffic that
            // needs balancing is what arrives *from* the LAN.
            $deviceMap = $this->deviceMap($this->fetchInterfaces());
            $devices   = [];
            foreach ($members as $member) {
                $dev = $this->resolveDevice((string) ($gateways[$member['id']]['interface'] ?? ''), $deviceMap);
                if ($dev !== '' && $dev !== 'any') {
                    $devices[$dev] = $dev;
                }
            }

            $rule = 'ct state new meta mark 0 meta mark set ' . $selector . ' map ' . $map;

            // Record the connection's choice, and (when sticky) the source's.
            $rule .= ' ct mark set meta mark';
            if ($sticky) {
                $rule .= ' add @' . $mapName . ' { ip saddr : meta mark }';
            }

            $assignment[] = $rule . ' comment "Multi-WAN ' . ($weighted ? 'load balance' : 'round robin')
                . ': ' . addslashes($label) . '"';

            $balancers[] = [
                'group'    => (string) $group['id'],
                'name'     => $label,
                'strategy' => $strategy,
                'sticky'   => $sticky,
                'timeout'  => $timeout,
                'members'  => $pool,
                'devices'  => array_values($devices),
            ];
        }

        return [
            'maps'      => $maps,
            'rules'     => array_merge($reuse, $affinity, $assignment),
            'gateways'  => $marks,
            'balancers' => $balancers,
        ];
    }

    /**
     * The `numgen` lookup that maps a counter value onto a gateway mark.
     *
     * Adjacent equal marks are collapsed into one range so a weighted member
     * reads as `0-1 : 20001` rather than two identical entries.
     *
     * @param  list<int> $values one mark per slice of the distribution
     */
    protected function numgenMap(array $values): string
    {
        $parts = [];
        $i     = 0;
        $n     = count($values);

        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $values[$j + 1] === $values[$i]) {
                $j++;
            }
            $parts[] = ($i === $j ? (string) $i : $i . '-' . $j) . ' : ' . $values[$i];
            $i = $j + 1;
        }

        return '{ ' . implode(', ', $parts) . ' }';
    }

    /** A single enabled gateway row, or null. */
    protected function findGateway(string $id): ?array
    {
        foreach ($this->fetchWanGateways() as $gateway) {
            if ((string) $gateway['id'] === $id) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * The routing table a Multi-WAN gateway mark steers into.
     *
     * One table per gateway, so each upstream owns its own default route and
     * the two bands never overlap (Multi-WAN uses 201-252, Policy-Based Routing
     * keeps 1-200).
     */
    protected function wanTableId(int $mark): int
    {
        return max(201, min(252, 200 + ($mark - 20000)));
    }

    /**
     * The fwmark a rule's traffic is tagged with.
     *
     * Derived from the rule's routing table so the mark and the `ip rule`
     * lookup agree without a separate counter to keep in sync.
     */
    protected function pbrMark(array $rule, array $vrfs): int
    {
        $table = $this->pbrTableId($rule, $vrfs);

        return $table > 0 ? (10000 + $table) : 0;
    }

    /**
     * The routing table a rule steers into.
     *
     * An explicit `set_table` wins; otherwise the named VRF's table id is used;
     * a rule that only sets a next-hop gets a table derived from its priority.
     */
    protected function pbrTableId(array $rule, array $vrfs): int
    {
        $explicit = (int) ($rule['set_table'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $vrfName = trim((string) ($rule['set_vrf'] ?? ''));
        if ($vrfName !== '') {
            foreach ($vrfs as $vrf) {
                if (($vrf['name'] ?? '') === $vrfName) {
                    $tableId = (int) ($vrf['table_id'] ?? 0);
                    if ($tableId > 0) {
                        return $tableId;
                    }
                }
            }
        }

        if (trim((string) ($rule['set_nexthop'] ?? '')) !== '') {
            $priority = (int) ($rule['priority'] ?? 100);

            return max(1, min(252, 100 + ($priority % 100)));
        }

        return 0;
    }

    /**
     * Forward-chain rules that mark PBR traffic.
     *
     * A permit rule tags the packet and lets it continue; a deny rule drops it.
     * A rule with no forwarding target (no table, VRF or next-hop) is skipped,
     * since there is nothing to steer it toward.
     *
     * @return list<string>
     */
    protected function compilePbrMarks(array $pbrRules, array $deviceMap, array $vrfs, array $addresses = [], array $addrGroups = []): array
    {
        $lines = [];

        foreach ($pbrRules as $rule) {
            $table = $this->pbrTableId($rule, $vrfs);
            $deny  = strtolower((string) ($rule['action'] ?? 'permit')) === 'deny';

            if (! $deny && $table === 0) {
                continue;
            }

            $parts = [];

            $iif = $this->resolveDevice($rule['match_iif'] ?? '', $deviceMap);
            if ($iif !== '' && $iif !== 'any') {
                $parts[] = "iifname \"{$iif}\"";
            }

            $srcClauses = $this->pbrMatchClauses('saddr', $rule, 'match_src', 'match_src_objects', $addresses, $addrGroups);
            $dstClauses = $this->pbrMatchClauses('daddr', $rule, 'match_dst', 'match_dst_objects', $addresses, $addrGroups);

            // A rule that matches several sources and/or destinations is emitted
            // once per combination. Each emitted rule carries the same mark, so
            // the set of marked packets is identical to a single "any of these"
            // rule — and each rule keeps exactly one source and one destination
            // expression, which is what nftables accepts.
            $srcOptions = $srcClauses === [] ? [''] : $srcClauses;
            $dstOptions = $dstClauses === [] ? [''] : $dstClauses;

            $tail = [];

            $proto = strtolower(trim((string) ($rule['match_protocol'] ?? '')));
            if (in_array($proto, ['tcp', 'udp', 'icmp', 'icmpv6'], true)) {
                $tail[] = "ip protocol {$proto}";
            }

            $dscp = $this->pbrDscpValue($rule['match_dscp'] ?? '');
            if ($dscp !== null) {
                $tail[] = "ip dscp {$dscp}";
            }

            $comment = ' comment "PBR: ' . addslashes($this->pbrLabel($rule)) . '" ';
            $action  = $deny ? 'drop' : "meta mark set {$this->pbrMark($rule, $vrfs)}";

            foreach ($srcOptions as $srcClause) {
                foreach ($dstOptions as $dstClause) {
                    $ruleParts = array_merge($parts, array_filter([$srcClause, $dstClause]), $tail);
                    $lines[]   = implode(' ', $ruleParts) . ' ' . $comment . $action;
                }
            }
        }

        return $lines;
    }

    /**
     * Builds the PBR source/destination match clauses for one direction.
     *
     * A rule may match literal CIDRs, named objects, or both. Named objects are
     * resolved with the same machinery a firewall policy uses, so an FQDN,
     * internet-service or geography object becomes the named set the compiler
     * already emits (`@fqdn_…`, `@isdb_…`, `@geo_…`) and stays current as that
     * set is refreshed.
     *
     * Each returned entry is a complete clause for a single value, because a
     * named set cannot be combined with other values inside one nftables set
     * literal. An empty list means the rule matches "any".
     *
     * @return list<string>
     */
    protected function pbrMatchClauses(
        string $direction,
        array $rule,
        string $cidrKey,
        string $objectsKey,
        array $addresses,
        array $addrGroups
    ): array {
        $values = [];

        $literal = trim((string) ($rule[$cidrKey] ?? ''));
        if ($literal !== '' && $literal !== 'any' && Cidr::isValid($literal)) {
            $values[] = $literal;
        }

        $objects = $this->decodeObjectList($rule[$objectsKey] ?? null);
        if ($objects !== []) {
            $resolved = $this->resolveAddressValues($objects, $addresses, $addrGroups);

            // An object list naming "all"/"any" means the rule matches every
            // address, which is the same as having no clause at all.
            if (in_array('all', $resolved, true)) {
                return [];
            }

            foreach ($resolved as $entry) {
                $values[] = $entry;
            }
        }

        $values = array_values(array_unique($values));

        return array_map(
            static fn (string $value): string => 'ip ' . $direction . ' ' . $value,
            $values,
        );
    }

    /**
     * Normalises a stored object list (JSON array or comma-separated string).
     *
     * @return list<string>
     */
    protected function decodeObjectList($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = Cidr::split($raw);
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $item = trim((string) $item);
            if ($item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** A human label for a PBR rule, for comments. */
    protected function pbrLabel(array $rule): string
    {
        $desc = trim((string) ($rule['description'] ?? ''));

        return $desc !== '' ? $desc : 'rule #' . ($rule['id'] ?? '');
    }

    /**
     * "ef" / "46" → 46, "cs1" → 8. Returns null when unset or unparseable.
     */
    protected function pbrDscpValue($value): ?int
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $names = [
            'be' => 0, 'cs0' => 0, 'cs1' => 8, 'af11' => 10, 'af12' => 12, 'af13' => 14,
            'cs2' => 16, 'af21' => 18, 'af22' => 20, 'af23' => 22, 'cs3' => 24,
            'af31' => 26, 'af32' => 28, 'af33' => 30, 'cs4' => 32, 'af41' => 34,
            'af42' => 36, 'af43' => 38, 'cs5' => 40, 'ef' => 46, 'cs6' => 48, 'cs7' => 56,
        ];

        if (isset($names[$value])) {
            return $names[$value];
        }

        if (ctype_digit($value) && (int) $value >= 0 && (int) $value <= 63) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Fetch active DoS / DDoS mitigation policies from the database.
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchDosPolicies(): array
    {
        if ($this->db && $this->db->tableExists('dos_policies')) {
            return $this->db->table('dos_policies')->where('enabled', 1)->get()->getResultArray();
        }

        return [];
    }

    /**
     * Virtual devices owned by a plugin, mapped to their accept-rule label.
     * A device is listed only while the plugin that creates it is installed and
     * enabled, so a removed plugin never leaves a standing accept behind.
     *
     * @return array<string, string> device => label
     */
    protected function pluginForwardDevices(): array
    {
        $devices = [];

        if ($this->pluginEnabled('nat64')) {
            $devices[self::NAT64_DEVICE] = 'NAT64/DNS64 translator (plugin)';
        }
        if ($this->pluginEnabled('ipv6-tunnel')) {
            $devices[self::TUNNEL_DEVICE] = 'IPv6 6in4 tunnel (plugin)';
        }

        return $devices;
    }

    /**
     * Input rules an enabled HA cluster needs: VRRP (IP protocol 112) for
     * keepalived, conntrackd's UDP sync port from the state-sync peer, and
     * HTTPS from the config-sync peer for `/ha/sync/receive`. Every rule is
     * bound to the peer's address; nothing is opened while HA is off.
     *
     * @return list<string>
     */
    protected function haClusterRules(): array
    {
        if (! $this->db || ! $this->db->tableExists('ha_settings')) {
            return [];
        }
        try {
            $ha = $this->db->table('ha_settings')->get(1)->getRowArray();
        } catch (\Throwable $e) {
            return [];
        }
        if (! is_array($ha) || empty($ha['enabled'])) {
            return [];
        }

        $family = static fn (string $ip): ?string => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? 'ip' : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'ip6' : null);

        $rules   = ['# High availability cluster (peer-bound)'];
        $rules[] = 'ip protocol vrrp accept comment "HA VRRP advertisements"';

        $statePeer = (string) ($ha['peer_sync_ip'] ?? '');
        $statePort = (int) ($ha['peer_sync_port'] ?? 3780);
        if (! empty($ha['conntrackd_enabled']) && ($f = $family($statePeer)) !== null && $statePort > 0 && $statePort < 65536) {
            $rules[] = "{$f} saddr {$statePeer} udp dport {$statePort} accept comment \"HA conntrackd state sync\"";
        }

        $configPeer = (string) ($ha['config_sync_peer_ip'] ?? '');
        if (! empty($ha['config_sync_enabled']) && ($f = $family($configPeer)) !== null) {
            $rules[] = "{$f} saddr {$configPeer} tcp dport 443 accept comment \"HA config sync from peer\"";
        }
        $rules[] = '';

        return $rules;
    }

    /**
     * Management web ports opened by enabled plugins (darkstat's own web UI,
     * default 666). A plugin records its listening port in `plugin_settings`
     * (`setting_key = web_port`) when its settings are applied; the port is
     * opened on the LAN side only — the WAN drop rules come first — and only
     * while the plugin's row is enabled, so removing the plugin closes it.
     *
     * @return array<int, string> port => rule comment
     */
    protected function pluginServicePorts(): array
    {
        if (! $this->db || ! $this->db->tableExists('plugin_settings') || ! $this->db->tableExists('plugins')) {
            return [];
        }

        try {
            $rows = $this->db->table('plugin_settings')->where('setting_key', 'web_port')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }

        $reserved = [22, 53, 67, 80, 443, 8443];
        $ports    = [];
        foreach ($rows as $row) {
            $id   = (string) ($row['plugin_id'] ?? '');
            $port = (int) ($row['value'] ?? 0);
            if ($id === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
                continue;
            }
            if ($port < 1 || $port > 65535 || in_array($port, $reserved, true) || ! $this->pluginEnabled($id)) {
                continue;
            }
            $ports[$port] = $id . ' web UI (plugin)';
        }
        ksort($ports);

        return $ports;
    }

    /**
     * Whether an installed plugin is enabled. Reads the same `plugins` row the
     * plugin UI writes; an absent table (a compiler run outside the app
     * database) means no plugin is enabled rather than a broken ruleset.
     */
    protected function pluginEnabled(string $id): bool
    {
        if (! $this->db || ! $this->db->tableExists('plugins')) {
            return false;
        }

        return $this->db->table('plugins')
            ->where('id', $id)
            ->where('enabled', 1)
            ->countAllResults() > 0;
    }

    /**
     * Compile Layer 4 DoS / DDoS mitigation rules (SYN flood, rate limits, protocol thresholds).
     *
     * @param list<array<string, mixed>> $dosPolicies
     * @param array<string, string>      $deviceMap
     * @param array<string, array<string, mixed>> $schedules
     * @return array{sets: list<string>, chain: list<string>}
     */
    protected function compileDosPolicies(array $dosPolicies, array $deviceMap, array $schedules = []): array
    {
        if (empty($dosPolicies)) {
            return ['sets' => [], 'chain' => []];
        }

        $sets = [];
        $sets[] = '    # Dynamic blacklist tracking source IPs exceeding DoS thresholds';
        $sets[] = '    set dos_blacklist {';
        $sets[] = '        type ipv4_addr;';
        $sets[] = '        size 65535;';
        $sets[] = '        flags timeout;';
        $sets[] = '    }';
        $sets[] = '';

        $chain = [];
        $chain[] = '    chain dos_mitigation {';
        $chain[] = '        # Drop hosts currently quarantined in the dynamic blacklist';
        $chain[] = '        ip saddr @dos_blacklist counter drop comment "DDoS: Dynamic Quarantined Host Drop"';
        $chain[] = '';

        foreach ($dosPolicies as $p) {
            $name = !empty($p['name']) ? $p['name'] : 'DoS Policy';
            $iface = $p['interface_id'] ?? 'all';
            $ifaceMatch = '';
            if ($iface !== 'all' && isset($deviceMap[$iface])) {
                $ifaceMatch = 'iifname "' . $deviceMap[$iface] . '" ';
            } elseif ($iface !== 'all' && !empty($iface)) {
                $ifaceMatch = 'iifname "' . $iface . '" ';
            }

            // Schedule (recurring meta day/hour or one-time meta time). Folded
            // into the leading match so every rule of the policy inherits it.
            $schedClause = $this->compileScheduleClause($p['schedule'] ?? null, $schedules);
            $ifaceMatch .= $schedClause;

            $chain[] = "        # --- Policy: {$name} ---";

            // Exemptions
            if (!empty($p['exempt_addresses'])) {
                $exempts = array_values(array_filter(array_map('trim', explode(',', (string) $p['exempt_addresses']))));
                if (!empty($exempts)) {
                    $chain[] = "        {$ifaceMatch}ip saddr { " . implode(', ', $exempts) . ' } return comment "DDoS Exemption: ' . addslashes($name) . '"';
                }
            }

            $cleanId = substr(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($p['id'] ?? 'pol')), 0, 16);
            $action = ($p['action'] ?? 'drop') === 'reject' ? 'reject' : 'drop';
            $logPrefix = !empty($p['log_enabled']) ? 'log prefix "[MICRON-DOS-DROP] " ' : '';

            // Port Scan protection
            if (!empty($p['port_scan_enabled'])) {
                $chain[] = "        {$ifaceMatch}tcp flags & (fin|syn) == (fin|syn) {$logPrefix}counter {$action} comment \"DDoS: SYN-FIN Scan Drop\"";
                $chain[] = "        {$ifaceMatch}tcp flags & (syn|rst) == (syn|rst) {$logPrefix}counter {$action} comment \"DDoS: SYN-RST Scan Drop\"";
                $chain[] = "        {$ifaceMatch}tcp flags & (fin|syn|rst|psh|ack|urg) == 0 {$logPrefix}counter {$action} comment \"DDoS: NULL Scan Drop\"";
                $chain[] = "        {$ifaceMatch}tcp flags & (fin|syn|rst|psh|ack|urg) == (fin|syn|rst|psh|ack|urg) {$logPrefix}counter {$action} comment \"DDoS: XMAS Scan Drop\"";
            }

            // SYN flood protection
            if (!empty($p['syn_flood_enabled'])) {
                $rate = max(1, (int) ($p['syn_flood_rate'] ?? 100));
                $burst = max($rate, (int) ($p['syn_flood_burst'] ?? ($rate * 2)));
                $banSec = (int) ($p['ban_duration'] ?? 600);
                $meter = 'dos_syn_' . $cleanId;
                if ($banSec > 0) {
                    $chain[] = "        {$ifaceMatch}tcp flags syn meter {$meter} { ip saddr limit rate over {$rate}/second burst {$burst} packets } update @dos_blacklist { ip saddr timeout {$banSec}s } {$logPrefix}counter {$action} comment \"DDoS: SYN Flood Protection\"";
                } else {
                    $chain[] = "        {$ifaceMatch}tcp flags syn meter {$meter} { ip saddr limit rate over {$rate}/second burst {$burst} packets } {$logPrefix}counter {$action} comment \"DDoS: SYN Flood Drop\"";
                }
            }

            // Concurrent connection limit
            if (!empty($p['conn_limit_enabled'])) {
                $max = max(1, (int) ($p['conn_limit_max'] ?? 50));
                $meter = 'dos_conn_' . $cleanId;
                $chain[] = "        {$ifaceMatch}meter {$meter} { ip saddr ct count over {$max} } {$logPrefix}counter {$action} comment \"DDoS: Host Connection Limit\"";
            }

            // Connection rate limit
            if (!empty($p['conn_rate_enabled'])) {
                $rate = max(1, (int) ($p['conn_rate_max'] ?? 60));
                $meter = 'dos_rate_' . $cleanId;
                $chain[] = "        {$ifaceMatch}ct state new meter {$meter} { ip saddr limit rate over {$rate}/second } {$logPrefix}counter {$action} comment \"DDoS: New Connection Rate Limit\"";
            }

            // UDP flood limit
            if (!empty($p['udp_flood_enabled'])) {
                $rate = max(1, (int) ($p['udp_flood_rate'] ?? 500));
                $meter = 'dos_udp_' . $cleanId;
                $chain[] = "        {$ifaceMatch}ip protocol udp meter {$meter} { ip saddr limit rate over {$rate}/second } {$logPrefix}counter {$action} comment \"DDoS: UDP Flood Rate Limit\"";
            }

            // ICMP flood limit
            if (!empty($p['icmp_flood_enabled'])) {
                $rate = max(1, (int) ($p['icmp_flood_rate'] ?? 20));
                $meter = 'dos_icmp_' . $cleanId;
                $chain[] = "        {$ifaceMatch}ip protocol icmp icmp type echo-request meter {$meter} { ip saddr limit rate over {$rate}/second } {$logPrefix}counter {$action} comment \"DDoS: ICMP Echo Flood Limit\"";
            }

            $chain[] = '';
        }

        $chain[] = '    }';
        $chain[] = '';

        return ['sets' => $sets, 'chain' => $chain];
    }

    /**
     * Fetch all schedule objects keyed by lowercase name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchSchedules(): array
    {
        try {
            $db = $this->db ?? \Config\Database::connect();
            if (! $db->tableExists('firewall_schedules')) {
                return [];
            }
            $rows = $db->table('firewall_schedules')->get()->getResultArray();
            $schedules = [];
            foreach ($rows as $row) {
                $schedules[strtolower($row['name'])] = $row;
            }
            return $schedules;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Whether rules bound to this schedule should be compiled at all.
     *
     * A disabled schedule pauses every rule that references it. `always`,
     * empty values and unknown names are treated as active so a missing
     * schedule row can never silently remove a policy.
     */
    public function isScheduleActive(?string $scheduleName, array $schedules): bool
    {
        $key = strtolower(trim((string) $scheduleName));
        if ($key === '' || $key === 'always' || !isset($schedules[$key])) {
            return true;
        }

        return (int) ($schedules[$key]['enabled'] ?? 1) === 1;
    }

    /**
     * Compiles schedule time/day matching clauses for nftables.
     *
     * Supported formats:
     *  - Recurring:
     *      meta day "Monday" - "Friday"
     *      meta day { "Saturday", "Sunday" }
     *      meta hour "08:00:00" - "18:00:00"
     *  - One-time:
     *      meta time "2026-10-05 08:00:00" - "2026-10-06 18:00:00"
     */
    public function compileScheduleClause(?string $scheduleName, array $schedules): string
    {
        if (empty($scheduleName)) {
            return '';
        }
        $key = strtolower(trim($scheduleName));
        if ($key === 'always' || !isset($schedules[$key])) {
            return '';
        }

        $s = $schedules[$key];
        $type = $s['type'] ?? 'recurring';

        if ($type === 'onetime') {
            if (!empty($s['start_datetime']) && !empty($s['end_datetime'])) {
                $start = date('Y-m-d H:i:s', strtotime($s['start_datetime']));
                $end   = date('Y-m-d H:i:s', strtotime($s['end_datetime']));
                return 'meta time "' . $start . '" - "' . $end . '" ';
            }
            return '';
        }

        // Recurring
        $clauses = [];
        $days = strtolower($s['days_of_week'] ?? 'all');
        if ($days !== 'all' && !empty($days)) {
            $dayMap = [
                'mon' => 'Monday',
                'tue' => 'Tuesday',
                'wed' => 'Wednesday',
                'thu' => 'Thursday',
                'fri' => 'Friday',
                'sat' => 'Saturday',
                'sun' => 'Sunday',
            ];
            $selectedDays = [];
            foreach (explode(',', $days) as $d) {
                $d = trim($d);
                if (isset($dayMap[$d])) {
                    $selectedDays[] = $dayMap[$d];
                }
            }

            if (!empty($selectedDays)) {
                if ($days === 'mon,tue,wed,thu,fri') {
                    $clauses[] = 'meta day "Monday" - "Friday"';
                } elseif ($days === 'sat,sun') {
                    $clauses[] = 'meta day { "Saturday", "Sunday" }';
                } elseif (count($selectedDays) === 1) {
                    $clauses[] = 'meta day "' . $selectedDays[0] . '"';
                } else {
                    $quoted = array_map(fn($d) => '"' . $d . '"', $selectedDays);
                    $clauses[] = 'meta day { ' . implode(', ', $quoted) . ' }';
                }
            }
        }

        $startTime = substr($s['start_time'] ?? '00:00:00', 0, 8);
        $endTime   = substr($s['end_time'] ?? '23:59:59', 0, 8);
        if ($startTime !== '00:00:00' || ($endTime !== '23:59:59' && $endTime !== '23:59:00')) {
            $clauses[] = 'meta hour "' . $startTime . '" - "' . $endTime . '"';
        }

        if (empty($clauses)) {
            return '';
        }

        return implode(' ', $clauses) . ' ';
    }

    /**
     * Fetch enabled QoS traffic classification rules.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Zero Trust Network Access / Clientless Gateway settings.
     *
     * @return array<string, mixed>
     */
    protected function fetchZtnaSettings(): array
    {
        try {
            $db = $this->db ?? \Config\Database::connect();
            if (!$db->tableExists('ztna_settings')) {
                return [];
            }
            return $db->table('ztna_settings')->where('id', 1)->get()->getRowArray() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Suricata IDS/IPS engine settings, used to decide whether the inline
     * NFQUEUE diversion table is emitted and how it behaves under failure.
     *
     * @return array<string, mixed>
     */
    protected function fetchIdsSettings(): array
    {
        try {
            return $this->db->table('ids_settings')->get()->getRowArray() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function fetchQosRules(): array
    {
        try {
            $db = $this->db ?? \Config\Database::connect();
            if (! $db->tableExists('qos_rules')) {
                return [];
            }
            return $db->table('qos_rules')->where('enabled', 1)->orderBy('priority', 'ASC')->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Compile a single QoS DSCP traffic classification rule.
     */
    public function compileQosRule(array $r, array $schedules): string
    {
        $parts = [];
        $proto = strtolower((string) ($r['protocol'] ?? 'any'));
        if ($proto !== 'any' && $proto !== '') {
            $parts[] = "ip protocol {$proto}";
        }

        if (!empty($r['src_ip']) && $r['src_ip'] !== 'any') {
            $parts[] = "ip saddr {$r['src_ip']}";
        }
        if (!empty($r['dst_ip']) && $r['dst_ip'] !== 'any') {
            $parts[] = "ip daddr {$r['dst_ip']}";
        }

        if (!empty($r['dst_port']) && $r['dst_port'] !== 'any') {
            $ports = array_values(array_filter(array_map('trim', explode(',', $r['dst_port']))));
            if (count($ports) === 1) {
                $parts[] = "th dport {$ports[0]}";
            } elseif (!empty($ports)) {
                $parts[] = 'th dport { ' . implode(', ', $ports) . ' }';
            }
        }

        // Schedule clause
        $schedClause = trim($this->compileScheduleClause($r['schedule'] ?? null, $schedules));
        if (!empty($schedClause)) {
            $parts[] = $schedClause;
        }

        $dscp = strtolower((string) ($r['dscp_class'] ?? 'cs0'));
        $parts[] = "ip dscp set {$dscp}";

        $comment = !empty($r['name']) ? ' comment "QoS: ' . addslashes($r['name']) . '"' : '';
        return implode(' ', $parts) . $comment;
    }
}



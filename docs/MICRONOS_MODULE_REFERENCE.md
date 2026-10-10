# Micron Firewall OS (MicronOS)
## Module Reference & Developer Guide
### Complete Subsystem Reference: All Implemented Controllers, Libraries, Migrations & Data-Plane Compilers

---

## Table of Contents
1. [Overview & Implementation Status](#1-overview--implementation-status)
2. [Management Plane Controllers Reference](#2-management-plane-controllers-reference)
3. [Compiler Libraries Reference](#3-compiler-libraries-reference)
4. [Utility Libraries Reference](#4-utility-libraries-reference)
5. [Database Migration Inventory](#5-database-migration-inventory)
6. [IPC / RPC Command Reference](#6-ipc--rpc-command-reference)
7. [CLI Commands (spark)](#7-cli-commands-spark)
8. [Test Suite Overview](#8-test-suite-overview)

---

## 1. Overview & Implementation Status

MicronOS is a **CodeIgniter 4 (PHP 8.3+)** management plane fronting a Linux kernel data plane. As of **October 2026**, the following subsystems are fully implemented:

| Subsystem | Controller | Compiler Library | Status |
| :--- | :--- | :--- | :--- |
| Dashboard | `Dashboard.php` | — | Implemented |
| Firewall Policies & Objects | `Firewall.php` | `NftablesCompiler.php` | Implemented |
| NAT (DNAT / SNAT / 1:1) | `Nat.php` | `NftablesCompiler.php` | Implemented |
| Dynamic Routing (BGP/OSPF/RIP/IS-IS/BFD/PBR/VRF) | `Routing.php` | FRR vtysh via IPC | Implemented |
| Network Interfaces | `Interfaces.php` | `iproute2` via IPC | Implemented |
| DHCP Server | `Dhcp.php` | `KeaDhcpCompiler.php` | Implemented |
| DNS Resolver & Sinkhole | `Dns.php` | `UnboundDnsCompiler.php` | Implemented |
| VPN: WireGuard | `VpnWireguard.php` | `WireguardCompiler.php` | Implemented |
| VPN: OpenVPN 2FA | `VpnOpenvpn.php` | `OpenvpnCompiler.php` | Implemented |
| VPN: IPsec (IKEv2) | `VpnIpsec.php` | `StrongswanCompiler.php` | Implemented |
| VPN User Portal | `VpnUserPortal.php` | — | Implemented |
| Web Filter & Proxy | `WebFilter.php` | `SquidConfigCompiler.php` | Implemented |
| Antivirus Inspection (C-ICAP / ClamAV) | `WebFilter.php` (Antivirus tab) | `SquidConfigCompiler.php` + `WebfilterAvDetectionModel` | Implemented |
| PPPoE (Client + BRAS) | `Pppoe.php` | `PppoeCompiler.php` | Implemented |
| IDS/IPS (Suricata) | `Ids.php` | `SuricataCompiler.php` | Implemented |
| DoS/DDoS Policies | `DosPolicy.php` | `NftablesCompiler.php` | Implemented |
| QoS Traffic Shaper | `Qos.php` | `tc`/CAKE via IPC | Implemented |
| Schedule Objects | `Schedule.php` | — | Implemented |
| High Availability | `HighAvailability.php` | `keepalived.conf` via IPC | Implemented |
| SNMP Agent | `Snmp.php` | `snmpd.conf` via IPC | Implemented |
| SIEM Integration | `Siem.php` | syslog/CEF via IPC | Implemented |
| Internet Services DB | `InternetServices.php` | `AddressResolver.php` | Implemented |
| Cron Scheduler | `Cron.php` | `CronJobLog.php` | Implemented |
| System Services | `Services.php` | `systemd` via IPC | Implemented |
| Per-Page Daemon Controls | `ServiceStatus.php` (page headers) | `Services::control` via IPC | Implemented |
| Certificates & PKI | `Certificates.php` | `X509.php` | Implemented |
| Backup & Restore | `Backup.php` | `BackupEngine.php` | Implemented |
| Diagnostics | `Diagnostics.php` | network tools via IPC | Implemented |
| System Settings | `SystemSettings.php` | — | Implemented |
| User Management | `Users.php` | — | Implemented |
| Roles & Permissions | `Roles.php` | — | Implemented |
| Auth (Local/LDAP/RADIUS/2FA) | `Auth.php` | LdapAuthService/RadiusAuthService/TotpService | Implemented |
| System Commit | `SystemCommit.php` | — | Implemented |
| Docs Portal | `Docs.php` | — | Implemented |

---

## 2. Management Plane Controllers Reference

### 2.1 Dashboard

**File:** `app/Controllers/Dashboard.php` | **Route:** `/dashboard` | **Library:** `CommandDispatcher`

Landing page aggregating real-time metrics in a multi-widget layout via `CommandDispatcher` (mock or live).

**Widgets:** CPU gauges (1m/5m/15m load), RAM & Swap, Disk, Conntrack state table, Network interfaces (RX/TX/errors/link), Multi-WAN gateway matrix (RTT/jitter/loss), Firewall log stream (last 50 entries), Daemon status panel (nftables, frr, wg, openvpn, squid, suricata, keepalived, snmpd, unbound, kea-dhcp4), WireGuard peer summary, HA VRRP state, DNSBL/proxy telemetry.

**AJAX endpoints:**
```
POST /dashboard/daemonAction  — Start/stop/restart a system daemon
GET  /dashboard/liveTraffic   — JSON stream for live bandwidth chart
GET  /dashboard/gatewayStatus — Multi-WAN RTT/loss matrix
```

---

### 2.2 Firewall Policies & Objects

**File:** `app/Controllers/Firewall.php` (1,704 lines) | **Route:** `/firewall`

**Libraries:** `NftablesCompiler`, `IpcClient`, `Fqdn`, `GeoIp`, `InternetService`

#### Object Types

| Object | DB Table | Description |
| :--- | :--- | :--- |
| Addresses | `firewall_addresses` | Single IP, CIDR, range, FQDN wildcard, GeoIP country |
| Address Groups | `firewall_address_groups` | Named collections for policy reuse |
| Services / Ports | `firewall_services` | TCP/UDP/ICMP definitions with well-known presets |
| Service Groups | `firewall_service_groups` | Named collections of services |
| Internet Services | `internet_services` | Cloud/SaaS IP sets (AWS, Google, Cloudflare, etc.) |

#### Object Hover Tooltips

`App\Libraries\ObjectTip` renders a `data-fw-tip` attribute holding a small server-side HTML card (title + label/value rows) for any address, service, group, interface, schedule or internet-service name. The shared JS in `app/Views/firewall/_obj_tooltip.php` (floating `#fw-tip-box`) shows it on hover or keyboard focus, with zero extra requests, and it works identically inside modals. It also mirrors option detail onto `<select>` elements via `data-fw-select`.

#### Policy Model

Rules stored in `firewall_rules`, grouped by interface pair (`src_interface -> dst_interface`):
- `?view=pair` (default) — FortiGate-style policy zone grouping
- `?view=seq` — Flat sequential list

**Policy fields:** id, name, sequence, enabled, action (accept/drop/reject/log), src_interface, dst_interface, src_address, dst_address, address groups, service/service_group, nat_dnat_id, nat_snat_pool_id, schedule, log_enabled, description

#### Key API Endpoints

| Method | Route | Purpose |
| :--- | :--- | :--- |
| GET | `/firewall` | Policy list with all objects |
| POST | `/firewall/saveRule` | Create / update rule |
| POST | `/firewall/deleteRule` | Remove a rule |
| POST | `/firewall/reorderRules` | AJAX drag-drop sequence reorder |
| POST | `/firewall/toggleRule` | Enable/disable without deleting |
| POST | `/firewall/applyRules` | Compile nftables + push via IPC |
| GET | `/firewall/exportRulesCsv` | Download all rules as CSV |
| POST | `/firewall/importRulesCsv` | Bulk import rules from CSV |
| POST | `/firewall/saveAddress` | Create / update address object |
| POST | `/firewall/saveAddressGroup` | Create / update address group |
| POST | `/firewall/saveService` | Create / update service object |
| POST | `/firewall/saveServiceGroup` | Create / update service group |

#### Compilation Flow

```
Firewall::applyRules()
  -> NftablesCompiler::build()
     - named sets for addresses/services/internet services
     - NAT prerouting/postrouting chains
     - filter forward chains per interface pair
  -> IpcClient::applyFirewall()  -> /run/micron/micrond.sock
```

---

### 2.3 NAT Engine

**File:** `app/Controllers/Nat.php` | **Route:** `/nat`

| NAT Type | DB Table | Description |
| :--- | :--- | :--- |
| DNAT (Port Forward) | `nat_dnat` | Redirect inbound WAN traffic to internal server |
| SNAT Pools | `nat_snat_pools` | Outbound masquerade / 1-to-many NAT pool |
| 1:1 NAT | `nat_one_to_one` | Bidirectional static IP mapping |
| Hairpin | `nat_dnat` (hairpin flag) | LAN clients reach forwards via WAN IP |

NAT objects are referenced by Firewall rules by name, preventing filter/NAT inconsistency.

---

### 2.4 Dynamic Routing (FRR)

**File:** `app/Controllers/Routing.php` (2,881 lines) | **Route:** `/routing`

| Tab | DB Tables | Features |
| :--- | :--- | :--- |
| Static Routes | `static_routes` | Destination, gateway, interface, metric |
| Kernel FIB | Live via IPC | Real-time Linux routing table (read-only) |
| BGP | `frr_bgp_settings`, `frr_bgp_neighbors` | AS, router-ID, neighbors, prefix lists |
| OSPF | `frr_ospf_settings`, `frr_ospf_areas`, `frr_ospf_ifaces`, `frr_ospf_neighbors` | Areas, interfaces, neighbors, redistribution |
| OSPF Policy | `frr_prefix_lists`, `frr_route_maps`, `frr_ospf_distribute_lists`, `frr_ospf_area_filters` | Prefix-lists, route-maps, distribute-lists & ABR area filters |
| RIP | `frr_rip_settings`, `frr_rip_networks`, `frr_rip_neighbors`, `frr_rip_passive_interfaces` | RIPv2 networks, unicast neighbors, passive interfaces, redistribution |
| IS-IS | `frr_isis_settings`, `frr_isis_interfaces` | NET / area-tag, is-type, metric-style, per-interface circuit tuning |
| BFD | `frr_bfd_peers` | Sub-second link monitoring |
| VRF | `frr_vrfs` | Named virtual routing instances |
| Multi-WAN | `gateway_groups`, `gateway_members` | Load balance, failover, sticky sessions |
| PBR | `pbr_rules` | Policy-based routing with match objects |

**Sticky sessions** (migration 000054): Source-IP hash for gateway affinity.
**PBR match objects** (migration 000053): src/dst prefix, interface, DSCP, fwmark, protocol+port.
**OSPF policy tools** (migrations 000078, 000079): named prefix-lists and route-maps reused as redistribution filters, process-level distribute-lists and ABR area filter-lists.
**RIP & IS-IS** (migration 000080): full ripd (RIPv2) and isisd (integrated IS-IS) configuration surfaces.

---

### 2.5 Network Interfaces

**File:** `app/Controllers/Interfaces.php` | **Route:** `/interfaces`

Types: `physical`, `vlan` (802.1Q), `bridge`, `bond` (LACP), `loopback`, `dummy`, `pppoe`, `wireguard`, `openvpn`, `vti` (IPsec VTI). Enhanced for virtual/traffic-shaping params (migration 000070). IPv6 globally enabled via `ipv6_enabled` system setting (migration 000039).

---

### 2.6 DHCP Server

**File:** `app/Controllers/Dhcp.php` | **Route:** `/dhcp` | **Library:** `KeaDhcpCompiler`

Kea DHCP 2.6+: multiple server instances per interface, static MAC-to-IP reservations, custom options (43, 66, 67, 119, 252 for WPAD), Kea HA lease sync.

**DB tables:** `dhcp_server_settings`, `dhcp_static_leases`, `dhcp_active_leases`

---

### 2.7 DNS Resolver & Sinkhole

**File:** `app/Controllers/Dns.php` | **Route:** `/dns` | **Libraries:** `UnboundDnsCompiler`, `SystemResolverCompiler`, `DynamicDnsRegistry`, `ApplianceResolver`

Unbound with DNSSEC, DNS-over-TLS upstream forwarders, domain overrides/split-horizon (migration 000071), sinkhole feed management, wildcard FQDN Bloom filter. The settings tab also carries the appliance's own DNS resolution controls (migrations 000097–000098) — **DNS Server Override** (`dns_server_override`) and **DNS Resolution Behavior** (`dns_resolution_behavior`: `local-fallback` default, `local-only`, `remote-only`) — compiled by `SystemResolverCompiler` into `/etc/resolv.conf` and applied through the `dns.resolver.apply` RPC, independently of how Unbound answers clients.

The dynamic half of the override lives in the interface path: after a successful `Interfaces::apply()`, `Pppoe::apply()` or `VpnOpenvpn::restart()`, `ApplianceResolver` reads the `interface.dynamic_dns` RPC (the per-link files DHCP/PPP/OpenVPN up-scripts write under `/run/micron/dynamic-dns/<source>/<link>.dns`), reconciles the `dynamic_dns_servers` table through `DynamicDnsRegistry`, and re-applies the compiled resolver — merging the assigned servers while the override is enabled (`local-fallback`, `remote-only`) and dropping them when it is not. The compiler caps the merged list at the daemon's eight-server ceiling.

**Endpoints:** `saveSettings`, `saveResolverSettings` (`POST dns/resolver`), `storeHost`, `storeDomainOverride`, `storeFeed`, `syncFeeds`, `storeOverride`, `quickWhitelist`, `flush`

**DB tables:** `dns_settings`, `dynamic_dns_servers`, `dns_hosts`, `dns_domain_overrides`, `dns_feeds`, `dns_overrides`

---

### 2.8 VPN: WireGuard

**File:** `app/Controllers/VpnWireguard.php` | **Route:** `/vpn/wireguard` | **Libraries:** `WireguardCompiler`, `IpcClient`, `Cidr`

Site-to-site and road warrior. Enhanced: `tunnel_interface` (000035), `address_list` AllowedIPs (000037), `preshared_key` PSK (000040).

**Endpoints:** `saveTunnel`, `savePeer`, `applyWireguard`, `generateKeypair`, `exportPeerConf/{id}`, `exportPeerQr/{id}`

**DB tables:** `wireguard_tunnels`, `wireguard_peers`, `vpn_ephemeral_leases`

---

### 2.9 VPN: OpenVPN

**File:** `app/Controllers/VpnOpenvpn.php` | **Route:** `/vpn/openvpn` | **Libraries:** `OpenvpnCompiler`, `TotpService`

OpenVPN 2.6+ with dynamic 2FA TOTP challenge. Client mode (000033), push local subnets (000034).

**2FA flow:** Client connects -> Challenge -> `Username:Password:TOTP_CODE` -> `TotpService::verify()` -> lease in `vpn_ephemeral_leases`

---

### 2.10 VPN: IPsec (IKEv2 / strongSwan)

**File:** `app/Controllers/VpnIpsec.php` | **Route:** `/vpn/ipsec` | **Library:** `StrongswanCompiler`

strongSwan IKEv2. VTI mode (migration 000036): `vti_local_addr`, `vti_remote_addr`, `vti_interface`. Auth: PSK, RSA X.509, EAP-MSCHAPv2.

---

### 2.11 VPN User Self-Service Portal

**File:** `app/Controllers/VpnUserPortal.php` | **Route:** `/vpn/portal`

End-user portal: download WireGuard conf/QR, OpenVPN .ovpn, enroll/reset TOTP, view lease. Scoped to authenticated user via `vpn.portal.access` RBAC.

---

### 2.12 Web Filter & Proxy (Squid)

**File:** `app/Controllers/WebFilter.php` | **Route:** `/webfilter`

**Libraries:** `IpcClient`, `WpadGenerator`, `ServiceStatus`, `InterceptionCa`, `X509`, WebfilterSettingsModel, WebfilterCategoryModel, WebfilterFeedModel, WebfilterAccessLogModel, WebfilterAvDetectionModel

| Tab | Description |
| :--- | :--- |
| General | Proxy mode (Transparent/Explicit/TPROXY), port, HTTPS intercept |
| Categories | Per-category enable/disable for blocklists |
| Blocklist Feeds | External URL feeds, last-sync timestamp |
| Access Control | Source IP/subnet allowlist and denylist |
| Authentication | Squid LDAP/RADIUS auth for explicit proxy |
| SSL Inspection | Bump/Splice rules, CA cert management |
| Antivirus & Malware (ICAP / ClamAV) | C-ICAP bridge config, scan direction, failure policy, bypass domains, definitions telemetry, detection log |
| WPAD / PAC | Auto-discovery proxy.pac/wpad.dat generator |
| Access Logs | Filtered access log viewer |

**Antivirus endpoints:** `POST /webfilter/save-antivirus`, `POST /webfilter/av/update-signatures` (`webfilter.clamav.update`), `POST /webfilter/av/clear-detections`, with telemetry from `webfilter.av.status`.

**SSL-Bump interception CA:** `POST /webfilter/provision-ca` generates the CA into the PKI store and installs it at the configured Squid paths; `GET /webfilter/download-ca` serves that CA (or the provisioned PEM on disk). It never serves a placeholder — with no CA available it returns HTTP 503 and says so, because a client that installs an unusable root cannot validate any intercepted session.

**Enhanced tables** (migration 000072, 28,055 bytes): `webfilter_acl_rules`, `webfilter_ssl_rules`, `webfilter_custom_categories`, `webfilter_access_logs`
**Antivirus tables** (migration 000082): `webfilter_av_detections` plus the `icap_*` / `clamav_*` columns on `webfilter_settings`

---

### 2.13 PPPoE Access Concentrator & Client

**File:** `app/Controllers/Pppoe.php` (865 lines) | **Route:** `/pppoe`

**Libraries:** `PppoeCompiler`, `PppoeBackoff`, `IpcClient`, `RadiusAttributes`

**BRAS mode:** `pppd + pppoe-server` terminating subscriber sessions. RADIUS auth (000057), Accounting (000058), EAP-TLS (000059). Session history (000060): `pppoe_session_history`, `pppoe_subscriber_metrics`, `pppoe_server_stats`.

**Client mode:** `pppd` dialing ISP PPPoE (ADSL/VDSL/GPON) with PAP/CHAP/EAP and LCP keep-alive.

**Partial refresh:** `GET /pppoe?partial=sessions` returns session table partial only.

**Exponential backoff redial:**
```
Drop -> PppoeBackoff::nextDelay() -> 5s, 10s, 20s, ... max 300s
     -> IpcClient::redialPppoe() -> micrond -> pppd reconnect
```
State persisted in `pppoe_client_backoff` (survives reboots).

---

### 2.14 IDS/IPS (Suricata)

**File:** `app/Controllers/Ids.php` | **Route:** `/ids`

**Libraries:** `SuricataCompiler`, `IpcClient`, `GeoIp`

| Tab | Features |
| :--- | :--- |
| Alerts | Real-time feed, severity classification, GID/SID |
| IP Rules | IP passlist (whitelist) and banlist (blacklist) |
| GeoIP | Country-level geo-fence via MaxMind GeoLite2 |
| Settings | IDS/IPS mode, interface binding, threat feeds, NFQUEUE diversion |
| Rule Management | Emerging Threats / ET Pro / SNORT rule sets |

**Modes:** `IDS` (AF_PACKET passive) or `IPS` (inline). Inline mode is a two-sided contract: `SuricataCompiler` emits the `nfq:` block and `af-packet: copy-mode: ips`, and **`NftablesCompiler` emits the diversion that feeds it** — `table inet micron_ids` with `chain ips_forward`/`ips_input` at `priority 10` (after the `priority filter` chains, so only already-accepted traffic is inspected) running `queue num <nfqueue_num> [bypass]`. Without that rule the engine reads a queue nothing populates, so "IPS" would inspect nothing. `queue_fail_mode` selects `bypass` (fail open) or its absence (fail closed).

**DB tables** (migration 000071, +000083): `ids_settings`, `ids_ip_rules`, `ids_geo_rules`, `ids_alert_log`

---

### 2.15 DDoS & DoS Protection Policies

**File:** `app/Controllers/DosPolicy.php` | **Route:** `/firewall/dos`

nftables rate limiting and synproxy policies:

| Preset | Mechanism | Protection |
| :--- | :--- | :--- |
| `syn_flood` | synproxy + limit rate | TCP SYN flood |
| `conn_limit` | ct count per-host | Connection exhaustion |
| `udp_flood` | limit rate | UDP bandwidth flood |
| `icmp_flood` | limit rate + type match | ICMP Ping flood |
| `port_sweep` | ct state new limit | Port scanner detection |
| `dynamic_ban` | nft set + timeout | Auto-blacklist attacker IPs |

Schedule support (migration 000077). **DB table** (migration 000074): `dos_policies`

---

### 2.16 QoS Traffic Shaper

**File:** `app/Controllers/Qos.php` | **Route:** `/qos`

Linux `tc` with CAKE and fq_codel. Per-interface upload/download limits, traffic class hierarchies (HFSC/CAKE), DSCP marking/re-marking, schedule-based rate overrides.

**DB tables:** `qos_interfaces`, `qos_classes`, `qos_rules`

---

### 2.17 Schedule Objects

**File:** `app/Controllers/Schedule.php` | **Route:** `/schedule`

Reusable time/day objects for Firewall Rules, NAT (DNAT), QoS, and DoS policies. Format: day-of-week bitmask + time ranges. Cross-subsystem usage reference counts displayed.

**DB table** (migration 000075): `firewall_schedules`

---

### 2.18 High Availability (VRRP)

**File:** `app/Controllers/HighAvailability.php` | **Route:** `/ha`

Keepalived VRRPv2/v3 for active/passive failover. VIP groups per interface, `conntrackd` for stateful session sync (preserves TCP/VoIP/WireGuard during failover). Pre-empt / non-preempt modes.

**DB tables** (migration 000024): `ha_vrrp_groups`, `ha_vrrp_virtual_ips`

---

### 2.19 SNMP Agent

**File:** `app/Controllers/Snmp.php` | **Route:** `/snmp`

Net-SNMP (`snmpd`): SNMPv1/v2c community strings, SNMPv3 USM (`authPriv`) with MD5/SHA + DES/AES, trap destinations, MicronOS-specific OID extensions.

**DB tables** (migration 000029): `snmp_settings`, `snmp_communities`, `snmp_v3_users`, `snmp_trap_targets`

---

### 2.20 SIEM Integration

**File:** `app/Controllers/Siem.php` | **Route:** `/siem`

Real-time log shipping. **Formats:** CEF (ArcSight), LEEF (QRadar), JSON ECS (ELK), RFC 5424 Syslog. **Sources:** nftables firewall log, Suricata EVE, Squid access log, auth events, audit trail. **Transports:** UDP syslog, TCP syslog (TLS), HTTPS webhook.

**DB tables** (migration 000029): `siem_targets`, `siem_source_mappings`

---

### 2.21 Internet Services Database

**File:** `app/Controllers/InternetServices.php` | **Route:** `/internet-services`

Offline IP set database for named cloud/SaaS services. Policies reference by name instead of managing raw IP ranges.

**Resolution flow:**
```
ServiceSources::fetchAll()   <- AWS, Cloudflare, Office 365, Google
  -> ServiceDatabase::upsert()
  -> AddressResolver::resolve()  <- FQDN + GeoIP
  -> NftablesCompiler named sets
```

**Seed URLs** (migration 000051): AWS IP ranges, Cloudflare, Microsoft 365 endpoints, Google goog.json.

**DB table** (migration 000048): `internet_services`

---

### 2.22 Cron Job Scheduler

**File:** `app/Controllers/Cron.php` | **Route:** `/cron` | **Library:** `CronJobLog`

Database-driven cron system with built-in seeded jobs:

| Job | Schedule | Purpose |
| :--- | :--- | :--- |
| `address_resolve` | Every 6h | Resolve FQDN objects to IP prefixes |
| `service_sync` | Daily 02:00 | Sync Internet Services IP databases |
| `pppoe_redial` | Every 30s | PPPoE health check + redial |
| `cert_expiry_check` | Daily 08:00 | Alert on certs expiring within 30 days |
| `backup_auto` | Weekly Sun 03:00 | Encrypted automated backup |
| `ids_rule_update` | Daily 04:00 | Download Suricata rule updates |
| `siem_flush` | Every 5min | Flush buffered SIEM log events |

**DB tables** (migration 000028): `cron_jobs`, `cron_run_log`

---

### 2.23 System Services Manager & Per-Page Daemon Controls

**File:** `app/Controllers/Services.php` (324 lines) | **Route:** `/services` | **Library:** `ServiceStatus`

systemd GUI: nftables, frr, wg-quick@*, openvpn@*, strongswan, suricata, squid, kea-dhcp4/6, unbound, keepalived, snmpd, pppd/pppoe-server, micron-cron, clamav-daemon, c-icap, rsyslog. All actions recorded in `audit_logs`.

**Endpoints:**

| Method | Route | Purpose |
| :--- | :--- | :--- |
| GET | `/services` | Full service inventory table |
| GET | `/services/status?ids=a,b,c` | Lightweight run-state poll (cap 25 ids, unknown → `unknown`) |
| POST | `/services/{id}/control` | JSON Start/Stop/Restart (`action` ∈ start/stop/restart); returns rotated CSRF token |
| POST | `/services/{id}/start` \| `/stop` \| `/restart` | Redirect-based legacy controls |
| POST | `/services/{id}/toggle-boot` \| `/toggle-watchdog` | Boot-enable / watchdog flags |
| GET | `/services/logs/{id}` | JSON `{service,status,logs}` journal viewer |
| POST | `/services/restart-all` | Restart every managed daemon |

**Per-page daemon controls.** `App\Libraries\ServiceStatus::widget('squid')` (or an array of ids) renders the shared `partials/service_controls` widget into any page header. It shows a status LED, daemon name and run-state with Start / Restart / Stop buttons, polls `/services/status` every 15s (pausing when the tab is hidden and skipping while an action is in flight), collapses gracefully below 1400px, and opens the shared `partials/service_logs_modal` journal viewer on LED click. Views must call the `view()`-backed `ServiceStatus` helper rather than `$this->include()`, because CodeIgniter 4's `include()` does not merge its options array into the view data.

The widget is wired into the Web Filter (squid), IDS (suricata), DHCP (kea-dhcp4), DNS (unbound), HA (keepalived/conntrackd), Firewall (nftables), SIEM (rsyslog), SNMP (snmpd), IPsec (strongswan), OpenVPN, WireGuard and Routing (frr) page headers.

---

### 2.24 Certificates & PKI

**File:** `app/Controllers/Certificates.php` | **Route:** `/certificates`

Full PKI: CAs (create/import), Certificates (issue/import), CSR round-trip, PEM/PKCS#12 import (migration 000038), and **ACME / Let's Encrypt issuance and renewal** (migration 000101). Used by WireGuard, OpenVPN, IPsec, Squid SSL, LDAP, Web UI HTTPS.

**DB tables** (migration 000024): `pki_authorities`, `pki_certificates`, plus `pki_acme_accounts` and `pki_acme_orders` (migration 000101)

#### ACME / Let's Encrypt

An ACME (RFC 8555) client and its appliance-facing state, so a publicly-trusted certificate is issued and renewed from this page rather than by hand:

| Component | File | Role |
| :--- | :--- | :--- |
| Protocol client | `app/Libraries/Acme.php` | ES256 JWS (`r‖s`, not DER), account, order, authorization, HTTP-01 / DNS-01, finalize, certificate download |
| Appliance state | `app/Libraries/AcmeManager.php` | Account key persistence, order lifecycle, renewal window, storage into the PKI tables, `cert.reload` |
| Scheduled task | `app/Commands/AcmeRenewCommand.php` (`micron:acme-renew`, daily 03:20) | Renews everything inside the window |

- **Directories are a closed set**: the production and staging Let's Encrypt endpoints only. A pasted URL can never turn the management plane into a request forwarder, and requests are single-hop HTTPS with no redirects.
- **A certificate lands in `pki_certificates` with `source = 'acme'`**, its `ca_id` pointing at a `pki_authorities` row that holds the issuer's chain and *no private key*. Everything that already reads those tables — Web GUI, VPN tunnels, LDAP, Squid — picks it up with no second configuration step.
- **HTTP-01 is answered by the appliance** at `GET /.well-known/acme-challenge/<token>` (no session, outside the authenticated route group). The key authorization is written to `writable/acme-challenges/` when the order opens and removed when it finishes, so nothing is served for an order the CA is not waiting on. The challenged name must resolve to this appliance on port 80, and the shipped vhost serves that directory there rather than redirecting it to HTTPS ([ISO & Installer Guide](ISO_CREATION_AND_INSTALLER_GUIDE.html) §6.1) — a redirect would send the CA into a TLS handshake with the certificate it is trying to replace.
- **DNS-01 returns the TXT records** for the operator to publish; the appliance holds no DNS provider credentials. Wildcard names are therefore accepted only over DNS-01.
- **Renewal** is derived from `valid_until` — 30 days before expiry by default, no stored flag — and re-issues into the *same* certificate row so every binding that references it survives. DNS-01 certificates are reported by the sweep rather than renewed unattended.
- **Permission:** `cert.acme.manage` — separate from `cert.create`, because requesting a certificate names this appliance's domains to a third party.

**Verified against a stub CA** that reads the HTTP-01 answer back from disk and signs the submitted CSR: `tests/app/Libraries/AcmeTest.php` (the JWS signature is independently re-verified with OpenSSL), `tests/app/Libraries/AcmeManagerTest.php` (issuance, the renewal window, in-place renewal), `tests/app/Controllers/CertificatesAcmeTest.php` (routes, the anonymous challenge endpoint, the panel) and `tests/app/Commands/AcmeRenewCommandTest.php`.

---

### 2.25 Configuration Backup & Restore

**File:** `app/Controllers/Backup.php` | **Route:** `/backup` | **Library:** `BackupEngine`

| Format | Extension | Encryption |
| :--- | :--- | :--- |
| Encrypted | `.mbk.enc` | AES-256-GCM, PBKDF2-SHA256 key derivation (100 000 rounds, 16-byte salt), 12-character minimum passphrase |
| Plain | `.mbk` | None — the payload document as written (JSON, not base64) |

An archive is a single JSON document: a header (`format`, `micronos_version`, `timestamp`, `hostname`) plus a `tables` map of configuration rows, `hardware.interfaces` for the remap, and — encrypted archives only — `secrets.master_key`. There is no tar container, and a plaintext archive carries neither connector credentials nor the master key.

Restore: selective subsystem restore by curated section, NIC remapping, connector key policy with post-restore credential verification. Planned, not present: Argon2id, a tar container, scheduled snapshots, retention rotation and SFTP/S3/Central export (architecture guide §17.7).

**DB table** (migration 000012): `system_backups`

---

### 2.26 Diagnostics & Network Tools

**File:** `app/Controllers/Diagnostics.php` (69,031 bytes) | **Route:** `/diag`

Each tool is proxied to `micrond` over IPC, and its answer is read out of the
JSON-RPC envelope's `result`. The one-shot tools return their output as JSON; ping,
traceroute and the live packet capture are instead sessions the page polls, so a
reply, a hop or a frame reaches the terminal as the daemon produces it rather than in
one lump at the end.

| Tool | Route | Parameters |
| :--- | :--- | :--- |
| Ping | `POST /diag/ping` | host, ipproto (IPv4/IPv6), sourceip, count, wait |
| Traceroute | `POST /diag/traceroute` | host, ipproto (IPv4/IPv6), sourceip, ttl, resolve, useicmp |
| Ping — stream start | `POST /diag/ping/start` | host, ipproto (IPv4/IPv6), sourceip, count, wait |
| Traceroute — stream start | `POST /diag/traceroute/start` | host, ipproto (IPv4/IPv6), sourceip, ttl, resolve, useicmp |
| Streamed probe — poll | `GET /diag/stream` | session |
| Streamed probe — stop | `POST /diag/stream/stop` | session |
| DNS Lookup | `POST /diag/dns-lookup` | name, type, server |
| Route Lookup | `POST /diag/route-lookup` | target |
| Port Probe | `POST /diag/port-probe` | host, port, protocol, timeout |
| DNS Benchmark | `POST /diag/dns-benchmark` | domain |
| Packet Capture | `POST /diag/packet-capture` | interface, filter, packets |
| Live Capture — start | `POST /diag/packet-capture/start` | interface, filter, packets |
| Live Capture — poll | `GET /diag/packet-capture/stream` | session |
| Live Capture — stop | `POST /diag/packet-capture/stop` | session |
| Firewall Log Stream | `GET /diag/logs/data` | — |
| Connection States | `GET /diag/states/data` | — |
| FIB / ARP / NDP tables | `GET /diag` | — (rendered on the page) |

**Live packet capture.** The runner is a session, not a request. `POST …/start`
validates the interface, the frame count (1–50) and the BPF expression, then has the
daemon compile the filter with `tcpdump -d` *before* launching
`timeout 120 tcpdump -i <iface> -n -l -c <n> <filter>` detached and writing to its own
file. `GET …/stream?session=` hands back only the bytes produced since the previous poll
(the terminal appends them, so frames appear live), and `POST …/stop` terminates the
capture and returns its tail. At most four sessions run at once and the `timeout`
wrapper caps any one at two minutes, so a filter that matches nothing cannot leave a
capture running. A BPF expression tcpdump cannot compile is refused with tcpdump's own
syntax error, and the whole set is gated on the dedicated `diag.packet_capture`
permission.

**Streamed ping and traceroute.** Ping prints a line per reply and traceroute a line per
hop, so neither waits for the command to finish: `POST /diag/ping/start` (or
`…/traceroute/start`) opens a session, `GET /diag/stream?session=` hands back only the
bytes produced since the previous poll, and `POST /diag/stream/stop` closes it early.
The terminal *appends* each poll's output, so the run reaches the operator line by line,
and the page registers the poll with the shared scheduler under `diag.probe` at 400 ms —
a failed attempt is reported to the scheduler rather than shown as a dead stream,
whereas a session the appliance *refuses* ends the probe. Ping and traceroute share the
one terminal with the live capture, so opening either closes whatever was already
running. Because CSRF regeneration is on and the CSRF cookie is `HttpOnly`, every
response hands the next hash back in its body and the page adopts it; a probe after the
first would otherwise be refused 403. That whole path — the two forms posting their own
fields, the poll appending only what it brought, the finish standing the poll down, the
hash hand-off, the three refusals, the outage that backs off, and one stream at a time —
is driven in Node by `tests/js/diagnostics_terminal_harness.mjs` (see §8).
| WireGuard Peers | `/diagnostics/wgpeers` | interface |
| DHCP Leases | `/diagnostics/dhcpleases` | server |

---

### 2.27 System Settings & Appearance

**File:** `app/Controllers/SystemSettings.php` | **Route:** `/system/settings`

| Category | Settings |
| :--- | :--- |
| General | Hostname, domain, timezone, NTP servers |
| Web UI | HTTPS port, session timeout, max login attempts |
| Appearance | Theme (9 presets), layout, custom login banner |
| Sticky Header | Enable, blink animation, header accent colors |
| IPv6 | Global IPv6 enable/disable (migration 000039) |
| SSH | Port, allowed users, key-only auth |
| Console | Serial console baud rate, login timeout |
| Updates | **Not implemented.** No update channel/window columns, fields or routes exist. The only `auto_update` columns in the schema belong to the *content* plane (`ids_settings`, webfilter category tables) and are unrelated to firmware. The planned System → Updates surface is specified in the [Update & Upgrade Mechanism Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html). |

**Migrations:** 000030 (base), 000031 (layout), 000032 (theme column), 000039 (IPv6), 000041 (banner+sticky), 000044 (blink), 000045 (header colors), 000046 (refinements)

---

### 2.28 User Management

**File:** `app/Controllers/Users.php` | **Route:** `/users`

Local users (bcrypt), LDAP/AD import with group mapping, force-change-on-login flag, enable/disable without deletion, API token management, 2FA enrollment status.

**DB table** (migration 000005): `users`

---

### 2.29 RBAC Roles & Permissions

**File:** `app/Controllers/Roles.php` | **Route:** `/roles`

```
users -> user_roles -> roles -> role_permissions -> permissions
```

System roles: `SuperAdmin` (all 90+ perms), `NetworkAdmin`, `SecurityAdmin`, `ReadOnly`, `VpnUser`. Permission key format: `module.resource.action` (e.g. `firewall.rules.edit`).

**DB tables** (migrations 000015-000018): `roles`, `permissions`, `role_permissions`, `user_roles`

---

### 2.30 Authentication (Local, LDAP, RADIUS, 2FA)

**File:** `app/Controllers/Auth.php` | **Route:** `/auth`

**Libraries:** `LdapAuthService`, `RadiusAuthService`, `TotpService`

```
Login submit
  |- Local DB (bcrypt) -> Pass -> TOTP enrolled? -> verify/skip
  |- LDAP/AD bind test -> DN bind -> sync local session
  `- RADIUS Access-Request -> Access-Accept -> login success
```

**Tables** (migration 000073): `ldap_servers`, `ldap_group_mappings`, `radius_servers`, `radius_test_log`

---

### 2.31 System Commit Engine

**File:** `app/Controllers/SystemCommit.php` | **Route:** `/system/commit`

Commit Watchdog: Stage candidate -> Compile -> Apply to data plane -> 60s countdown in browser -> Confirm (commit) or Expire (auto-rollback).

**DB table** (migration 000013): `config_transactions`

Each transaction records its own timing in the two columns the table actually has — `applied_at` (when the candidate configuration reached the appliance) and `confirmed_at` (the resolution stamp: when the transaction was closed, whether it was confirmed by the operator or rolled back by the watchdog, the console, or the Rollback button). A rollback stamps the resolution time and leaves `applied_at` alone, so a discarded transaction stays distinguishable from one still awaiting confirmation; read `status` to know which resolution happened. The console equivalent is `microctl config rollback`, and the Dashboard's System Information card shows the most recent rollback (when, and by whom) from this log. Note that `id` is a `VARCHAR(36)` primary key with no auto-increment — it is generated by `ConfigTransactionModel::startPending()`.

---

### 2.32 Docs Portal

**File:** `app/Controllers/Docs.php` | **Route:** `/docs`

Serves the internal documentation hub. It reads the repository's `docs/*.md` directly (the markdown stays the single source of truth), splits each guide into its `## ` sections and renders a filterable table of contents with one card per section — an operator-manual layout rather than the standalone HTML export. The section anchors reuse GitHub-style slugs so any in-document link keeps resolving. Guides currently exposed: Architecture Blueprint, Implementation Plan, ISO & Installer Guide, SSL-VPN & ZTNA Spec, and Module Reference. Run `php docs/build_docs.php` to regenerate the standalone `docs/*.html` bookmarks and the `docs/index.html` portal.

---

## 3. Compiler Libraries Reference

### 3.1 NftablesCompiler

**File:** `app/Libraries/NftablesCompiler.php` (80,275 bytes — largest library, 62 graph edges)

Translates entire MicronOS DB state into a single atomic nftables ruleset.

**Output structure:**
```nftables
flush ruleset

# Named address sets
define addr_DMZ_SERVERS = { 10.10.1.1, 10.10.1.2 }

# Internet service sets (IP intervals)
set internet_service_microsoft365 {
    type ipv4_addr; flags interval;
    elements = { 13.107.6.0/24, 40.96.0.0/13, ... }
}

# NAT table
table ip nat {
    chain prerouting { type nat hook prerouting priority -100; }
    chain postrouting { type nat hook postrouting priority 100; }
}

# Filter table with flowtable fast-path
table inet filter {
    flowtable ft_fastpath { hook ingress priority 0; devices = { eth0, eth1 }; }
    chain forward_WAN_LAN { ... }
    chain forward_LAN_WAN { ... }
}

# DoS rate limiting
table inet dos {
    chain dos_input { type filter hook input priority -10; }
}
```

**Key methods:**
```php
NftablesCompiler::build(): string         // Full ruleset
NftablesCompiler::buildDos(): string      // DoS fragment only
NftablesCompiler::validateSyntax(): bool  // Dry-run via nft --check
NftablesCompiler::buildAddressSets(): string
NftablesCompiler::buildNatChains(): string
NftablesCompiler::buildForwardChains(): string
```

---

### 3.2 PppoeCompiler

**File:** `app/Libraries/PppoeCompiler.php` (46,597 bytes, 55 graph edges)

Generates pppd and pppoe-server config files for BRAS and client modes.

**Output files:** `/etc/ppp/pppoe-server-options`, `/etc/ppp/chap-secrets`, `/etc/ppp/pap-secrets`, `/etc/ppp/peers/micron-wan`, `/etc/raddb/server` (if RADIUS).

**Key methods:**
```php
PppoeCompiler::renderServer(array $server): array  // Returns [filename => content]
PppoeCompiler::renderClient(array $client): array
PppoeCompiler::usesRadius(array $server): bool
PppoeCompiler::usesAccounting(array $server): bool
PppoeCompiler::fetchServerUsers(string $serverId): array
```

---

### 3.3 WireguardCompiler

**File:** `app/Libraries/WireguardCompiler.php` (16,364 bytes)

Generates `/etc/wireguard/wgX.conf`:

```ini
[Interface]
Address = 10.200.0.1/24
ListenPort = 51820
PrivateKey = <server_private_key>
PostUp = nft add rule inet filter forward iifname "wg0" accept

[Peer]
PublicKey = <peer_public_key>
PresharedKey = <optional_psk>
AllowedIPs = 10.200.0.10/32
PersistentKeepalive = 25
```

---

### 3.4 OpenvpnCompiler

**File:** `app/Libraries/OpenvpnCompiler.php` (16,891 bytes)

Generates OpenVPN server `.conf` with `auth-user-pass-verify` script for `Username:Password:TOTP_CODE` 2FA. Also generates portable `.ovpn` client profiles embedding CA, cert, key, and TLS-auth key.

---

### 3.5 StrongswanCompiler

**File:** `app/Libraries/StrongswanCompiler.php` (11,717 bytes)

Generates `swanctl.conf` (vici format) for IKEv2. Supports transport/tunnel mode, VTI binding, PSK/certificate auth, split tunneling, and DPD.

---

### 3.6 SuricataCompiler

**File:** `app/Libraries/SuricataCompiler.php` (11,716 bytes)

Generates `suricata.yaml`. Key sections: af-packet/nfqueue interface bindings, address-groups vars, rule-files list, EVE JSON output, suppress list (from IP passlist), threshold list.

---

### 3.7 SquidConfigCompiler

**File:** `app/Libraries/SquidConfigCompiler.php` (12,194 bytes)

Generates `squid.conf`. Key sections: http_port/https_port with intercept/ssl-bump flags, ACL definitions from blocklists, ssl_bump rules (peek/splice/bump), auth_param for LDAP/RADIUS, cache_dir, and the ICAP antivirus bridge. When `icap_antivirus_enabled` is set it emits `icap_enable on`, `icap_preview_size`, `icap_service_failure_limit`, per-direction `icap_service … reqmod_precache|respmod_precache bypass=…` lines, an optional `acl av_scan_bypass dstdomain …` list and the matching `adaptation_access` rules.

---

### 3.8 KeaDhcpCompiler

**File:** `app/Libraries/KeaDhcpCompiler.php` (4,896 bytes)

Generates `/etc/kea/kea-dhcp4.conf` (JSON). Multiple subnets, MAC-based static reservations, custom DHCP options.

---

### 3.9 UnboundDnsCompiler

**File:** `app/Libraries/UnboundDnsCompiler.php` (5,886 bytes)

Generates `/etc/unbound/unbound.conf`, `local-zones/sinkhole.conf` (blocklist entries), and `local-zones/overrides.conf` (split-horizon entries).

---

## 4. Utility Libraries Reference

### 4.1 IpcClient

**File:** `app/Libraries/IpcClient.php` (29,729 bytes — most connected node: 83 graph edges)

Primary gateway: PHP management plane <-> `micrond` daemon via Unix Domain Socket / JSON-RPC 2.0. All calls return `['ok' => bool, 'data' => mixed, 'error' => string]`.

**Dev mode:** `MICRONOS_IPC_MOCK=true` in `.env` returns realistic fake data without a live Linux system.

**Key method groups:**
```php
// Firewall
IpcClient::applyFirewall(): array
IpcClient::getFirewallRules(): array

// Routing
IpcClient::applyStaticRoutes(): array
IpcClient::applyFrrConfig(): array
IpcClient::getKernelRoutes(): array

// WireGuard
IpcClient::applyWireguard(string $tunnel): array
IpcClient::reloadWireguardPeer(string $peer): array  // atomic, no restart
IpcClient::getWireguardStatus(): array

// OpenVPN
IpcClient::applyOpenvpn(string $server): array
IpcClient::getOpenvpnStatus(): array

// IPsec
IpcClient::applyIpsec(): array
IpcClient::reloadIpsecSwanctl(): array  // vici reload, no restart

// PPPoE
IpcClient::applyPppoe(string $server): array
IpcClient::getPppoeStatus(string $server): array
IpcClient::redialPppoe(string $server): array
IpcClient::getPppoeRadiusStatus(string $server): array

// System
IpcClient::systemInfo(): array
IpcClient::interfaceList(): array
IpcClient::daemonAction(string $service, string $action): array
```

---

### 4.2 CommandDispatcher

**File:** `app/Libraries/CommandDispatcher.php` (6,445 bytes)

Higher-level IpcClient wrapper for Dashboard. Gracefully degrades to mock/empty states on IPC failure.

---

### 4.3 BackupEngine

**File:** `app/Libraries/BackupEngine.php`

The archive format, the envelope layout and the restore pipeline are specified in architecture guide §17; this is the API surface.

```php
BackupEngine::createBackup(?string $passphrase = null): array   // filename, content, sha256, is_encrypted, credentials_included, size
BackupEngine::restore(array $payload, array $nicMap = [], array $selectedTables = [], bool $replaceKey = false): array
BackupEngine::decryptPayload(string $envelope, string $passphrase): ?string
BackupEngine::isEncryptedArchive(string $raw): bool
BackupEngine::payloadDigest(string $payload): string           // what system_backups.sha256_checksum records
BackupEngine::recordedDigest(string $filename): string
BackupEngine::registeredArchives(): array
BackupEngine::inspectHardwareDifferences(array $payload, array $currentNics): array   // not yet wired to the UI
```

`ENVELOPE_MAGIC` (`MICRON_ENC_V1`) is a public constant because three jobs depend on recognising it: the writer, the restorer and the verifier.

Encryption: `openssl_encrypt(..., 'aes-256-gcm', ...)` over the payload document, with a 256-bit key derived by **PBKDF2-SHA256** (100 000 rounds, 16-byte salt). Not ChaCha20-Poly1305 and not Argon2id — that design was never built, and `ext-sodium` is not required (see §17.7 for the planned upgrade).

---

### 4.4 AddressResolver (FQDN/GeoIP)

**File:** `app/Libraries/AddressResolver.php` (6,318 bytes)

Resolves FQDN wildcard address objects to current IP prefixes stored in `firewall_addresses.resolved_prefixes` (migration 000049). Resolves GeoIP country objects via MaxMind GeoLite2. Triggers NftablesCompiler rebuild if prefixes changed. Runs every 6h via `address_resolve` cron job.

---

### 4.5 Cidr

**File:** `app/Libraries/Cidr.php` (7,109 bytes, 35 graph edges)

```php
Cidr::contains(string $ip, string $cidr): bool
Cidr::overlaps(string $a, string $b): bool
Cidr::merge(array $cidrs): array    // supernet merging
Cidr::split(string $cidr, int $prefix): array
Cidr::toRange(string $cidr): array  // [first_ip, last_ip]
```

---

### 4.6 - 4.9 Other Address Libraries

- **`Fqdn.php`** (9,294 bytes) — Validates and normalizes FQDNs with wildcard support.
- **`GeoIp.php`** (10,358 bytes) — MaxMind GeoLite2 offline country-code lookup and per-country CIDR enumeration.
- **`InternetService.php`** (9,851 bytes) — Queries `internet_services` table for named cloud service IP prefix sets.
- **`ServiceDatabase.php`** (11,011 bytes) + **`ServiceSources.php`** (11,529 bytes) — Fetch/normalize AWS, Cloudflare, Microsoft, Google IP ranges and upsert/dedup into `internet_services`.

---

### 4.10 ResolveObjects

**File:** `app/Libraries/ResolveObjects.php` (6,157 bytes)

Expands symbolic firewall object references (e.g. `src_address = "OFFICE_SUBNET"`) to actual IP lists at compile time.

---

### 4.11 WpadGenerator

**File:** `app/Libraries/WpadGenerator.php` (11,837 bytes)

Generates `proxy.pac`/`wpad.dat` JavaScript:
```javascript
function FindProxyForURL(url, host) {
    if (isInNet(host, "192.168.0.0", "255.255.0.0")) return "DIRECT";
    if (dnsDomainIs(host, ".internal.corp")) return "DIRECT";
    return "PROXY 192.168.1.1:3128";
}
```

---

### 4.12 LdapAuthService & RadiusAuthService

- **`LdapAuthService.php`** (7,962 bytes) — PHP `ldap_*` DN bind, group membership check, attribute sync.
- **`RadiusAuthService.php`** (10,324 bytes) — Pure-PHP RFC 2865 RADIUS Access-Request with PAP/CHAP, primary+secondary failover.

---

### 4.13 - 4.17 Remaining Libraries

- **`TotpService.php`** (3,343 bytes) — RFC 6238 TOTP: `generateSecret()`, `verify()`, `getQrUri()`, `getScratchCodes()`.
- **`X509.php`** (7,406 bytes) — Certificate and CSR parsing/validation: PEM normalization, DN/SAN/extension extraction (`parseCertificate`), key↔certificate match, CSR validation. It does **not** generate keys or certificates — CA/cert creation is done with OpenSSL directly by `Certificates::createCa()` and `InterceptionCa`.
- **`CronJobLog.php`** (9,260 bytes) — `start(job): run_id`, `success(run_id, output)`, `failure(run_id, error)`, `getLastRun(job)`, `exists(job)`.
- **`PppoeBackoff.php`** (13,446 bytes) — Exponential backoff state machine (max 300s). `nextDelay()`, `reset()`, `hasTable()` (safe migration check).
- **`RadiusAttributes.php`** (6,485 bytes) — RADIUS attribute constants and encoder/decoder for raw packet construction.

---

### 4.18 ServiceStatus

**File:** `app/Libraries/ServiceStatus.php` (58 lines)

Read-only helper over the `system_services` table (the web-side source of truth for daemon run-state, kept in sync by `micrond`).

```php
ServiceStatus::get(string $id): array        // row or safe {status:'unknown'} placeholder
ServiceStatus::isRunning(string $id): bool
ServiceStatus::widget(string|array $ids): string  // renders partials/service_controls
```

`widget()` is the supported way to embed the page-header Start/Restart/Stop control; it renders through `view()` so options are merged correctly (CI4 `include()` would silently drop them). It degrades to `status = 'unknown'` if the database is briefly unreachable.

---

### 4.19 ObjectTip

**File:** `app/Libraries/ObjectTip.php` (280 lines)

Builds server-side hover-detail cards for firewall objects and returns a `data-fw-tip` attribute string. Entry points: `attr()`, `address()`, `addressType()`, `addressValue()`, `addressGroup()`, `service()`, `serviceGroup()`, `internetService()`, `schedule()`, `iface()`. Empty fields are skipped so a card only shows populated rows; content is escaped for the `attr` context. Rendered client-side by `app/Views/firewall/_obj_tooltip.php`, which also copies option details onto `<select>` elements tagged `data-fw-select`.

---

### 4.20 InterceptionCa

**File:** `app/Libraries/InterceptionCa.php`

The certificate authority Squid SSL-Bump uses to mint per-host certificates for decrypted HTTPS sessions. It lives in the PKI store (`pki_authorities`, the same table managed on `/certificates`) rather than a private side-channel, so it can be inspected, rotated and exported like any other CA.

```php
InterceptionCa::ensure(): ?array              // find by CN, generating it with OpenSSL on first use
InterceptionCa::certificate(): ?string         // PEM, or null when OpenSSL is unavailable
InterceptionCa::provision(certPath, keyPath): array   // write to the configured Squid paths
```

The generated certificate is a genuine self-signed CA (`basicConstraints CA:TRUE`, via the `v3_ca` OpenSSL extension) — without that extension it could not sign the per-host certificates and HTTPS inspection would fail. `COMMON_NAME` (`MicronOS Proxy Inspection CA`) keys the lookup, so the CA is generated once and reused.

---

## 5. Database Migration Inventory

75 migrations, all dated 2026-10-02 through 2026-10-06.

### Foundation (000001-000020)
`interfaces`, `firewall_rules`, `nat_rules`, `ldap_servers`, `users`, `openvpn_servers`, `wireguard_tunnels`, `wireguard_peers`, `vpn_ephemeral_leases`, `webfilter_settings`, `webfilter_categories`, `system_backups`, `config_transactions`, `audit_logs`, `roles`, `permissions`, `role_permissions`, `user_roles`, `ipsec_tunnels`, network CRUD tables

### Feature Expansion (000021-000040)
Firewall objects tables, dynamic routing (BGP/OSPF/BFD/VRF), HA/QoS/PKI tables, DHCP, DNSBL, comprehensive NAT, services/cron (17,032 bytes), SNMP/SIEM (12,606 bytes), system settings, OpenVPN client mode, local subnets push, WireGuard tunnel interface, IPsec VTI fields, WireGuard AllowedIPs, PKI import support, IPv6 enabled flag, WireGuard PSK

### Sticky Header (000041, 000044-000046)
Login banner + sticky header base, blink animation, header accent colors, refinements

### NAT Refactor (000042-000047)
`nat_dnat` and `nat_snat_pools` as first-class named objects. Migration 000043 consolidates. Migration 000046 finalizes schema names. Migration 000047 neutralizes borrowed names.

### Internet Services (000048-000052)
`internet_services` table, `resolved_prefixes` column on addresses, cron job seeds (address resolve, service sync, PPPoE redial)

### Routing Enhancements (000053-000054)
PBR match objects, gateway group sticky sessions

### PPPoE Full Stack (000055-000060)
PPPoE client+server tables (000055), PPPoE redial cron seed (000056), RADIUS auth (000057), RADIUS accounting (000058), EAP-TLS (000059), backoff + session history (000060, 16,614 bytes)

### Latest Features (000070-000077)
Interface virtual+traffic params (000070), DNS domain overrides (000071a), IDS/IPS tables (000071b, 15,314 bytes), enhanced webfilter (000072, 28,055 bytes), RADIUS/LDAP auth tables (000073, 12,488 bytes), DoS policies (000074), firewall schedules (000075), schedule enabled flag (000076), DoS policy schedule linkage (000077)

### Routing Policy & Service Inventory (000078-000081)
FRR policy tables — `frr_prefix_lists`, `frr_route_maps` (000078); OSPF filter tables — `frr_ospf_distribute_lists`, `frr_ospf_area_filters` (000079); RIP & IS-IS tables — `frr_rip_*`, `frr_isis_*` (000080); and the idempotent seed of the IPsec/SNMP/SIEM page daemons (`strongswan`, `snmpd`, `rsyslog`) into `system_services` (000081).

### Antivirus Inspection (000082)
C-ICAP/ClamAV inspection: the `icap_*` and `clamav_*` columns on `webfilter_settings`, the `webfilter_av_detections` log table, and the `clamav` / `c-icap` daemon seed.

### Inline IPS Queue Controls (000083)
`ids_settings.nfqueue_num` (the Netfilter queue Suricata reads) and `ids_settings.queue_fail_mode` (`open` adds the `bypass` flag, `closed` fails closed).

---

## 6. IPC / RPC Command Reference

The `RpcHandler` in `micrond` handles these JSON-RPC 2.0 methods:

| RPC Method | Description |
| :--- | :--- |
| `firewall.apply` | Apply compiled nftables ruleset atomically |
| `firewall.flush` | Flush all nftables rules |
| `firewall.list` | Return current live ruleset |
| `routing.static.apply` | Apply static routes via `ip route` |
| `routing.frr.apply` | Write FRR config + reload via `vtysh` |
| `routing.fib.get` | Read kernel FIB via `ip route show` |
| `wireguard.apply` | Write wg config + `wg-quick down/up` |
| `wireguard.peer.reload` | Atomic `wg set` (no full restart) |
| `wireguard.status` | Parse `wg show all dump` |
| `openvpn.apply` | Write ovpn config + systemd restart |
| `openvpn.status` | Parse management socket status |
| `ipsec.apply` | Write swanctl.conf + `swanctl --load-all` |
| `ipsec.reload` | `swanctl --reload-settings` (vici, no restart) |
| `pppoe.apply` | Write pppd/pppoe-server config |
| `pppoe.status` | Parse active PPPoE sessions |
| `pppoe.redial` | Kill + redial PPPoE client connection |
| `pppoe.radius.status` | RADIUS server connectivity test |
| `system.info` | CPU, RAM, disk, uptime metrics |
| `system.interfaces` | Interface list + status + counters |
| `daemon.action` | systemd start/stop/restart/enable/disable |
| `system.service` | Start/stop/restart a systemd unit, returning the resulting run-state (used by the per-page `Services::control` widget) |
| `service.restart` | Restart a single subsystem daemon (Dashboard `CommandDispatcher` path) |
| `diag.ping` | ICMP echo; validates the host, protocol, source, count and interval before it builds argv |
| `diag.traceroute` | Path trace; validates the host, protocol, source, hop count and the reverse-lookup / ICMP switches |
| `diag.ping_start` | Start a streamed ICMP echo the page polls, line-buffered so each reply arrives as it lands |
| `diag.traceroute_start` | Start a streamed path trace the page polls, so each hop arrives as it is found |
| `diag.stream_poll` | A streamed probe's output since the previous poll |
| `diag.stream_stop` | Terminate a streamed probe and return its tail |
| `diag.dns_lookup` | `dig` query; validates the name, record type and server |
| `diag.tcpdump` | One-shot, bounded packet capture |
| `diag.capture_start` | Compile the BPF filter with `tcpdump -d`, then start a detached live capture |
| `diag.capture_poll` | The live capture's output since the previous poll |
| `diag.capture_stop` | Terminate a live capture and return its tail |
| `diag.port_probe` | TCP handshake / UDP datagram probe of a host and port, with a bounded timeout |
| `diag.dns_benchmark` | A real `dig` query timed against each resolver in a fixed set |
| `diag.route_lookup` | `ip route get` for a target |
| `diag.firewall_logs` | Recent `MICRON-FW:` kernel log events |
| `diag.conntrack_states` | Parsed `conntrack -L -o extended`, each flow attributed to the interface it runs over (the raw device; the controller shows it under the interface's configured description) |
| `diag.routing_table` | Parsed `ip -4 route show` |
| `diag.ndp_table` | Parsed `ip -6 neigh show` |

---

## 7. CLI Commands (spark)

| Command | File | Description |
| :--- | :--- | :--- |
| `spark backup:create` | `app/Commands/BackupCreate.php` | Atomic configuration backup archive (also the pre-update snapshot) |
| `spark firewall:compile` | `app/Commands/FirewallCompile.php` | Compile DB firewall & NAT rules into nftables syntax |
| `spark micron:verify` | `app/Commands/VerifySystem.php` | Verify all compilers, daemons and engines |
| `spark micron:migrate-resync` | `app/Commands/MigrateResync.php` | Repoint migration-history rows at renamed migration classes |
| `spark micron:sync-services` | `app/Commands/SyncServices.php` | Download public prefix / geography feeds for the Internet Service Database |
| `spark micron:resolve-objects` | `app/Commands/ResolveObjects.php` | Resolve FQDN / internet-service / geography objects into an offline CIDR list |
| `spark micron:pppoe-redial` | `app/Commands/PppoeRedial.php` | Redial down PPPoE links with escalating backoff |
| `spark user:reset-admin` | `app/Commands/UserResetAdmin.php` | Reset the superadmin (Ministrator) password |
| `spark micron:cron` | via Cron controller | Run all due cron jobs |
| `spark db:migrate` / `db:seed` | CodeIgniter built-in | Run pending migrations / seeders |

**PPPoE redial system crontab** — the command name is `micron:pppoe-redial` (the seeded cron job and the Cron UI both use it):
```cron
* * * * * cd /usr/local/micron && /usr/bin/php spark micron:pppoe-redial >> /var/log/micron/redial.log 2>&1
```

### 7.1 Appliance Lifecycle Scripts (non-spark)

These ship in `installer/` and run outside CodeIgniter — during installation and early boot.

| Script | Installed as | Purpose |
| :--- | :--- | :--- |
| `installer/install.sh` | live medium | Partitions `ESP` + swap + `ROOT_A` + `ROOT_B` + `DATA`, formats every slot, writes `fstab`, installs the GRUB slot selector, seeds the slot environment and enables the health check. |
| `installer/micron-slot` | `/usr/local/micron/installer/micron-slot` | Reads/writes GRUB's environment block (`grubenv`) — `active`, `activate`, `mark-ok`, `mark-failed`, `fail-count`, `init`, `--self-test`. No `grub-editenv` dependency, so the installer can seed the block before GRUB exists in the target. |
| `installer/micron-healthcheck` | `/usr/local/micron/installer/micron-healthcheck` | Runs once per boot; the **only** code that may mark a slot good. Probes management plane, micrond IPC, nftables ruleset and the persistent data mount; counts failures and abandons a slot after `MICRON_MAX_FAILS` (default 3) attempts. |
| `installer/micron-healthcheck.service` | `/etc/systemd/system/micron-healthcheck.service` | Wires the health check into boot, ordered after `apache2`/`php8.3-fpm`/`micrond`. |
| `installer/grub.d/09_micron_slots` | `/etc/grub.d/09_micron_slots` | Emits the A/B selector into `grub.cfg`. Sorts before `10_linux`, so its menuentry is the default; performs no arithmetic (counters live in the health check). |

See the [Update & Upgrade Mechanism Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html) for the slot contract and the phases that consume it.

---

## 8. Test Suite Overview

107 test classes — **1,130 test methods / 5,679 assertions**, all green under `vendor/bin/phpunit --no-coverage`.

The installer's A/B slot layout is covered by a shell acceptance suite, `tests/installer/phase0_slot_b_test.sh` (49 assertions), which runs on appliance hardware and in CI as well as through `Phase0SlotBTest` in this suite — see §7.1.

The strict-primitive guard is wired into the same run. `tests/test_strict_decoding_guard.py` parses each Python harness and the `system/micrond/` data plane with `ast` and rejects lenient decoding, raw crypto and shell primitives, and `StrictDecodingGuardTest` executes it from PHPUnit — so a lenient decoder fails the build instead of landing unnoticed. It is a hard gate: with no `python3`/`python` interpreter on `PATH` the test fails rather than skips.

The object picker's own script is driven by a Node DOM harness, `tests/js/picker_harness.mjs`, which `PickerDomHarnessTest` executes from the same run. It reads the inline `<script>` out of `app/Views/partials/picker_search.php` — never a copy, so the shipped code cannot rot behind a passing fixture — gives it a hand-written DOM just large enough for a `<select multiple>`, and drives it against synthetic catalogues: filtering over text, value and group heading, the sentinel that survives every query, the keyboard contract (↑/↓, Space only once armed, Enter adding without clearing, Escape clearing the query, and the keys swallowed so they cannot submit the dialog), `change` as the one event a page syncs from, and the server-backed path — the debounced request's parameters, paging on scroll and on walking off the end of the loaded rows, a picked value resolved back into the list under a `Selected` heading, and a failed request keeping the values the rule carries. That failure degrades the box rather than disabling it: the rows already on screen answer the query at once, picked rows stay visible through it, the note says the search is unavailable and which rows are being filtered (never that the object is not there), and the first answer that arrives — or the next dialog opened — puts the whole catalogue back. The harness drives all of it: an outage it can switch on and off, so a box that degrades *and recovers* is told apart from one that is merely patched up. The same path is then driven over a transport the harness holds in its hand — answers and connection drops released in whatever order the check needs, rather than a sleep and a hope — which is the only way "the older answer arrives last" and "the drop surfaces after the next query was answered" become assertions instead of races. What that pins is the picker's ordering: a request the operator has typed over is abandoned, and an answer to it is dropped on sight even when the transport ignores the abort, when it reports the caller's own abort as a bare connection error, or when the browser has no `AbortController` to abort with at all; a failure from a request nobody is waiting for any more does not put an outage note over a list the server has since answered; a superseded answer does not release the paging gate while the request that replaced it is still in flight, which would otherwise let one scroll abort the query the operator is waiting on; and a reopened dialog draws the page the server rendered however late the closed dialog's answer turns up. What follows a drop is then its own contract: a failed search is retried on a widening delay — one second, doubling to a fifteen-second cap — instead of on every keystroke, so a long outage costs one failing request per window rather than one per character typed, and the attempt that is due asks for whatever query the box holds when it runs, so nothing the operator types while waiting is lost; the note under the box is the connection-lost indicator, naming what failed, whether the query matched any of the rows still in the box, and when the next attempt is due, and it is rebuilt as the operator keeps typing so a query that happens to match cannot hide it; paging is suspended while the box is degraded; a dialog reopened in the meantime starts from zero rather than inheriting the wait; and an answer clears the count, so the next outage is retried at a second again. A box the page left as a local list is covered both ways round: one that holds the whole catalogue stays local — no request, no debounce — and one the markup reports as a single page of a bigger catalogue adopts the endpoint the markup declares at load and pages from there, so objects past the first page stay reachable and a saved rule's off-page values are resolved instead of dropped. A truncated box that *cannot* be adopted — the markup named no endpoint, or named one without a kind — stays local, and the harness pins that it says so in one console line naming the box, what it is showing against the whole catalogue, and the argument `partials/pick_select.php` was not given, so a screen that strands objects leaves a trace for whoever wrote it instead of a box that answers "no match" for objects that are plainly there. It also asserts a filtering time budget over a synthetic 5,000-row catalogue: that a keystroke fits in one 60 Hz frame, and that it stays within a few passes over the catalogue — measured against a reference pass on the same machine in the same run, so the budget means the same thing in CI as it does locally. Like the strict-primitive guard it is a hard gate: with no `node` on `PATH` the test fails rather than skips.

The catalogue the box searches is kept in the browser as well, so a reload into a dead link is not a dialog whose one query fails and whose list is the single page the markup happened to carry — the rows the appliance has already named stay searchable while it cannot be reached. Storing what the page rendered costs nothing and happens on every load, which is also what keeps the record's age honest, and it is why the walk of the pages behind the rendered one is decided *before* that write: a record that stamped itself fresh on every load would never be walked at all. When the record is missing or older than six hours the box fetches up to three further pages in the background, once per catalogue per load — the guard is a local of `init`, so it cannot outlive the load that made it, and two pickers over the same objects share the one walk even when the store refuses what the walk brought, as an exhausted quota does. The checks drive each rule that could be removed without the page looking broken: the version and the age a record must carry before it is believed (an object deleted weeks ago must not come back into a policy because a browser remembered it), the row cap that stops a picker's copy becoming a copy of the database, the smaller write when the browser refuses the record outright, and the browser that stores nothing at all, where the box must behave exactly as it did before any of this existed. What the operator sees is the note under a failed search saying that the rows it is filtering are cached, and how old they are: "no match among these" reads very differently when "these" is what the browser remembers rather than what the appliance just said. The source-level half of the contract is pinned in `ObjectPickerSearchTest`, including that ordering — the walk's decision against the load's own write, and the seeded rows against the query they answer.

The appliance's live screens used to own their polling and their AJAX one page at a time: a dashboard sampler, a diagnostics tail, a conntrack table, a row of service LEDs, SIEM, SNMP and the web-proxy log each grew a `setInterval` and a quiet `console.error`, so an appliance that went away for a minute failed N times, at N moments, with N different (or no) messages, and none of them slowed down while it was gone. They share one connection now. `public/assets/js/micronos-scheduler.js` is loaded synchronously by the layout — before any page script that starts a poller at parse time — and the layout renders the one banner it paints (`#mnoConnBanner`), styled from the design system. A page hands its recurring work to `MicronScheduler.poll(name, { interval, run })` and its one-shot AJAX to `MicronScheduler.fetch(url, options)`; the first request that cannot reach the appliance turns the shared state off-line, shows the banner, and starts backing every poll off — the first retry after the poll's own interval, then doubling to a thirty-second ceiling, with a bounded ±15% jitter so a page full of pollers does not retry in lockstep — while the browser's own `online`/`offline` signals and the tab's visibility feed the same state, and the first request that gets through again (a poll's own attempt, a button, a picker search) clears it and wakes every poll at once. A poller reports a failed attempt by resolving `false` or rejecting and an idle one (a paused stream) by resolving normally, which is what lets a deliberate pause and a dead link be told apart; the scheduler already holds every poll while the tab is hidden and resumes on `visibilitychange`, so a widget needs neither its own timer nor its own visibility handler. The object picker keeps its in-dialog note but reports through `report()` and takes its retry delay from `backoffDelay()`, so the banner and the box tell the same story about the same connection. The behaviour is driven in Node by `tests/js/scheduler_harness.mjs` — its own clock, DOM and transport, with `Math.random` injected so the jitter is checked at both ends of its band rather than averaged away — and made load-bearing by `SchedulerDomHarnessTest`; the wiring is pinned by `RequestSchedulerTest`: that the module ships and stays exposed, that each page's poller registers with the scheduler and fetches through it and keeps no `setInterval` of its own, that the layout renders exactly one banner, and that no view reaches for a bare `fetch`. The scheduler script is a third versioned asset tree under `public/assets/` (`js/`, beside `fonts/` and `vendor/`), so the offline-asset contract — the repository's trees, `stage-overlay.sh`, `public/.htaccess`, the shipped nginx block in the ISO guide, and `assets/(fonts|js|vendor)/` in the immutable-cache scope — carries it too.

The lldpd plugin page's neighbour view is driven the same way. `tests/js/lldpd_neighbours_harness.mjs`, made load-bearing by `LldpdNeighboursHarnessTest`, reads the inline `<script>` out of `app/Views/plugins/apps/lldpd.php` and drives the structured neighbour table the daemon returns for `plugin.lldpd.neighbours` with `format=json`: that each neighbour becomes a row, that a column click sorts one way and then the other, that a TTL is compared as a number rather than as text (`95`, `120`, `1200` — not `120`, `1200`, `95`), that a blank management address stays last in either direction, and that the sorted column marks itself for assistive tech. It then drives the fall back: when the daemon refuses the structured read, or answers with no neighbours, the page shows the listing exactly as `lldpcli` prints it and names why, the toggle moves between the table and the listing, and a chassis name that arrives over the wire looking like markup is rendered as text. Like the picker and scheduler harnesses it is a hard gate — with no `node` on `PATH` the test fails rather than skips.

The **Diagnostics → States** table is driven the same way. `tests/js/states_harness.mjs`, made load-bearing by `StatesDomHarnessTest`, reads the inline `<script>` out of `app/Views/diag/states.php` and drives the table the page draws from `diag/states/data`: that a state whose interface resolved renders that name (`WAN`) while an unassigned port keeps its raw device name, that a state whose interface is blank or missing renders the em-dash fallback rather than `undefined`, that the fetched answer becomes one row per state with the count badge beside it, and that a search matches the resolved interface name. The harness gives the page a `document` whose nodes really nest, so it drives the table it actually built: the row is the ten cells the header declares, the protocol and state cells hold the badges that style them, and a re-render is a swap of the body rather than rows piling up behind it. That structure is what makes the escaping structural rather than a step to remember — every cell is a node whose content is set as *text*, so a value off the wire that looks like markup is pinned as the cell's text with no element children, an address that reaches an attribute is pinned as absent, and no cell in the table may be built from an HTML string at all (`escapeHtml` and the string assembly it served are gone). The kill button is pinned by clicking it: it is a node in the row's last cell with no `onclick` attribute, its handler closes over its own row, and the harness reads the addresses the request carried — so a quote in an address arrives as data, which no amount of escaping inside an attribute could have guaranteed. Like the other DOM harnesses it is a hard gate — with no `node` on `PATH` the test fails rather than skips.

The **Diagnostic Terminal** the ping and traceroute tools share is driven the same way. `tests/js/diagnostics_terminal_harness.mjs`, made load-bearing by `DiagnosticsTerminalHarnessTest`, reads the inline `<script>` out of `app/Views/diag/index.php`, gives it a hand-written `document`, a `FormData` that records what each request carried and a `MicronScheduler` whose answers it queues, and drives the streamed probe runner end to end. The ping and traceroute forms are posted to their own start endpoints with the fields the controller validates (including the two traceroute checkboxes), the command the daemon ran and its first output land on the terminal, and the start registers the `diag.probe` poll at its 400 ms interval. Each poll is then driven by hand: it reads `diag/stream` for that session and *appends* only the bytes it brought — the harness counts a line to tell an append from a replace — while an empty chunk adds nothing at all; the answer that reports the run finished completes the probe, stops the poll and puts the stop button away; and the next hash out of a response is pinned as the one the *next* request sends, which is what stops every probe after the first from being refused 403 now that CSRF regeneration is on and the cookie is `HttpOnly`. The three ways a probe can fail are told apart, because they are three different things to show: a daemon that turns the probe down, an HTML error page out of the permission filter (reported as a refusal rather than parsed as an answer), and a transport that never answered. During the stream, a poll the appliance *refuses* ends the probe quietly — without dumping the error into the console — whereas a poll whose request failed resolves `false`, the shared scheduler's signal to back off, and leaves the probe running with its stop button up. Finally the one-terminal contract and the console controls: a probe started over a live packet capture (or over another probe) closes what was running first, and clearing the console ends the stream and resets the status badge to idle. Like the other DOM harnesses it is a hard gate — with no `node` on `PATH` the test fails rather than skips.

The three tables that still assembled their rows as HTML strings are driven the same way, and now build them as nodes instead. `tests/js/siem_events_harness.mjs`, made load-bearing by `SiemEventsHarnessTest`, drives the SIEM event stream: one row per event, the action in the badge classed for it (`PASS`/`LOGIN` green, `ALERT`/`DROP` red, `AUTH` purple, anything else on the cyan chip), the delivery status in its badge beside the check mark, the source and destination in one cell, and the Inspect button clicked like an operator would click it — which opens the modal on that row's own event. `tests/js/cron_logs_harness.mjs` (`CronLogsHarnessTest`) drives the scheduler's execution history: one row per run, the user and the status in their badges, the command both as its cell's text and as that cell's `title` — the attribute set through the DOM, so a command that would break out of a markup attribute is nothing but its value — and the Inspect Output button, which used to JSON-encode the run's output into an `onclick` attribute, opening the modal on that run's own output. `tests/js/snmp_logs_harness.mjs` (`SnmpLogsHarnessTest`) drives the agent's log list: one `.log-row` per entry, the level in the chip classed for it (a trap ships as `TRAP-OUT` and shows as `TRAP`, and an unknown level — including one named like an object key, which the chip table's own-key lookup is pinned against — takes the plain chip), and the filter box narrowing the same list through the same builder. In all three the escaping is now structural: every field is a node's text, so the SIEM action, the SNMP level and the cron run's output — each of which was concatenated straight into markup before — can only ever be text; the div-based `escapeHtml` those views carried is gone with them, and the harnesses assert that no node in the table is built from an HTML string at all. Curiously, the row builders' first job was to stop *reaching* for markup: the SIEM action and the SNMP level were simply never escaped, and the cron button put a command's output inside an attribute. Like the other DOM harnesses they are hard gates — with no `node` on `PATH` the tests fail rather than skip.

**Those four tables now share the builder that made their escaping structural.** Each page had its own loop, and each loop escaped the fields it remembered to escape — which is how a page ends up with a rule nobody can see: the escaping was correct for every field except the one that was forgotten, and there was no way to tell the difference by reading the page. `public/assets/js/micronos-table.js` is the one place a row is assembled, loaded synchronously by the layout beside the scheduler. A page hands it a container, its rows and a column spec — a `text` column names a field of the row (or a function of it) and is set as that cell's *text*, a `node` column returns an element the page built — and gets rows back, swapped in at once so a re-render can neither leave rows behind nor show a half-built table. There is no third kind of column: a spec that names `html`, `innerHTML`, `markup` or `rawHtml` is **refused** rather than ignored, so the way to put a wire value into a cell is a way that cannot interpret it, and a page can get a cell's *content* wrong but cannot make markup out of data. `MicronTable.badge` and `MicronTable.button` cover what every screen otherwise re-derives: a chip whose class and label are set as a node, and a row action whose handler is a listener closed over its own row — so no field of a row is ever written into an attribute on that path, which is the other half of the same promise. The pages keep their column tables declaratively (`STATE_COLUMNS`, `SIEM_COLUMNS`, `CRON_LOG_COLUMNS`, `SNMP_LOG_COLUMNS`), and the per-page `textCell`/`textSpan` helpers and the div-based `escapeHtml` are gone with the loops they served. The contract is driven in Node by `tests/js/table_harness.mjs` — reading the shipped module, on a document whose nodes really nest, with the module loaded through a `window` and no bare `document` in scope, exactly as the layout loads it — and made load-bearing by `TableBuilderHarnessTest`: that a text column's value is the cell's text even when it looks like an element or carries a quote, that a node column's element is the one appended, that a cell is styled and classed as the column asks and carries no attribute the page did not ask for, that a re-render swaps and an empty or absent list draws the one row that says so, that each markup-naming spec (and each spec naming both sources or neither) is refused *before any node is built*, that a badge draws its icon before its label and its label as text, and that a button writes no handler into an attribute while clicking it runs the handler closed over that row. The same test pins what PHP can see and a browser cannot: that the layout loads the module from the head, versioned from its own mtime (so `assets/js/` stays an immutable-versioned tree under both servers, see §6.1), and that the four pages draw their tables through `MicronTable.render` while writing no markup into `innerHTML` from anything but a fixed literal. Sixteen deliberate breaks were re-run against these gates — a text cell built as markup, the markup-naming refusal disabled, an attribute set by `innerHTML`, a badge's icon spliced in as markup, a row action's handler moved into an `onclick` attribute, and each page's badge, chip, title or row action replaced by a plain text cell — and every one failed the harness that owns it, with the page restored byte-identically each time.

The **Diagnostics → Ping** page carries pfSense's own field set — hostname, IP protocol, source address, maximum number of pings, seconds between pings — and `diag.ping` validates every one of them before it builds the command line: a host that reads as an option is refused rather than handed to `ping` as a flag, a source must be an address of the chosen family, and the counts are bounded to 1–10 (a refused request runs nothing at all). The controller validates the same fields first, for a fast and specific error, and reads the daemon's answer out of the IPC envelope's `result` — the ping path used to look for `output` on the envelope itself, so on a real appliance the response always looked absent and the page silently fell back to simulated output. The same envelope read was broken across the rest of the Diagnostics tools — DNS Lookup, Packet Capture, Route Lookup, the Firewall Logs stream, the Connection States table and the Kernel Routing and NDP tables each looked for their payload on the envelope instead of inside `result`, and `micrond` implemented no handler for any of them — so on a live appliance every one of them fell back to simulated output even while the daemon was answering. Each now reads `result`, and `diag.dns_lookup` (`dig`), `diag.tcpdump`, `diag.route_lookup` (`ip route get`), `diag.firewall_logs`, `diag.conntrack_states`, `diag.routing_table` (`ip -4 route show`) and `diag.ndp_table` (`ip -6 neigh show`) are implemented in the daemon under the same validated-argv, never-a-shell contract as ping and traceroute. Two of the tools had no daemon path at all: **Port Probe** and the **DNS Benchmark** ran entirely inside PHP, so on an appliance they were whatever the web process could reach and the benchmark's "RESOLVED" column was a socket timing that never resolved anything. Both now go through the daemon — `diag.port_probe` performs the TCP handshake (or sends one UDP datagram and says so, since UDP has no handshake to report) against a validated host and a bounded port and timeout, and `diag.dns_benchmark` times a real `dig` against each resolver in a set fixed in the daemon, so a resolver that does not answer is reported as `NO ANSWER` rather than as a fast one. Their local implementations remain only as the no-daemon dev fallback. The page also had to be made to work past its first probe: CSRF regeneration is enabled and the CSRF cookie is `HttpOnly`, so the hash the page rendered is spent by the first request and JavaScript cannot read the replacement from the cookie — every Diagnostics JSON response now hands the next hash back in the body (the same shape the plugin pages use) and the page adopts it, where before anything but the first tool run on a page was refused 403.

The **Diagnostics → Traceroute** page is rebuilt to the same contract and pfSense's own field set — hostname, IP protocol, source address, maximum number of hops (1–64, 18 by default), a **Reverse Address Lookup** box and a **Use ICMP** box, with pfSense's hints verbatim. The command line follows the two checkboxes exactly rather than a fixed flag set: because Reverse Address Lookup is off by default, `-n` (which *suppresses* the PTR lookups) is emitted precisely when the box is clear, `-I` only when Use ICMP is checked, the hop ceiling only as `-m <hops>`, and a source address — its select opening on `Any`, meaning none — becomes `-s <addr>`; IPv6 runs `traceroute6`. The handler had inherited the same envelope misread as ping (it read `output` off the containing envelope instead of out of `result`) on top of hardcoded `traceroute -n -m 15` against an unvalidated host, so on a real appliance it too always fell back to simulated output; `diag.traceroute` now validates the target, the source family and the hop count before it builds argv, and a refused request runs nothing at all.

**Ping and Traceroute now stream.** A ping prints a line per reply and a traceroute a line per hop, and either can run for a minute or more, so neither should make the page wait for the whole command: both are sessions in the same shape as the live capture. `POST /diag/ping/start` and `POST /diag/traceroute/start` validate exactly the field sets the one-shot forms do — both forms share a single argv builder in the daemon, so they cannot diverge — and then hand back a 16-hex session id; the daemon runs the command detached as `timeout 300 stdbuf -oL -eL <command>`, so a probe cannot run past five minutes and each line is flushed to the per-session file as it is printed rather than block-buffered into one lump at the end. The page polls `GET /diag/stream?session=` — at most four live sessions, separate from the capture's four — and appends only the bytes produced since its previous poll into the shared Diagnostic Terminal, so replies and hops appear as they arrive, and `POST /diag/stream/stop` ends a run early and returns its tail. A probe that fails and prints nothing at all (which `ping` does when a name does not resolve) is reported as `Host "…" did not respond or could not be resolved.` rather than as an empty box, exactly as the one-shot handler says it. The one-shot `POST /diag/ping` and `POST /diag/traceroute` remain for callers that want the whole run in a single answer, and the one stop button on the terminal ends whichever session is running, probe or capture.

**Source Address selects.** Ping and Traceroute each carry a Source Address select, and both draw their candidates from the one list the controller resolves for the request (`probeSourceAddresses()` — each configured interface's live address when the daemon reports one, otherwise its configured address, with interfaces that hold no address left out). The two selects are worded exactly as pfSense words its own, which means they deliberately differ: pfSense's `diag_ping.php` labels the field `*Source address` with its default `Automatically selected (default)` under the empty key, while `diag_traceroute.php` labels it `*Source Address` with its default `Any` under the `any` key — each with its own help sentence ("…for the ping." / "…for the trace."). MicronOS reproduces both verbatim (dropping only pfSense's required-field asterisk, which no field on these pages carries) and normalises the submitted value — `any` becomes no source flag — so the two defaults mean the same thing to the daemon. `DiagnosticsSourceAddressTest` pins the pair, including that they share one candidate list, so neither select can drift from the page it is copied from.

**The States table's Interface column is resolved, not guessed.** `conntrack` records no interface for a flow, so `diag.conntrack_states` reads the appliance's own addresses once per request (`ip -o addr show`, parsed into address → interface) and attributes each flow to the interface that owns one of its addresses — the appliance's own endpoint, or the address the flow was NATed onto, taking the original direction before the reply so a flow the box SNATed is attributed to the interface it leaves by. A flow with no address of the appliance's own (plain forwarded traffic) is left blank, and neither the page nor its CSV export paints a device the daemon never reported — the column used to be filled with a hardcoded `eth0`. The device it does report is then shown the way pfSense shows it: the controller maps each state's real port (`eth0`) to that interface's configured description (`WAN`) from the `interfaces` table — the same pairing pfSense builds from `convert_real_interface_to_friendly_interface_name_fast()` and `get_configured_interface_with_descr()` — so the States table reads `WAN`/`LAN` rather than `eth0`/`eth1`, and the CSV and JSON exports carry the same name. A port that is not assigned to any interface keeps its raw device name, exactly pfSense's own fallback (`'interface' => $interfaces[$state['if']] ?? $state['if']`), so an unassigned or virtual interface is never given a label it does not have. That the cell is then built from the resolved value — and that a flow with no interface renders the em-dash fallback rather than `undefined` — is driven in Node by `tests/js/states_harness.mjs` (see §8). The table is built as DOM nodes — each cell's content is set as text, never as markup, so a device name or an address can only ever be text, and the kill button is a node whose handler closes over its own row rather than an inline attribute carrying addresses.

The **Diagnostics → Packet Capture** tab is a live runner rather than a one-shot probe. A capture is a session: the page asks the daemon to `start` one and then polls it, appending the bytes `tcpdump` has produced since the previous poll into the shared Diagnostic Terminal, so frames arrive as they are captured instead of only when the run finishes. The BPF expression is validated twice — the controller checks its shape (length, no control characters, a well-formed interface name and a frame count of 1–50), and `micrond` compiles it with `tcpdump -d` before anything is launched, so a filter that does not parse is refused with tcpdump's own syntax error and nothing runs. The capture itself is `timeout 120 tcpdump -i <iface> -n -l -c <n> <filter>`, started detached in its own session and writing to a per-session file under `/run/micron/capture`; `stop` signals the whole process group so the `timeout` wrapper and tcpdump end together. Four sessions may run at once and the two-minute wrapper is the wall-clock backstop, so a filter that matches nothing cannot leave a capture behind. The three endpoints are gated on the dedicated `diag.packet_capture` permission rather than the ping one.

### Responsive Header Contract

The two shipped layouts share one header on small screens. `data-layout="sidebar"` puts the rail along the left and shows the compact `header.topbar` at every width; `data-layout="topnav"` puts seven groups, the logo, the commit button, the status pills and the sign-out link in a single `nav.topnav-bar` row. That row needs about 1193px before the groups start losing their labels, and the menu is deliberately *not* a scroll container (a scroll container would clip the `.topnav-dropdown` panels that hang below it), so on narrower screens the last groups ran underneath the right-hand controls instead of shrinking. Below **1200px** the topnav layout therefore hands the header to the same compact `topbar` — hamburger, logo, page title, commit, status pills, sign-out — and the rail it hides becomes an off-canvas drawer the hamburger opens, exactly as in the sidebar layout. The CSS breakpoint and the breakpoint the hamburger script uses to decide drawer-versus-collapse are the same number on purpose: the rail is off-canvas wherever the compact header is showing, so a tap on the hamburger always opens the full menu rather than collapsing a rail that is already hidden. `HeaderResponsiveTest` pins that contract — the media query and the script's own check must parse to the same width, the drawer and its `mobile-open` state must exist, and the compact header must still carry the toggle, the breadcrumb, the commit group, the status group and the sign-out link. It also pins the two narrow-bar fixes: the commit group's hide rule is scoped under `.topbar` (the button's `d-flex` utility declares `display: flex` later in the stylesheet and won a bare-selector tie, leaving a dead rule behind), and the controls hold their size while the page title truncates with an ellipsis.

### Controller Tests

| Test Class | Controller | Coverage |
| :--- | :--- | :--- |
| `PppoeLiveFeaturesTest` | Pppoe | Redial dispatch, AJAX session partial, subscriber history |
| `NatCrudTest` | Nat | DNAT/SNAT CRUD operations |
| `WebFilterComprehensiveTest` | WebFilter | Category toggle, feed management, ACL rules |
| `IdsCrudTest` | Ids | IP rules CRUD, GeoIP rules |
| `DosPolicyTest` | DosPolicy | Policy create/update/delete, preset application |
| `ScheduleTest` | Schedule | Schedule CRUD, usage reference counting |
| `InterfacesCrudTest` | Interfaces | Interface create/update |
| `DnsDomainOverridesTest` | Dns | Domain override CRUD |
| `RadiusAndLdapAuthTest` | Auth | LDAP bind, RADIUS challenge/response |
| `DocsTest` | Docs | Docs portal routing |
| `OspfPolicyTest` | Routing | Prefix-lists, route-maps, distribute-lists, area filters (38 cases) |
| `RipIsisTest` | Routing | RIP & IS-IS settings, networks, neighbors, interfaces (25 cases) |
| `ServiceControlTest` | Services | Per-page widget render, `control`/`status` endpoints, polling, log modal, collapse (12 cases) |

### Library Tests

| Test Class | Library | Coverage |
| :--- | :--- | :--- |
| `NftablesCompilerTest` | NftablesCompiler | Full ruleset generation, address sets, NAT chains |
| `PppoeCompilerTest` | PppoeCompiler | Server/client config generation |
| `PppoeRadiusTest` | RadiusAuthService | Primary/secondary failover |
| `PppoeBackoffTest` | PppoeBackoff | Exponential delay calculation, reset |
| `PppoeRedialMockTest` | IpcClient (mock) | Redial dispatch via mock socket |
| `PppoeRedialCommandTest` | PppoeRedial CLI | Command dispatch |
| `MultiWanStickyTest` | Routing | Sticky session gateway selection |
| `PbrCompilerTest` | Routing | PBR rule compilation |
| `UnboundDnsCompilerTest` | UnboundDnsCompiler | DNS config generation |
| `AddressResolverTest` | AddressResolver | FQDN resolution, GeoIP lookup |
| `ResolveObjectsTest` | ResolveObjects | Symbolic object expansion |
| `ServiceDatabaseTest` | ServiceDatabase | Upsert, dedup operations |
| `CronJobLogTest` | CronJobLog | Start/success/failure lifecycle |
| `ObjectTipTest` | ObjectTip | `data-fw-tip` card generation, empty-field skipping, escaping (11 cases) |
| `FqdnInternetServiceTest` | Fqdn / InternetService | FQDN wildcard validation, named cloud IP set lookup |
| `ServiceSourcesTest` | ServiceSources | AWS/Cloudflare/Microsoft/Google feed normalization |
| `RadiusAttributesTest` | RadiusAttributes | Attribute encode/decode round-trips |
| `DnsDomainOverridesTest` | Dns | Split-horizon domain override CRUD |

### Model Tests

| Test Class | Model | Coverage |
| :--- | :--- | :--- |
| `PermissionModelSuperAdminTest` | PermissionModel | SuperAdmin all-permissions grant |
| `PppoeSessionHistoryModelTest` | PppoeSessionHistoryModel | Session record insert/query |

---

*Last updated: October 2026 — MicronOS Development Build*
# Micron Firewall OS (MicronOS)
## Comprehensive Technical Architecture & Engineering Specification
### Enterprise Linux Network Security Appliance, Next-Gen Web Filter & Routing Platform

---

## Table of Contents
1. [Executive Summary & Core Philosophy](#1-executive-summary--core-philosophy)
2. [Component Comparison: Legacy BSD Firewalls vs. MicronOS (Debian Linux)](#2-component-comparison-legacy-bsd-firewalls-vs-micronos-debian-linux)
3. [Strict Control Plane vs. Data Plane Decoupling](#3-strict-control-plane-vs-data-plane-decoupling)
4. [Dual Database Architecture: SQLite 3 & MySQL/MariaDB](#4-dual-database-architecture-sqlite-3--mysqlmariadb)
5. [Privilege Separation & Secure IPC Daemon (`micrond`)](#5-privilege-separation--secure-ipc-daemon-micrond)
6. [Transactional Commit Engine](#6-transactional-commit-engine)
7. [High-Performance Linux Kernel Networking & Acceleration](#7-high-performance-linux-kernel-networking--acceleration)
8. [Enterprise Identity, Access Management & Role-Based Access Control (RBAC)](#8-enterprise-identity-access-management--role-based-access-control-rbac)
9. [Road Warrior Remote Access VPN with Multi-Factor Authentication (2FA)](#9-road-warrior-remote-access-vpn-with-multi-factor-authentication-2fa)
10. [Next-Gen Web Filtering & Proxy Engine (Squid, TPROXY, C-ICAP, ClamAV)](#10-next-gen-web-filtering--proxy-engine-squid-tproxy-c-icap-clamav)
11. [Dynamic Enterprise Routing & Multi-WAN (FRRouting, BGP, OSPF, BFD, VRF, PBR)](#11-dynamic-enterprise-routing--multi-wan-frrouting-bgp-ospf-bfd-vrf-pbr)
12. [WAN Access & PPPoE Subscriber Management (PPPoE Client, BRAS, RADIUS, EAP-TLS)](#12-wan-access--pppoe-subscriber-management-pppoe-client-bras-radius-eap-tls)
13. [Internet Service Database & Offline Address Resolution (FQDN, GeoIP, Threat Feeds)](#13-internet-service-database--offline-address-resolution-fqdn-geoip-threat-feeds)
14. [High Availability & Stateful Cluster Failover (Keepalived & conntrackd)](#14-high-availability--stateful-cluster-failover-keepalived--conntrackd)
15. [Intrusion Detection & Prevention (Suricata IDS/IPS) & DDoS Defense](#15-intrusion-detection--prevention-suricata-idsips--ddos-defense)
16. [Traffic Shaping & QoS Bufferbloat Elimination (CAKE / fq_codel)](#16-traffic-shaping--qos-bufferbloat-elimination-cake--fq_codel)
17. [Configuration Backup & Restore Engine (Encrypted & Plain Mechanism)](#17-configuration-backup--restore-engine-encrypted--plain-mechanism)
18. [Unified Database Schema (Complete DDL for SQLite & MySQL)](#18-unified-database-schema-complete-ddl-for-sqlite--mysql)
19. [CodeIgniter 4 Management Plane Architecture & CLI (`microctl`)](#19-codeigniter-4-management-plane-architecture--cli-microctl)
20. [Appliance Lifecycle, Dual A/B Root Partitions & Hardware Watchdog](#20-appliance-lifecycle-dual-ab-root-partitions--hardware-watchdog)
21. [System Services Lifecycle & Daemon Watchdog Manager](#21-system-services-lifecycle--daemon-watchdog-manager)
22. [Cron Job Scheduler & Automated Maintenance Engine](#22-cron-job-scheduler--automated-maintenance-engine)
23. [Enterprise Network Address Translation (NAT) & Hairpin Engine](#23-enterprise-network-address-translation-nat--hairpin-engine)
24. [SNMP Agent & Enterprise Network Monitoring (v1/v2c/v3 & Traps)](#24-snmp-agent--enterprise-network-monitoring-v1v2cv3--traps)
25. [SIEM Integration & Real-Time Event Log Shipping (CEF, LEEF, JSON ECS)](#25-siem-integration--real-time-event-log-shipping-cef-leef-json-ecs)
26. [Detailed Phased Implementation Roadmap](#26-detailed-phased-implementation-roadmap)

---

## 1. Executive Summary & Core Philosophy

**Micron Firewall OS (MicronOS)** is an enterprise-grade, bare-metal and virtualized network security operating system. It combines the raw throughput and hardware driver compatibility of the **Linux kernel (Debian Minimal base)** with a modern, responsive web orchestration plane developed in **CodeIgniter 4 (PHP 8.3+)**.

The core premise of MicronOS is simple: **The user should never need to touch the Linux command-line shell after deployment**, yet the underlying engine must deliver carrier-grade packet routing, wire-speed VPNs, transparent web proxying, and banking-grade security isolation.

### Architectural Tenets
1. **Linux Kernel as the Data Plane:** Linux provides state-of-the-art packet processing primitives (`nftables`, eBPF/XDP, kernel WireGuard, `conntrackd`, and FRRouting) that significantly outperform legacy BSD network stacks in multi-threaded CPU scaling and 10GbE/40GbE/100GbE throughput.
2. **Zero Root Privilege in Web Engine:** Web application processes (Apache 2.4, PHP 8.4-FPM) run strictly as unprivileged service accounts (`www-data`). Under no circumstances does PHP execute raw `sudo` commands or direct shell pipelines.
3. **Database as Single Source of Truth:** System state resides in a structured, transactional database (SQLite or MySQL). Linux configuration files (`/etc/nftables.conf`, `/etc/frr/frr.conf`, `/etc/squid/squid.conf`) are transient compilation targets generated deterministically by the system compiler.
4. **Safety Against Remote Lockout:** Every routing, interface, and firewall modification follows a transactional candidate model featuring automated "Commit Watchdog" rollback timers.

### 1.1 Software Semantic Versioning & Release Channels
Micron OS enforces a strict 3-digit semantic versioning and stability channel convention:

$$\mathbf{MAJOR}.\mathbf{MINOR}.\mathbf{PATCH}\quad (\text{e.g. } 2.4.56, 0.1.23, 2.2.11)$$

- **Minor Version (2nd Digit) — Release Channel Rule:**
  - **Odd number (`1, 3, 5, ...`)** $\rightarrow$ **Development Version** (e.g. `0.1.23`, `1.1.24`, `2.3.56`). Indicates active engineering, pre-release staging, and continuous testing.
  - **Even number (`0, 2, 4, ...`)** $\rightarrow$ **Stable Version** (e.g. `0.2.0`, `2.2.11`, `2.4.12`). Indicates production-hardened releases and enterprise LTS stability.
- **Patch Version (3rd Digit) — Incremental Edits:** Represents incremental edits, builds, or configuration revisions performed on the project (`.56`, `.12`, `.11`, `.24`, `.23`). Incremented automatically by `AppVersion::incrementPatch()` upon confirmed Git configuration commits.
- **Project Starting Baseline:** Official starting baseline is **`0.1.23`** (Development version).
- **Official Naming Standard:** Strictly **"Micron OS"** (with space), not "MicronOS".
- **Reference Specification:** See [Semantic Versioning & Release Lifecycle Specification](MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION.html).

---

## 2. Component Comparison: Legacy BSD Firewalls vs. MicronOS (Debian Linux)

| Functional Subsystem | Legacy BSD Firewall Stack (FreeBSD) | Micron Firewall OS (Debian Linux) | Technical Rationale & Micron Advantage |
| :--- | :--- | :--- | :--- |
| **Base Operating System** | FreeBSD 14 / 15 | Debian 13 (Trixie) Minimal | Superior hardware driver support, broader NIC offload features, kernel upgrades without system breakage. |
| **Packet Filtering Engine** | `pf` (Packet Filter) | `nftables` (with Flowtable Offload) | Unified syntax for IPv4/IPv6/ARP/Bridge, atomic batch rule updates, atomic set lookups, kernel flowtable bypass. |
| **Volumetric DDoS Mitigation** | Software pf tables | **eBPF / XDP (eXpress Data Path)** | Drops malicious packets inside the NIC ring buffer before kernel `sk_buff` allocation, defending against 10M+ pps attacks. |
| **Site-to-Site VPN** | OpenVPN / WireGuard (kmod) / IPsec | **In-Kernel WireGuard + strongSwan** | True multi-core kernel WireGuard with zero context switching; sub-millisecond crypto handshake. |
| **Road Warrior VPN & 2FA** | OpenVPN + Radius / TOTP | **OpenVPN 2FA (Dynamic Challenge) + WireGuard Captive Leases** | Interactive 2FA prompts in client GUI; user self-service provisioning portal for certificates and profiles. |
| **Dynamic Routing Suite** | FRR package (often desynced) | **FRRouting (FRR) Native Integration** | Full support for BGP, OSPFv2/v3, IS-IS, BFD sub-second link monitoring, and VRF route isolation. |
| **High Availability & State Sync** | VRRP + `state-table sync` | **Keepalived (VRRPv2/v3) + `conntrackd`** | Stateful session sync preserves active TCP streams, VoIP SIP calls, and WireGuard tunnels with zero session drop. |
| **Web Filtering & Proxy** | Squid package (maintenance issues) | **Squid 6+ with Linux TPROXY & C-ICAP** | Preserves client source IPs via TPROXY; Peek & Splice TLS SNI filtering without client root certificates; ClamAV stream A/V. |
| **DHCP Server** | ISC DHCP (Deprecated) / Kea | **Kea DHCP 2.6+ (High-Performance Engine)** | Modular JSON-RPC control plane, multi-threaded lease allocation, REST API integration. |
| **DNS Resolver & Sinkhole** | Unbound + ad-block package | **Unbound Resolver + Memory Bloom Filter Sinkhole** | High-performance DNSSEC validation, DoT/DoH upstream encryption, instantaneous million-domain ad/malware sinkhole. |
| **Traffic Shaping (QoS)** | ALTQ / HFSC / FQ-CoDel | **Linux `tc` with CAKE & fq_codel** | CAKE automatically mitigates bufferbloat on variable bandwidth WANs (e.g. 4G/5G, Starlink, GPON) with zero tuning. |
| **Intrusion Detection (IDS/IPS)** | Snort / Suricata | **Suricata 7+ (AF_PACKET / NFQUEUE)** | Native multi-threaded inspection leveraging modern AVX-512 vectorization and eBPF bypass filters. |
| **Backup & Disaster Recovery** | XML flat file export | **Encrypted Envelope (`.mbk.enc`, AES-256-GCM + PBKDF2-SHA256) & Plain (`.mbk` JSON)** | Authenticated tamper-proof encryption, cross-hardware NIC remapping, selective component restoration, connector key policy with post-restore credential verification. (Argon2id derivation and a staged watchdog activation are planned — §17.7.) |
| **WAN Access & Subscriber Termination** | PPPoE client + third-party BRAS package | **Built-in PPPoE Client + PPPoE Access Server (BRAS)** | One box terminates subscriber PPPoE and originates WAN PPPoE; RADIUS auth/accounting and EAP-TLS with no external packages. |
| **Address Objects & Service Lists** | Literal IP aliases, manually refreshed tables | **Internet Service Database + offline FQDN/GeoIP resolution** | Named service and wildcard-FQDN policies keep themselves current; the appliance never depends on live DNS at filter time. |
| **Management Plane Stack** | Custom PHP scripts | **CodeIgniter 4 (PHP 8.3) + Tailwind UI** | MVC architecture, RESTful API, entity modeling, automated DB migrations, robust CSRF/XSS protection. |
| **System State Storage** | Monolithic `config.xml` | **SQLite 3 (WAL) & MySQL 8.4 Dual Strategy** | ACID relational consistency, zero risk of XML parsing corruption, instant indexed lookups, audit logging. |

---

## 3. Strict Control Plane vs. Data Plane Decoupling

In legacy appliances, web management scripts frequently write directly to configuration files on disk and execute restart commands synchronously. A syntax error or crash in the script can leave the entire operating system in a broken, unreachable state.

MicronOS enforces a **strict architectural boundary** between the unprivileged management plane and the privileged kernel data plane:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   MANAGEMENT / CONTROL PLANE                           │
│                                                                        │
│   Web GUI (CI4 + Tailwind/Vanilla CSS)  ◄──►  REST API / CLI (microctl)│
│                             │                                          │
│                             ▼                                          │
│             Configuration Engine & Schema Validator                     │
│                             │                                          │
│            ┌────────────────┴────────────────┐                         │
│            ▼                                 ▼                         │
│   SQLite (Appliance Node)         MySQL / MariaDB (Enterprise/Central) │
│                             │                                          │
│                             ▼                                          │
│                 Privileged Daemon IPC (UDS)                            │
│                  /run/micron/micrond.sock                              │
└─────────────────────────────┬──────────────────────────────────────────┘
                              │
══════════════════════════════╪═══════════════════════════════════════════
                              │
┌─────────────────────────────▼──────────────────────────────────────────┐
│                      LINUX DATA PLANE                                  │
│                                                                        │
│   ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌────────────┐ │
│   │ nftables      │ │ FRRouting     │ │ WireGuard     │ │ Keepalived │ │
│   │ (Filter/NAT)  │ │ (BGP/OSPF)    │ │ (Kernel Mesh) │ │ (VRRP)     │ │
│   └───────────────┘ └───────────────┘ └───────────────┘ └────────────┘ │
│   ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌────────────┐ │
│   │ conntrackd    │ │ Kea DHCP /    │ │ OpenVPN 2FA   │ │ Suricata   │ │
│   │ (State Sync)  │ │ Unbound DNS   │ │ (PAM/TOTP/AD) │ │ (IDS/IPS)  │ │
│   └───────────────┘ └───────────────┘ └───────────────┘ └────────────┘ │
│   ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌────────────┐ │
│   │ Squid 6+ Proxy│ │ Linux TPROXY  │ │ C-ICAP Engine │ │ ClamAV     │ │
│   │ (SNI / SSL)   │ │ (Transp. 443) │ │ (Content A/V) │ │ (Antivirus)│ │
│   └───────────────┘ └───────────────┘ └───────────────┘ └────────────┘ │
│   ┌──────────────────────────────────────────────────────────────────┐ │
│   │         Linux Kernel 6.x+ (eBPF/XDP, Flow Offload, VRF)          │ │
│   └──────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Dual Database Architecture: SQLite 3 & MySQL/MariaDB

MicronOS supports two distinct deployment tiers without code divergence, utilizing CodeIgniter 4's query builder abstraction:

```
                ┌──────────────────────────────────┐
                │   CodeIgniter 4 Database Layer   │
                │     Abstract Model & Entities    │
                └─────────────────┬────────────────┘
                                  │
         ┌────────────────────────┴────────────────────────┐
         ▼                                                 ▼
┌─────────────────────────────────┐       ┌─────────────────────────────────┐
│       Appliance Node Mode       │       │      Enterprise / NOC Mode      │
│            (SQLite 3)           │       │        (MySQL 8.4/MariaDB)      │
├─────────────────────────────────┤       ├─────────────────────────────────┤
│ • /var/lib/micron/config.db     │       │ • Remote or Local Cluster       │
│ • Local system config           │       │ • Central policy distribution   │
│ • Candidate config staging      │       │ • Consolidated audit logs       │
│ • Local interface states        │       │ • NetFlow / IPFIX flow database │
│ • Local WireGuard peer keys     │       │ • Multi-tenant management       │
│ • User 2FA Secrets & Leases     │       │ • Centralized User & LDAP cache │
└─────────────────────────────────┘       └─────────────────────────────────┘
```

---

## 5. Privilege Separation & Secure IPC Daemon (`micrond`)

To prevent web-based vulnerabilities from compromising the root operating system, PHP-FPM executes under unprivileged credentials (`uid 33 / www-data`). The privileged daemon (`micrond`) runs as `root`, listening exclusively on a protected Unix Domain Socket (`/run/micron/micrond.sock`, permissions `0660`, group `www-data`).

```
┌─────────────────────────────────┐
│     Web UI (CodeIgniter 4)      │
│       Runs as: www-data         │
└────────────────┬────────────────┘
                 │
                 │ JSON-RPC via Unix Domain Socket
                 │ Path: /run/micron/micrond.sock
                 ▼
┌─────────────────────────────────┐
│       micrond (Root Daemon)     │
│   • Go / Rust or C Engine       │
│   • Validates JSON Payload      │
│   • Compiles config files       │
│   • Atomically applies state    │
└────────────────┬────────────────┘
                 │
   ┌─────────────┼─────────────┬─────────────┬─────────────┬─────────────┐
   ▼             ▼             ▼             ▼             ▼             ▼
nftables    iproute2      frr (vtysh)    wg-quick      openvpn       squid -k
```

---

## 6. Transactional Commit Engine

The primary hazard in remote network administration is applying a configuration change that severs management connectivity (e.g. erroneous firewall block rule or incorrect WAN IP/gateway).

MicronOS implements the **Commit Watchdog Pattern**:

```
                       ADMIN MODIFIES CONFIGURATION
                                    │
                                    ▼
                        [ STAGE CANDIDATE CONFIG ]
                                    │
                                    ▼
                        [ SYNTAX & COMPILATION TEST ]
                                    │
                       ┌────────────┴────────────┐
                       ▼                         ▼
                  [ FAILED ]                [ PASSED ]
                       │                         │
                 Reject with Error         Apply to Data Plane
                                                 │
                                                 ▼
                                     Start Watchdog Timer (60s)
                                                 │
                               ┌─────────────────┴─────────────────┐
                               ▼                                   ▼
                   [ ADMIN CLICKS CONFIRM ]               [ DEADLINE EXPIRES ]
                               │                                   │
                     Commit State to Main              Restore Previous Working Config
                        (Permanent)                      Reload All Subsystems (Auto-Rollback)
```

---

## 7. High-Performance Linux Kernel Networking & Acceleration

```nftables
table inet filter {
    flowtable ft_fastpath {
        hook ingress priority 0
        devices = { eth0, eth1, eth2, eth3, bond0 }
    }

    chain forward {
        type filter hook forward priority 0; policy drop;

        # Bypass established TCP and UDP streams directly to NIC fast-path
        ip protocol { tcp, udp } flow offload @ft_fastpath
        ip6 nexthdr { tcp, udp } flow offload @ft_fastpath

        # Standard stateful filtering for initial packets
        ct state established,related accept
        ct state invalid drop
    }
}
```

---

## 8. Enterprise Identity, Access Management & Role-Based Access Control (RBAC)

MicronOS implements a **fully dynamic RBAC system** inspired by established firewall privilege managers but with far more granular, database-driven permissions. Unlike hard-coded privilege strings, MicronOS supports **custom role creation** with arbitrary permission combinations — enabling precise least-privilege implementation.

### 8.1 Authentication Architecture

```
                           AUTHENTICATION BROKER
                                     │
           ┌─────────────────────────┼─────────────────────────┐
           ▼                         ▼                         ▼
   Web Admin GUI             OpenVPN Road Warrior      Squid Web Proxy
   (Admins / Operators)      (Remote Teleworkers)      (Captive / Explicit)
           │                         │                         │
           └─────────────────────────┼─────────────────────────┘
                                     │
                       Identity Verification Pipeline
                                     │
         ┌───────────────────────────┴───────────────────────────┐
         ▼                                                       ▼
  Factor 1: Primary Auth                                  Factor 2: Second Factor
  ├── Microsoft Active Directory (LDAPS)                  ├── RFC 6238 TOTP (Google/MS Auth)
  ├── OpenLDAP / FreeIPA                                  ├── FIDO2 / WebAuthn (YubiKey)
  └── Local Encrypted Database (Fallback)                 └── Single-Use Scratch Codes
```

---

### 8.2 Default Superadmin Credentials

| Field    | Value                              |
| :------- | :--------------------------------- |
| Username | `Ministrator`                      |
| Password | `M1Cr0N`                           |
| Role     | `SuperAdmin` (all 90+ permissions) |
| Source   | Local (SQLite database)            |

> **Warning:** The `Ministrator` password must be changed immediately after first login. This account cannot be deleted and cannot have its role downgraded below `SuperAdmin`.

---

### 8.3 RBAC Database Schema (4-Table Normalised Model)

```
users ────── user_roles ──────── roles ──────── role_permissions ──── permissions
(id,          (user_id,          (id,            (role_id,             (id, key,
 username,     role_id,           name,            permission_id,        label,
 password)     assigned_at,       display_name,    granted)              module)
               assigned_by)       is_system)
```

- A user can hold **multiple roles** — effective permissions = UNION of all roles.
- `granted = 0` creates an **explicit deny** that overrides grants from other roles.
- Permissions use **dot-notation keys** (`firewall.rules.edit`) for O(1) programmatic checking.
- Roles with `is_system = 1` cannot be deleted or renamed.

---

### 8.4 Built-In System Roles

| Role Name       | Display Name           | Permissions | Description                                          |
| :-------------- | :--------------------- | :---------: | :--------------------------------------------------- |
| `SuperAdmin`    | Super Administrator    | **All 90+** | Unrestricted access including user/role management   |
| `NetworkAdmin`  | Network Administrator  | ~50         | Full network config; no user/role management         |
| `SecurityAdmin` | Security Administrator | ~41         | Firewall, IDS/IPS, VPN, WebFilter; read-only topology|
| `Operator`      | Operator (Read-Only)   | ~25         | View-only dashboards, logs and status panels         |
| `VpnUser`       | VPN Road Warrior       | 2           | Self-service VPN portal only                         |

---

### 8.5 Permission Catalog (96 Permissions across 18 Modules)

| Module       | Key Examples                                                                       |
| :----------- | :--------------------------------------------------------------------------------- |
| Dashboard    | `dashboard.view`, `dashboard.widgets.edit`                                         |
| Interfaces   | `interfaces.view`, `interfaces.edit`, `interfaces.apply`, `interfaces.vlan.manage` |
| Firewall     | `firewall.rules.view/edit/delete/apply/reorder`, `firewall.schedules.manage`       |
| NAT          | `nat.view/edit/delete/apply`                                                       |
| PPPoE        | `pppoe.server.view`, `pppoe.server.manage`, `pppoe.server.apply`                    |
| Routing      | `routing.table.view`, `routing.frr.edit`, `routing.multiwan.manage`                |
| VPN          | `vpn.wireguard.manage`, `vpn.wireguard.peer.selfservice`, `vpn.openvpn.manage`     |
| DHCP         | `dhcp.view/edit/leases.manage/restart`                                             |
| DNS          | `dns.view/edit/override.manage/flush`                                              |
| WebFilter    | `webfilter.policy.edit`, `webfilter.categories.manage`, `webfilter.logs.view`      |
| IDS/IPS      | `ids.view/rules.manage/suppress.manage/restart`                                    |
| QoS          | `qos.view/edit/apply`                                                              |
| HighAvail.   | `ha.view/edit/failover`                                                            |
| Certificates | `cert.view/create/delete/ca.manage`                                                |
| Identity     | `users.*`, `roles.*`, `ldap.view/edit`, `totp.manage`                             |
| Backup       | `backup.view/create/restore/delete/export`                                         |
| System       | `system.reboot/halt/update/factory_reset`, `system.noc.register`                  |
| Diagnostics  | `diag.ping/packet_capture/logs.view/audit.view/arp.view/states.view`              |

---

### 8.6 Custom Role Creation Workflow

Administrators with `roles.create` permission can build custom roles from **Identity → Roles & Permissions**:

1. **Name the Role** — slug + display name + description.
2. **Assign Permissions** — checkbox matrix grouped by module; select-all per module supported.
3. **Assign to Users** — apply immediately after creation.
4. **Audit Trail** — all changes written to `audit_logs`.

Custom roles (`is_system = 0`) can be fully edited or deleted. Deletion auto-removes all user-role assignments.

---

### 8.7 Permission Enforcement Flow

```
Request → AuthFilter (session check) → PermissionFilter (permission key check)
                │                               │
         Not logged in                   Permission missing
                │                               │
         Redirect /auth/login           Web: Redirect /dashboard + flash error
                                        API: HTTP 403 JSON { error: "Forbidden" }

Session caching:
  Login   → compute UNION of role permissions → store in 'user_permissions' session key
  Request → O(1) in_array() check (zero DB queries per request)
  Expire  → on role change, permission sync, or logout
```

---

### 8.8 Enterprise Directory Integration: LDAP & Microsoft Active Directory (AD)

MicronOS provides native, zero-dependency integration with corporate directory services including **Microsoft Active Directory (AD DS)**, **Azure AD DS / Entra ID Domain Services**, **OpenLDAP**, and **FreeIPA**. Configuration is centralized under **System > System Settings > LDAP / Active Directory** (`/system#ldap`).

#### 8.8.1 Directory Schema Profiles & Attribute Mapping
Directory implementations differ in their object classes and membership attributes. MicronOS abstracts these differences using schema presets:

| Attribute / Parameter | Microsoft Active Directory (`ad`) | Standard OpenLDAP / RFC 4519 (`openldap`) | Custom RFC 2307bis / FreeIPA |
| :--- | :--- | :--- | :--- |
| **User Search Filter** | `(&(objectClass=user)(sAMAccountName={username}))` | `(&(objectClass=inetOrgPerson)(uid={username}))` | `(&(objectClass=posixAccount)(uid={username}))` |
| **Group Membership Attr** | `memberOf` (Distinguished Name string) | `member` or `memberUid` | `memberOf` or `member` |
| **Display Name Attribute** | `displayName` or `cn` | `cn` or `gecos` | `displayName` / `cn` |
| **Email Attribute** | `mail` or `userPrincipalName` | `mail` | `mail` |
| **Default Secure Port** | `636` (LDAPS) | `636` (LDAPS) | `636` (LDAPS) |
| **Default StartTLS Port** | `389` (RFC 2830) | `389` (RFC 2830) | `389` (RFC 2830) |

#### 8.8.2 High Availability, Failover & Resiliency
1. **Primary & Secondary Controllers**: Enterprise deployments configure multiple Domain Controllers. If the primary DC does not acknowledge TCP handshakes within the configurable socket timeout (default: 5s), the authentication pipeline automatically fails over to secondary directory endpoints without blocking user sessions.
2. **Local Fallback Defense (`fallback_local = 1`)**: When network segmentation or WAN outages isolate the firewall appliance from remote directory domain controllers, local administrative accounts (such as emergency break-glass superadmins) retain full access. External identity authentication gracefully fails without freezing the WebGUI or SSH daemons.
3. **Encrypted Transport Security**:
   - **LDAPS (`ldaps://`, port 636)**: Complete TLS encapsulation from the initial SYN packet.
   - **StartTLS (`ldap://`, port 389)**: In-band TLS negotiation via RFC 2830 Extended Operation.
   - **Plaintext (`ldap://`, port 389)**: Restricted for isolated lab testing or local loopback instances.

#### 8.8.3 Real-Time Diagnostics & AJAX Directory Browser
Administrators can test directory credentials and search queries interactively via `POST /system/ldap/test` without applying global configuration:
- Validates TCP/TLS connectivity to the target domain controller.
- Executes administrative bind using configured Bind DN and password.
- Resolves the Base DN and counts directory leaf nodes.
- Simulates user resolution and renders retrieved directory attributes (`sAMAccountName`, `dn`, `memberOf`, `mail`).

---

### 8.9 Enterprise RADIUS Authentication & Accounting Architecture (RFC 2865 / RFC 2866)

MicronOS implements carrier-grade **Remote Authentication Dial-In User Service (RADIUS)** client capabilities, conforming to **RFC 2865 (Authentication & Authorization)** and **RFC 2866 (Accounting)**. The RADIUS engine integrates both administrative WebGUI / SSH authentication and network access control (NAC / 802.1X / Captive Portal / VPN). Configuration is managed under **System > System Settings > RADIUS Authentication** (`/system#radius`).

#### 8.9.1 Protocol Mechanics & Packet Exchange Flow
```
User / Admin              MicronOS Appliance (NAS)            RADIUS AAA Server
    │                               │                                 │
    │── 1. Enter Credentials ──────▶│                                 │
    │   (Username & Password)       │── 2. Construct Access-Request ─▶│
    │                               │      - Code: 1 (Access-Request) │
    │                               │      - Identifier & Authenticator
    │                               │      - Attribute 1: User-Name   │
    │                               │      - Attribute 2: User-Pass   │
    │                               │      - Attribute 4: NAS-IP      │
    │                               │      - Attribute 32: NAS-ID     │
    │                               │                                 │
    │                               │◀─ 3. Evaluate Credentials ──────│
    │                               │      Code: 2 (Access-Accept) OR │
    │                               │      Code: 3 (Access-Reject)    │
    │                               │      - Attribute 25: Class      │
    │                               │      - Attribute 11: Filter-Id  │
    │                               │      - Attribute 26: Vendor-AVP │
    │                               │                                 │
    │◀─ 4. Session Established ─────│── 5. Accounting-Request (Start)▶│ (If RFC 2866 enabled)
    │      (Role permissions cached)│◀─ 6. Accounting-Response ───────│
```

#### 8.9.2 Key Parameters & Specifications
- **Authentication Protocols**: Supports **PAP** (Password Authentication Protocol), **CHAP** (Challenge Handshake Authentication Protocol RFC 1994), and **MS-CHAPv2** (Microsoft Challenge Handshake Authentication Protocol v2 RFC 2759).
- **Dual UDP Socket Architecture**: Default authentication port `1812/UDP`, default accounting port `1813/UDP` (with legacy fallback to `1645/UDP` and `1646/UDP`).
- **NAS Identification**: Multi-homed firewall appliances can explicitly designate the `NAS-IP-Address` (e.g., Management Interface IP or Loopback VIP) and `NAS-Identifier` string to comply with RADIUS Network Policy Server (NPS) client policies.
- **Failover & Retry Watchdogs**: Configurable retransmission counts (`retries`, default: 3) and round-trip timeouts (`timeout`, default: 5s). When the primary RADIUS server fails to answer, requests switch instantly to designated secondary servers.
- **Live Packet Verification Tool**: Administrators can execute instantaneous packet validation via `POST /system/radius/test`, sending a synthetic `Access-Request` datagram to confirm shared secret matching, authenticator verification, and attribute retrieval.

---

### 8.10 External Group-to-Role Mapping Engine (`auth_group_mappings`)

To eliminate duplicate account provisioning and ensure strict adherence to corporate **Principle of Least Privilege (PoLP)**, MicronOS features an intelligent group mapping translation layer (`auth_group_mappings` table). Rather than manually maintaining local user permissions, external directory groups directly map to granular MicronOS RBAC Roles (`roles` table).

#### 8.10.1 Mapping Evaluation Engine & Conflict Resolution
When an external user authenticates via Active Directory (via `memberOf` DN strings) or RADIUS (via `Filter-Id`, `Class`, or Cisco AV-Pair attributes), the user may belong to multiple groups simultaneously. MicronOS applies a deterministic **Priority-Weighted Evaluation Algorithm**:

```
External User Groups: ["VPN-Users", "SOC-Analysts", "Domain Admins"]
                               │
                               ▼
        ┌──────────────────────────────────────────────────┐
        │  Fetch Active Group Mappings (Priority Ordered)  │
        └──────────────────────────────────────────────────┘
                               │
   ┌───────────────────────────┴───────────────────────────┐
   ▼                                                       ▼
Rule 1 (Priority 1): "Domain Admins"  ──▶ Match Found! ──▶ Role: "SuperAdmin"
Rule 2 (Priority 5): "SOC-Analysts"   ──▶ Skipped (lower priority)
Rule 3 (Priority 10): "VPN-Users"     ──▶ Skipped (lower priority)
                               │
                               ▼
          Effective MicronOS Role Assigned: SuperAdmin
          Permissions Cached: All 96 System Permissions
```

If no mapped group matches, the system safely falls back to the configured directory/RADIUS default role (`default_role_id`), or refuses administrative access if no fallback role is designated.

#### 8.10.2 Centralized Administration in Roles & Permissions (`/roles`)
The group mapping engine is managed directly within **System > Roles & Permissions > External Group Mappings** (`/roles/group-mappings`):
- **Universal External Support**: Supports both LDAP/AD group identifiers (e.g., `CN=FirewallAdmins,OU=Groups,DC=corp,DC=local` or simple CN `FirewallAdmins`) and RADIUS authorization strings (e.g., `radius-superadmins`, `Filter-Id: NetworkOps`).
- **Scope Specificity**: Mappings can be bound globally across all directories or pinned specifically to an individual directory/RADIUS server (`server_id`).
- **Visual Mapping Matrix**: Displays role assignments with contextual role badges, priority hierarchy pills, and inline CRUD modals (`POST /roles/group-mappings/store`, `POST /roles/group-mappings/update`, `POST /roles/group-mappings/delete`).

---

### 8.11 Users & Identities Management Integration (`/users`)

The **Users & Identities** subsystem (`/users`) acts as the single unified pane of glass for all human and service identities accessing the MicronOS platform.

#### 8.11.1 Multi-Source Identity Federation
The user directory seamlessly tracks identities originating from diverse authentication backends:
- **Local Users (`auth_source = 'local'`)**: Locally managed accounts with Argon2id / Bcrypt password hashing, local password rotation policies, and WebAuthn / TOTP bindings.
- **Active Directory / LDAP Users (`auth_source = 'ldap'`)**: Accounts bound to corporate directories. Local passwords are not stored (`password_hash = NULL`); authentication delegates live to upstream directory servers. The user's Distinguished Name (`ldap_dn`) and current group membership (`external_group`) are cached for auditing.
- **RADIUS Users (`auth_source = 'radius'`)**: Network and administrative users authenticated via enterprise AAA servers. Authentication bypasses local hashes, allowing corporate password expiration and token policies to govern access.

#### 8.11.2 Directory User Import Engine (`/users/import-ldap`)
Rather than waiting for users to sign in dynamically, administrators can proactively import directory users:
1. Administrator accesses **Import Directory Users** modal on `/users`.
2. Selects target LDAP / AD server connection.
3. Enters an optional directory query filter (e.g., `(&(objectClass=user)(department=IT))`).
4. MicronOS executes an authenticated query against the directory tree, displaying user matches with CN, email, and group memberships.
5. Administrator selects users and assigns baseline roles or delegates to the group mapping engine. Selected identities are enrolled with `auth_source = 'ldap'`.

#### 8.11.3 UI Metrics & Quick Filtering
The `/users` console features instant visual telemetry:
- **Authentication Source Distribution Cards**: Real-time counters showing total active Local Accounts, Active Directory / LDAP accounts, and RADIUS accounts.
- **Dynamic Filter Pills**: One-click filtering between `All Users`, `Local Only`, `Active Directory`, and `RADIUS`.
- **Identity Source Badges**: Color-coded badges indicating authentication origin (`Local` in cyan, `Active Directory` in blue, `RADIUS` in purple).

---

### 8.12 Dynamic Login Delegation, Resilience & Just-In-Time (JIT) Provisioning

MicronOS implements a zero-friction, multi-stage authentication pipeline in `app/Controllers/Auth.php` and `app/Libraries/AuthService.php`. When a user submits credentials at `/auth/login`, the system follows an automated delegation cascade:

```
                      User Enters Credentials at /auth/login
                                         │
                                         ▼
                     ┌──────────────────────────────────────┐
                     │ Check Local Database ('users' table) │
                     └──────────────────────────────────────┘
                                         │
                 ┌───────────────────────┴───────────────────────┐
                 ▼                                               ▼
          [ Record Exists ]                              [ Record Not Found ]
                 │                                               │
   ┌─────────────┼─────────────┐                                 │
   ▼             ▼             ▼                                 ▼
auth_source   auth_source   auth_source                  [ Dynamic JIT Login ]
 = 'local'     = 'ldap'      = 'radius'                          │
   │             │             │                                 │
Verify        Delegate to   Delegate to               Try Enabled LDAP Servers
Argon2id/     LdapAuthSvc   RadiusAuthSvc             (Primary ──▶ Secondary)
Bcrypt Hash   (AD / LDAP)   (RFC 2865)                           │
   │             │             │                       Success? ──┼── No
   │             │             │                          │       │
   │             │             │                          ▼       ▼
   │             │             │                   Try RADIUS Servers
   │             │             │                   (Primary ──▶ Secondary)
   │             │             │                          │
   │             │             │                       Success? ──┼── No
   │             │             │                          │       │
   ▼             ▼             ▼                          ▼       ▼
┌────────────────────────────────────────────────────────────┐ ┌─────────────┐
│             AUTHENTICATION SUCCESS EVALUATION              │ │ HTTP 401    │
├────────────────────────────────────────────────────────────┤ │ Login Denied│
│ 1. Resolve External Groups via 'auth_group_mappings'       │ └─────────────┘
│ 2. If JIT Login: Auto-provision 'users' record with Role   │
│ 3. Hydrate Session with Permissions Union ('user_perms')   │
│ 4. Emit Audit Event into 'audit_logs' with IP & Method     │
│ 5. Redirect User to Target Dashboard View                  │
└────────────────────────────────────────────────────────────┘
```

#### 8.12.1 Security Safeguards & Rate Limiting
- **Brute-Force Shield**: Failed login attempts trigger exponential IP-based throttling to defend both local and remote directory infrastructure against credential stuffing and account lockouts.
- **Audit Logging**: Every successful and failed authentication event logs the exact transport method, source IP, username, and resolved identity provider into `audit_logs` for compliance reporting (PCI-DSS 8.1, SOC2 CC6.1).
- **Zero-DB Permission Evaluation**: Because permissions are resolved and unioned into the session token upon authentication, subsequent requests require zero database queries to enforce authorization gates.

---

## 9. Road Warrior Remote Access VPN with Multi-Factor Authentication (2FA)

```
┌────────────────────────────────┐
│   Remote Client (OpenVPN GUI)  │
└───────────────┬────────────────┘
                │
                │ 1. TLS Certificate Handshake (Device ID verification)
                ▼
┌────────────────────────────────┐
│      OpenVPN Daemon (Linux)    │
└───────────────┬────────────────┘
                │
                │ 2. PAM Challenge / auth-user-pass-verify
                ▼
┌────────────────────────────────┐
│     Micron Auth Helper         │
│     (/usr/lib/micron/vpn-auth) │
└───────────────┬────────────────┘
                │
        ┌───────┴───────────────────────────┐
        ▼                                   ▼
[ Verify Password ]                [ Verify 2FA OTP ]
Active Directory / LDAP            TOTP Secret Engine
(User: john.doe)                   (Code: 849201)
        │                                   │
        └───────────────┬───────────────────┘
                        ▼
            [ Both Success -> TUNNEL ESTABLISHED ]
```

---

## 10. Next-Gen Web Filtering & Proxy Engine (Squid, TPROXY, C-ICAP, ClamAV)

```
                           CLIENT REQUEST
                                 │
                                 ▼
                     nftables TPROXY (TCP 80/443)
                                 │
           ┌─────────────────────┴─────────────────────┐
           ▼                                           ▼
   HTTP (Port 80)                              HTTPS (Port 443)
           │                                           │
           │                                 Squid SSL-Bump Engine
           │                                           │
           │                          ┌────────────────┴────────────────┐
           │                          ▼                                 ▼
           │                 Tier 2: Peek & Splice             Tier 3: SSL Decryption
           │                 (No Root CA on client)            (Corporate Root CA)
           │                 - Extracts TLS SNI                - Full URL inspection
           │                 - Matches against blocklist       - File download scanning
           │                 - Zero client cert deployment     - MIME-type blocking
           │                          │                                 │
           └──────────────────────────┼─────────────────────────────────┘
                                      │
                                      ▼
                        URL & Category Classification Engine
                     (UT1 / Shallalist / Custom Blacklists)
                                      │
                      ┌───────────────┴───────────────┐
                      ▼                               ▼
                 [ MATCH BLOCK ]                 [ ALLOW ]
                      │                               │
             Generate Custom Block Page         ICAP Anti-Malware
             (Internal Nginx VIP / 403)        (C-ICAP + ClamAV Scan)
                                                      │
                                                      ▼
                                              Forward to Internet
```

---

### 10.1 High-Performance Proxy Architecture & Operational Modes

The MicronOS Web Filter and Proxy subsystem provides advanced caching, traffic inspection, and compliance enforcement. Built on an optimized **Squid 6.x** caching proxy engine, it operates in three complementary deployment topologies configured under **Security Services > Web Filter & Proxy** (`/webfilter`):

1. **Explicit Forward Proxy (`http_port 3128`)**:
   - Clients explicitly configure proxy settings manually, via Group Policy (GPO), or through automated discovery (WPAD).
   - Supports user authentication (LDAP / Active Directory, RADIUS, or Local), detailed accounting, and explicit port steering.
2. **Transparent TPROXY (Linux `IP_TRANSPARENT` & `nftables`)**:
   - Intercepts outbound HTTP (port 80) and HTTPS (port 443) connections without requiring client-side network configuration.
   - Leverages Linux kernel `TPROXY` socket options to preserve the original client and server IP addresses in proxy connection tables and logs, avoiding standard NAT IP masquerading bottlenecks.
3. **SSL-Bump Inspection Modes**:
   - **Tier 2: Peek & Splice (Zero-Touch Deployment)**: Inspects the TLS Client Hello to extract the Server Name Indication (SNI) extension. Compares the SNI against category blocklists and domain ACLs. If allowed, Squid "splices" the connection, stepping aside and letting encrypted traffic pass directly without requiring a trusted root CA on client endpoints.
   - **Tier 3: Full SSL MITM Decryption (Corporate Endpoints)**: Generates on-the-fly dynamic certificates signed by the MicronOS internal Certificate Authority (CA). Decrypts TLS payloads for full URL path inspection, POST body analysis, and antivirus content filtering.

---

### 10.2 Comprehensive Access Control Lists (ACLs) & Policy Enforcement

The policy engine translates high-level web filtering rules into strict Squid ACL directives:

| ACL Type | Matching Parameter | Enforcement Action | Example Configuration |
| :--- | :--- | :--- | :--- |
| **Source Subnet ACL** | Client IPv4 / IPv6 Subnet | Allow / Deny / Bypass | `acl corporate_lan src 10.10.0.0/16` |
| **Domain Blacklist** | FQDN / Wildcard Domain | Instant 403 / Redirect | `acl blocked_sites dstdomain .gambling.com .malware.net` |
| **URL Regular Expression** | Full URL Path & Query | Pattern Match Reject | `acl dangerous_paths url_regex -i \.(exe|bat|ps1|scr|iso)$` |
| **MIME Type Filter** | HTTP `Content-Type` Header | Stream Intercept | `acl blocked_mimes rep_mime_type application/x-msdownload` |
| **Time-Based ACL** | Day of Week & Hour Window | Conditional Access | `acl business_hours time M T W H F 08:00-18:00` |
| **Bypass Whitelist** | Government / Banking / Medical | Bypass SSL Bump & AV | `acl financial_domains ssl::server_name .chase.com .gov` |

---

### 10.3 Automated Proxy Discovery: Simplified WPAD & PAC Configuration Architecture

Configuring proxy settings across thousands of heterogeneous enterprise endpoints (Windows, macOS, Linux, iOS, Android) often leads to deployment friction and browser misconfigurations. MicronOS solves this by implementing a turnkey **Web Proxy Auto-Discovery (WPAD)** and **Proxy Auto-Configuration (PAC)** engine that drastically simplifies enterprise deployment.

#### 10.3.1 Architectural Discovery Mechanics
```
  Client Device Boot / Network Association
                     │
         ┌───────────┴───────────┐
         ▼                       ▼
  [ DHCP Discovery ]       [ DNS Discovery ]
  Requests Option 252      Queries 'wpad.<search-domain>'
         │                       │
         ▼                       ▼
  MicronOS Kea DHCP        MicronOS Unbound DNS
  Returns Option 252:      Resolves 'wpad' A-Record
  "http://<ip>/wpad.dat"   To MicronOS Management VIP
         │                       │
         └───────────┬───────────┘
                     ▼
  Client HTTP GET http://<firewall-ip>/wpad.dat (or /proxy.pac)
                     │
                     ▼
  MicronOS Dynamic PAC Generator (/webfilter/pac)
  Emits Optimized PAC JavaScript (application/x-ns-proxy-autoconfig)
                     │
                     ▼
  Browser Executes PAC Rules:
  - Local LAN Subnets & Private RFC 1918 ──▶ DIRECT (No Proxy)
  - Explicit Internal Services (.corp)   ──▶ DIRECT (No Proxy)
  - External Internet Web Traffic        ──▶ PROXY proxy.corp:3128; DIRECT
```

#### 10.3.2 Turnkey Implementation Advantages
1. **Dynamic In-App Serving**: Rather than requiring dedicated external web servers, the MicronOS core HTTP daemon directly serves `http://micronos.test/wpad.dat` and `http://micronos.test/proxy.pac` with RFC-compliant headers (`Content-Type: application/x-ns-proxy-autoconfig`).
2. **Deterministic Fallback Routing**: Generated PAC scripts include resilient failover logic: if the primary proxy fails health checks, browser clients automatically attempt secondary proxies before falling back to direct routing.
3. **No Root CA Required in Splicing Mode**: When paired with Tier 2 Peek & Splice SSL-Bump, the combination of WPAD auto-discovery and SNI filtering delivers comprehensive web protection across unmanaged BYOD endpoints with zero client software installation.

---

### 10.4 Antivirus & Content Inspection Pipeline (C-ICAP & ClamAV)

**Status: implemented.** The Web Filter page carries an *Antivirus & Malware (ICAP / ClamAV)* tab (`/webfilter?tab=antivirus`) that stores the inspection policy in `webfilter_settings`, compiles it into `squid.conf` via `SquidConfigCompiler`, and drives the `c-icap` and `clamav-daemon` services.

For full payload security, MicronOS routes explicit and decrypted HTTP/HTTPS traffic through a high-throughput **Internet Content Adaptation Protocol (ICAP, RFC 3507)** bridge:
- **Streaming Pipeline**: Files and dynamic payloads are scanned concurrently in memory via `c-icap` offloading to the multi-threaded `clamav-daemon` socket.
- **Archive Decomposition**: Nested archives (`.zip`, `.tar.gz`, `.7z`, `.rar`) are unpacked in memory up to 8 recursion levels to detect embedded ransomware, macro viruses, and obfuscated exploits.
- **Custom Block Pages**: Blocked or infected requests trigger an instant internal HTTP 403 error page notifying the end user of the specific signature violation (`Eicar-Test-Signature`, `Win.Trojan.Generic`), user IP, and timestamp.

**Management surface:**

| Control | Behaviour |
| :--- | :--- |
| Master toggle | `icap_antivirus_enabled` — off compiles `# ICAP antivirus inspection disabled.` and nothing else. |
| ICAP endpoint | Host, port (default 1344) and service name (default `squidclamav`). |
| Direction | `request` (`reqmod_precache`), `response` (`respmod_precache`) or `both`. |
| Preview / scan size | `icap_preview_size` in KB and the maximum scanned file size in MB. |
| Failure policy | `allow` compiles `bypass=1` (fail open if the scanner dies); `block` compiles `bypass=0` (fail closed). |
| Scan bypass | Domains compiled to `acl av_scan_bypass dstdomain …`, then `adaptation_access … deny av_scan_bypass` ahead of the `allow all` rule. |
| HTTPS bodies | Only reachable by the scanner when SSL-Bump deep inspection is enabled; otherwise the compiled config carries an explicit warning comment. |
| Definitions telemetry | Engine, signature count, revision and last-update time, persisted to `webfilter_settings` and refreshed by **Update Signatures Now** (`POST /webfilter/av/update-signatures` → `webfilter.clamav.update`). |
| Detection log | `webfilter_av_detections` (time, client, domain, threat, action, size, SHA-256) with a clear action. |

Compiled `squid.conf` fragment:
```
icap_enable on
icap_send_client_ip on
icap_preview_size 1024
icap_service_failure_limit 5 in 10 seconds
icap_service svc_respmod respmod_precache bypass=1 icap://127.0.0.1:1344/squidclamav
acl av_scan_bypass dstdomain *.apple.com *.windowsupdate.com
adaptation_access svc_respmod deny av_scan_bypass
adaptation_access svc_respmod allow all
```

**SSL-Bump interception CA.** Decrypting HTTPS requires a CA that clients trust. `InterceptionCa` generates it into the PKI store (`pki_authorities`, shared with `/certificates`) rather than serving a stub, and `POST /webfilter/provision-ca` installs it at the configured Squid paths. `GET /webfilter/download-ca` serves that real CA and returns HTTP 503 when none exists — handing an operator a certificate that cannot validate a session is worse than an honest error. The certificate is a genuine self-signed CA (`basicConstraints CA:TRUE`), without which Squid could not sign the per-host certificates it mints.

---

## 11. Dynamic Enterprise Routing & Multi-WAN (FRRouting, BGP, OSPF, RIP, IS-IS, BFD, VRF, PBR)

FRRouting provides carrier-grade routing directly through the GUI:
- **BGP-4 & MP-BGP:** Multi-homed ISP peering, community tagging, AS-path prepending.
- **OSPFv2 / OSPFv3:** Multi-area enterprise routing with MD5 authentication, per-area/interfaces/neighbors configuration, and a dedicated **OSPF Policy** tab for prefix-lists, route-maps, distribute-lists and ABR area filter-lists (tables `frr_prefix_lists`, `frr_route_maps`, `frr_ospf_distribute_lists`, `frr_ospf_area_filters`).
- **RIPv2 (`ripd`):** Lightweight legacy interoperability — networks, unicast neighbors, passive interfaces and redistribution (`frr_rip_*`).
- **IS-IS (`isisd`):** Integrated link-state routing — NET/area-tag, level, metric-style and per-interface circuit tuning (`frr_isis_*`).
- **BFD:** 150 ms link failure detection across WANs and WireGuard tunnels.
- **VRF:** Complete Layer 3 network isolation.

### 11.1 Policy-Based Routing (PBR)

Policy-Based Routing lets an administrator steer traffic by **who or what** it is, rather than only by its destination prefix. Rules live in the `routing_pbr_rules` table, are evaluated in a deterministic sequence, and are compiled by `NftablesCompiler` into `ip rule` / route lookups plus `nftables` marks on the `micron_filter` table.

A PBR policy has four moving parts:

| Component | Purpose | Storage |
| :--- | :--- | :--- |
| **Match source** | Host, subnet, address group, or interface the policy applies to. | `source` |
| **Match objects** | Protocol, destination port(s), DSCP, and one or more **Internet Service** / FQDN / GeoIP address objects. | `match_objects` (migration 53) |
| **Action / gateway** | Which WAN gateway, gateway group, or VRF the matched traffic egresses through. | `gateway`, `vrf` |
| **Order & state** | Explicit sequence number and enable toggle so policies compose predictably. | `sequence`, `enabled` |

Ordering is explicit and re-orderable from the UI; the compiler refuses to emit a policy that references a match object that no longer exists (`unknownPbrObjects()` surfaces dangling references rather than silently dropping the rule). Applying policies is a privileged data-plane operation (`POST pbr/apply`) that is gated behind the `routing.pbr.manage` permission and executed by `micrond` as a transaction.

### 11.2 Multi-WAN: Gateways, Gateway Groups & Sticky Connections

Multi-WAN is modelled as three layers:

1. **WAN Gateways** (`wan_gateways`) — a named next-hop (IP or interface) with a monitor address, weight, and description. Gateway latency, jitter (`RTTsd`), and loss are sampled by the gateway monitor.
2. **Gateway Groups** (`wan_gateway_groups` + `wan_gateway_group_members`) — an ordered, weighted pool of gateways with a **failover trigger** (member down, packet loss, high latency) and a **load-balancing strategy** (round-robin, weight-based, or primary/backup).
3. **Sticky Connections** (`sticky` column, migration 54) — when enabled on a group, each individual LAN flow is hashed to a single member gateway and **pinned for the lifetime of the connection**.

#### Why sticky connections matter

Naive per-packet load balancing across two WANs breaks stateful protocols: a banking session that leaves via WAN1 and returns via WAN2 has a different source IP, so the server resets it. Banking a *flow* (not a packet) to one gateway preserves the source address for the whole TCP/UDP conversation:

```
                    LAN CLIENT 192.168.1.50
                              │
                    ┌─────────┴─────────┐
                    │  Flow Hash (5-tuple)│  ← sticky=True
                    └─────────┬─────────┘
                 ┌────────────┴────────────┐
                 ▼                          ▼
        WAN1 Gateway (primary)      WAN2 Gateway (backup)
        eth0 · 198.51.100.2         eth1 · 203.0.113.9
        │                            │
        └──── all packets of THIS ───┘   Flow A → WAN1 (pinned)
              connection use one      Flow B → WAN2 (pinned)
              source IP for its life  Flow C → WAN1 (pinned)
```

The `applyMultiWan` controller action compiles the group, member weights, per-gateway default routes, and the sticky policy into a single `micrond` transaction. When a member fails its health probe, only the flows pinned to that member are re-hashed onto healthy members; flows on the remaining members are left undisturbed.

---

## 12. WAN Access & PPPoE Subscriber Management (PPPoE Client, BRAS, RADIUS, EAP-TLS)

MicronOS terminates and originates PPPoE on both sides of the appliance. The same compilation engine (`PppoeCompiler`) and daemon (`micrond`) power two distinct roles:

```
┌────────────────────────────── WAN SIDE ──────────────────────────────┐
│  PPPoE CLIENT (uplink)                                                │
│  physical NIC ──► pppd + rp-pppoe.so ──► ppp0 (MTU 1492)             │
│      peer file     chap-secrets        health probe / redial cron     │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────── LAN / SUBSCRIBER SIDE ───────────────────────┐
│  PPPoE ACCESS SERVER (BRAS)                                           │
│  eth1 ◄── pppoe-server ──► ppp1, ppp2, ppp3 … one per subscriber      │
│      pppoe-server-options   pppoe-server-users   RADIUS / EAP-TLS     │
└──────────────────────────────────────────────────────────────────────┘
```

All configuration is stored relationally and materialised only by the privileged daemon — the web tier never writes `/etc/ppp` directly.

### 12.1 PPPoE Client (WAN Uplink)

Any interface with `ip_assignment_type = 'pppoe'` becomes a client. The eight `pppoe_*` columns on `interfaces` carry the credentials and options:

| Field | Meaning |
| :--- | :--- |
| `pppoe_username` / `pppoe_password` | Subscriber credentials (password encrypted at rest). |
| `pppoe_service` | Requested service name (blank accepts any). |
| `pppoe_ac_name` | Access concentrator name to request. |
| `pppoe_mtu` / `pppoe_mru` | Clamped MTU/MRU, default **1492** (1500 − 8 bytes PPPoE overhead). |
| `pppoe_idle_timeout` | Idle disconnect timer in seconds (`0` never disconnects). |
| `pppoe_default_route` | Install a default route via the negotiated peer. |

Compilation produces:

- **`/etc/ppp/peers/<device>`** — the pppd option file (`plugin rp-pppoe.so`, `nic-<iface>`, `noauth`, `defaultroute`, `mtu 1492`, `persist`, DNS handling).
- **`/etc/ppp/chap-secrets`** — CHAP/PAP credentials for every PPPoE client interface.

### 12.2 PPPoE Subscriber Access Server (BRAS)

The `pppoe_servers` table describes a concentrator bound to a LAN-side interface. Each server owns a local tunnel address and an IPv4 address pool (`remote_start`–`remote_end`), plus a subscriber list in `pppoe_server_users`.

| Server field | Purpose |
| :--- | :--- |
| `interface` | LAN interface carrying the subscriber broadcast domain. |
| `service_name`, `ac_name` | Advertised PPPoE service tags. |
| `local_ip`, `remote_start`, `remote_end` | Tunnel endpoint and dynamic address pool. |
| `dns1`, `dns2` | DNS handed to subscribers via IPCP. |
| `auth_required` | Whether subscribers must authenticate. |
| `idle_timeout`, `max_sessions` | Idle disconnect and concurrency cap. |
| `auth_backend` | `local` (CHAP against `chap-secrets`) or `radius`. |
| `radius_*` | RADIUS server(s), shared secret, NAS-ID, timeout/retries, accounting. |
| `eap_tls` + `eap_*` | X.509 material for certificate-based authentication. |

Subscriber rows (`pppoe_server_users`) carry `username`, `password`, optional `static_ip`, per-user `dns1`/`dns2`, a `rate_limit`, description, and enable state. Compilation emits `/etc/ppp/pppoe-server-options`, the per-server `chap-secrets` entries, and the per-user RADIUS/rate-limit intent.

### 12.3 RADIUS Authentication

When `auth_backend = radius`, the server options gain the pppd RADIUS plugin chain, and a `radiusclient.conf` is compiled for it:

```
# /etc/ppp/pppoe-server-options (excerpt)
plugin radius.so
plugin radattr.so          # order matters: radattr must load after radius
require-mschap-v2
radius-config-file /etc/radiusclient/radiusclient.conf
```

```
# /etc/radiusclient/radiusclient.conf (excerpt)
authserver 10.20.30.40:1812,10.20.30.41:1812
acctserver 10.20.30.40:1813
```

- Up to **two authentication servers** (`radius_server`, `radius_server2`) are compiled as a failover list; each may override its port.
- The shared secret is **encrypted at rest** and decrypted only inside `micrond`.
- A **built-in test tool** (`Pppoe::testRadius()` → `pppoe.radius.test`) sends a real `Access-Request` using `radclient` so the administrator can validate credentials and reachability before committing. The verdict is read from the `Received` reply line (a rejection string naturally contains the words “Access-Accept”, so the first matching token is ignored).
- NAS identity is advertised with `radius_nas_id`; the NAS-IP is derived from the server's local tunnel address.

### 12.4 RADIUS Accounting

Accounting is enabled independently per server (`radius_accounting`) with a dedicated `radius_acct_port` (default **1813**) and optional `radius_acct_server` override. The presence of an `acctserver` line in `radiusclient.conf` is what turns accounting on in pppd's `radius.so`; when disabled, the compiler emits an explicit `# accounting disabled for this server` comment so the config remains self-documenting.

| Event | pppd trigger | Records sent |
| :--- | :--- | :--- |
| Accounting-Start | IP interface comes up | `Acct-Status-Type=Start`, session/tunnel IDs, framed IP, NAS info |
| Interim-Update | Only when the server returns `Acct-Interim-Interval` | Running octets, session time |
| Accounting-Stop | Interface goes down | Final octets, session time, terminate cause |

> **Engineering note:** pppd only transmits Interim-Update records when the `Access-Accept` carries an `Acct-Interim-Interval` attribute, and it enforces a hard **60-second floor** on the interval it will use. A RADIUS policy that omits the attribute yields Start/Stop accounting only.

Interim-Update is surfaced in the UI as an `Acct Interim <n>` chip on the live session, so administrators can see at a glance whether the subscriber is on a metered/periodic-accounting plan.

### 12.5 EAP-TLS Certificate Authentication

EAP-TLS is a genuinely **additive** authentication method: it can be enabled alongside PAP/CHAP on the same server. pppd negotiates EAP when the peer offers it and otherwise falls back to the configured CHAP method — no existing subscriber is broken by turning it on.

#### How the certificate material is located

EAP-TLS support is **compiled into pppd**; it is *not* a loadable plugin. The appliance therefore **never emits a `plugin` line for it**. On the server side pppd does not read top-level `ca`/`cert`/`key` options (those are client-side). Instead it reads a per-peer **EAP-TLS secrets file**:

```
# /etc/ppp/eaptls-server   (tab-separated, one line per peer)
# client   server   cli-cert      serv-cert                ca-cert           key
*          *        -             /etc/ppp/eaptls/srv01.crt /etc/ppp/eaptls/ca.crt /etc/ppp/eaptls/srv01.key
```

Fields: `client`, `server`, `client-cert`, `server-cert`, `ca-cert`, `key`, then optional per-peer address filters. A `-` leaves a field unspecified; `*` is a wildcard. The client-cert field defaults to `-` until a specific client certificate is pinned.

The compiler (`PppoeCompiler`):

- Writes each certificate/key to `/etc/ppp/eaptls/` with a filename-safe identifier (key mode **0600**, certificates **0644**, secrets file **0600**).
- Normalises every PEM block (CRLF→LF, exactly one trailing newline) and rejects non-PEM input during validation.
- Emits `require-eap` in the server options, plus a `crl <path>` line when a Certificate Revocation List is supplied.

```
# /etc/ppp/pppoe-server-options (EAP-TLS excerpt)
require-eap
ipcp-accept-local
ipcp-accept-remote
crl /etc/ppp/eaptls/revoked.crl
```

Enabling EAP-TLS requires a **CA certificate**, a **server certificate**, and a **server private key**; a client certificate and a CRL are optional. Private keys are encrypted at rest, are never sent to the browser, and can be left blank on an update to mean “keep the existing key”.

### 12.6 Live Sessions & Per-Account Policy Visibility

The `/pppoe` page shows live sessions for both roles. Beyond negotiated address and counters, MicronOS reads RADIUS **reply attributes** from `/var/run/radattr.<iface>` (written by `radattr.so`) and normalises them into human-readable chips (`RadiusAttributes::describe()`):

- **Session-Timeout / Idle-Timeout** rendered as durations, with a live “expires in …” countdown.
- **Framed-IP-Address** reported separately from the negotiated address when they differ.
- **Rate limits** recognised across multiple dictionary spellings (`Filter-Id`, `Mikrotik-Rate-Limit`, `SN-Rate-Limit`, `Cisco-AVPair`) and the `WISPr-Bandwidth-Max-Down`/`-Up` pair.
- **Acct-Interim-Interval** shown as the accounting cadence.
- Any attribute that cannot be interpreted is still shown verbatim in an “+N more” expansion, so nothing is silently hidden.

The same attribute map is persisted per session into `pppoe_session_history` (migration 60), giving a per-subscriber audit trail of how rate limits and timeouts changed over time.

### 12.7 Resilience: Health-Aware Redial & Escalating Backoff

PPPoE links flap. MicronOS treats a redial as a decision, not a blind loop:

1. **Health assessment** — the redial engine inspects each link's operational state before acting. Point-to-point interfaces can report `state UNKNOWN` while carrying traffic, so the link is considered up based on the interface **UP flag**, not the state word.
2. **Selective repair** — only links that are not up are rebuilt; the concentrator is restarted only if something was actually repaired. Healthy links are reported as `skipped`.
3. **Escalating backoff** — repeated failures for an interface advance a backoff schedule (`pppoe_backoff_states`), computing `next_retry_at` and suppressing premature retries. Crossing the alert threshold raises an operator alert with the reason.
4. **Force override** — `--force` (CLI) or the UI action bypasses health checks and backoff for a deliberate hard recycle.

A redial can be triggered three ways: the web UI, the `micron:pppoe-redial` CLI, or the seeded `cron_pppoe_redial` scheduled job.

### 12.8 Run History

Every redial outcome is appended to a bounded, machine-parsable run history via `CronJobLog` (see §22.1). Each entry records a one-line summary, status, and the repair/skip detail, and the history retains the most recent runs up to a byte cap so the `cron_jobs` row cannot grow without bound.

### 12.9 Management Surface

| Route | Method | Permission | Purpose |
| :--- | :--- | :--- | :--- |
| `pppoe/` | GET | `pppoe.server.view` | Servers, subscribers, live sessions. |
| `pppoe/servers/store`, `/(:segment)/update` | POST | `pppoe.server.manage` | Create/update a concentrator. |
| `pppoe/servers/(:segment)/test-radius` | POST | `pppoe.server.manage` | Live RADIUS `Access-Request` test. |
| `pppoe/users/store`, `/(:segment)/update` | POST | `pppoe.server.manage` | Subscriber accounts. |
| `pppoe/users/(:segment)/history` | GET | `pppoe.server.view` | Per-subscriber session history. |
| `pppoe/redial` | POST | — | Health-aware redial (with optional force). |
| `pppoe/apply` | POST | `pppoe.server.apply` | Commit configuration to the data plane. |

CLI: `php spark micron:pppoe-redial [--interface <if>] [--down] [--force] [--ipc]`.

---

## 13. Internet Service Database & Offline Address Resolution (FQDN, GeoIP, Threat Feeds)

Firewall policy should be written against **services and identities**, not fragile literal IP lists. The Internet Service Database lets an administrator reference a named service (e.g. a public cloud, a collaboration suite, a social platform) and have MicronOS keep its IP prefixes current automatically — *without* the appliance ever depending on live DNS at policy-evaluation time.

### 13.1 Service Catalogue

Each service is a stable key with a display name and description. Representative entries include cloud infrastructure, collaboration and conferencing suites, developer platforms, social media, streaming and gaming platforms, and anonymity networks. Country/region address blocks are addressed separately through a GeoIP path.

### 13.2 Offline Resolution Pipeline

The design principle is **resolve once, distribute locally**:

```
   Public prefix feeds (vendor-published, HTTPS)
                  │
                  ▼
        micron:sync-services  (ServiceDatabase)
                  │  parse per-source format
                  ▼
     internet_services.resolved_prefixes  ──►  AddressResolver
                  │                                  │
                  ▼                                  ▼
   firewall_addresses (type: internet-service)   ResolveObjects cron
   firewall_addresses (type: fqdn, geoip)         │
                  │                               ▼
                  └──────────────►  nftables sets / address groups
                                    (written as local feed files)
```

- **`ServiceDatabase`** downloads and parses each enabled service's published prefix list. Feeds are expressed by **public URL only** — the catalogue stores no vendor code, so nothing proprietary is embedded in the appliance.
- **`AddressResolver`** resolves address objects of type `fqdn`, `internet-service`, and `geoip` into concrete CIDR prefixes, caching the result in the `resolved_prefixes` column (migration 49).
- **`ResolveObjects`** re-resolves objects on a schedule, tracks freshness (default staleness 24 hours), and can report which objects are stale.
- Because resolved prefixes are stored locally, policy evaluation and packet filtering never block on an external request; the appliance is fully functional when isolated from the internet.

### 13.3 FQDN Address Objects

An address object may be a fully-qualified domain name, including **wildcards** (`*.example.com`). The resolver expands the FQDN to its current A/AAAA prefixes and stores them; wildcard matches cover the whole zone. This is how administrators write “allow the vendor's SaaS endpoints” rules that stay correct as the provider rotates IPs.

### 13.4 Feed Files & nftables Integration

The compiler may emit resolved prefix sets as **local feed files** that `nftables` includes as named sets, so large service lists match in a single set lookup rather than hundreds of sequential rules. Because a set is refreshed by rewriting the include file, updating a service never requires re-emitting the whole ruleset.

### 13.5 Synchronisation & Scheduling

Two scheduled jobs are seeded to keep the database fresh:

| Job | Action | CLI equivalent |
| :--- | :--- | :--- |
| `cron_address_resolve` | Re-resolve FQDN / Internet-Service / GeoIP address objects and prune stale prefixes. | `php spark micron:resolve-objects` |
| `cron_service_sync` | Download and parse enabled service prefix feeds. | `php spark micron:sync-services [--service <key>] [--dry-run]` |

Administrators can also trigger a manual sync from `/internet-services`. Both the CLI and the web action report per-service status (`ok`, `no-source`, `error`) with counts, so a failing upstream feed is visible rather than silent.

---

## 14. High Availability & Stateful Cluster Failover (Keepalived & conntrackd)

```
                      ┌──────────────────────┐
                      │    Upstream / WAN    │
                      └──────────┬───────────┘
                                 │
                 ┌───────────────┴───────────────┐
                 │                               │
        ┌────────▼────────┐             ┌────────▼────────┐
        │  Firewall A     │             │  Firewall B     │
        │  MASTER         │             │  BACKUP         │
        │  WAN: 198.51.1  │             │  WAN: 198.51.2  │
        │  LAN: 10.0.0.2  │             │  LAN: 10.0.0.3  │
        └────────┬────────┘             └────────┬────────┘
                 │                               │
                 │ ◄──── Sync Link (Heartbeat) ──►
                 │   1. Keepalived (VRRP v4/v6)  │
                 │   2. conntrackd (State Sync)  │
                 │   3. Config Sync (mTLS API)   │
                 │                               │
                 └───────────────┬───────────────┘
                                 │
                      Virtual IP: 10.0.0.1
                                 │
                      ┌──────────▼───────────┐
                      │     LAN Switch       │
                      └──────────────────────┘
```

---

## 15. Intrusion Detection & Prevention (Suricata IDS/IPS) & DDoS Defense
Suricata 7+ multi-threaded inline engine with AVX-512 acceleration, daily ET rules, and native `nftables` GeoIP sets.

**Passive vs. inline.** In `ids` mode Suricata sniffs from AF_PACKET rings and only alerts. In `ips` mode enforcement is a two-sided contract that has to stay in sync:

| Half | Produced by | Content |
| :--- | :--- | :--- |
| Engine side | `SuricataCompiler` | `nfq:` block (`mode: accept`, repeat/bypass marks) and `af-packet: copy-mode: ips` |
| Firewall side | `NftablesCompiler` | `table inet micron_ids` — `ips_forward` / `ips_input` chains at `priority 10` running `queue num <nfqueue_num> [bypass]` |

The diversion is emitted at `priority 10`, *after* the `priority filter` chains, so the scanner only ever sees traffic the firewall has already accepted. `queue_fail_mode` decides whether a dead engine fails open (the `bypass` flag keeps forwarding) or closed; fail-open is the default, because an IPS must never be able to blackhole the appliance. Both halves read the same `ids_settings` row — which is why the queue controls live there and not in the engine config.

---

## 16. Traffic Shaping & QoS Bufferbloat Elimination (CAKE / fq_codel)
CAKE automatically isolates interactive traffic from background bulk transfers:
```bash
tc qdisc replace dev eth0 root cake bandwidth 500mbit diffserv4 nat dual-srchost
```

---

## 17. Configuration Backup & Restore Engine (Encrypted & Plain Mechanism)

Configuration backups in an enterprise network appliance must satisfy two conflicting requirements: **portability** (migrating to different hardware) and **extreme cryptographic secrecy** (protecting private keys, VPN passwords, and Active Directory credentials).

Everything in this section describes `App\Libraries\BackupEngine` and `App\Controllers\Backup` as they behave, driven from the Backup page or `microctl backup …`. One thing is worth stating up front, because an earlier revision of this section described a design that was never built: **an archive is a single JSON document, not a tar container.** There is no `manifest.json`, no `config.sql`, no `database.db` and no `secrets/` directory; the identity of the archive is the JSON header itself, the configuration is a `tables` map in the same document, and the upload control on the Backup page accepts `.mbk`, `.mbk.enc` and `.json`. Everything the earlier revision promised and that is *not* built — an Argon2id derivation, a tar container, keyed integrity for the plain format, scheduled offsite export — is listed as planned in §17.7 rather than described as present.

```
                             BACKUP CREATION PIPELINE
                                        │
           ┌────────────────────────────┴────────────────────────────┐
           ▼                                                         ▼
   Plain Mode (.mbk)                                      Encrypted Mode (.mbk.enc)
   ├── Plaintext JSON Document                            ├── Master Passphrase Input (12+ chars)
   ├── `tables` map of configuration rows                 ├── PBKDF2-SHA256 Key Derivation (100 000 rounds)
   ├── No credential, no master key, ever                 ├── AES-256-GCM Authenticated Encryption
   └── SHA-256 payload digest in the register             └── Tamper-Proof 128-bit Auth Tag
           │                                                         │
           └────────────────────────────┬────────────────────────────┘
                                        │
                                        ▼
                             Storage & Export Targets
                    ├── Direct Browser Download (Instant)
                    ├── Local Appliance Store (writable/backups, manual deletion)
                    └── (planned) Retention Rotation, SFTP / SCP,
                        S3-Compatible Storage, Micron Central Push
```

### 17.1 Backup Package Structure & File Formats

| Mode | Extension | Container | Encryption |
| :--- | :--- | :--- | :--- |
| Plain | `.mbk` | JSON document | none |
| Encrypted | `.mbk.enc` | JSON envelope wrapping the JSON payload | AES-256-GCM |

#### Payload document (a `.mbk`, and the plaintext inside a `.mbk.enc`)

The payload is one JSON object, written with `JSON_PRETTY_PRINT` so a plain archive stays readable and diffable in the field — which is the reason the format is a configuration document rather than a tar of a SQL dump:

```json
{
  "format": 2,
  "micronos_version": "1.0.0",
  "timestamp": "2026-10-06T11:30:09+00:00",
  "hostname": "fw-primary.branch01.corp",
  "is_encrypted": true,
  "credentials_included": true,
  "hardware": {
    "interfaces": [ { "name": "eth0", "physical_device": "eth0", "ipv4_address": "203.0.113.2" } ]
  },
  "tables": {
    "interfaces": [ { "...": "one object per row" } ],
    "firewall_rules": [ { "...": "..." } ]
  },
  "secrets": {
    "master_key": "9f1d5c2e…",
    "key_version": 1,
    "created_at": "2026-10-06T11:30:09+00:00"
  }
}
```

- `format` is the version discriminator (`2` today) and `micronos_version` records the application version string the archive was written by.
- `hardware.interfaces` repeats the `interfaces` rows under a name that survives a schema change, and is the inventory the planned NIC-mismatch detection of §17.5 would read.
- `tables` is the configuration itself: one entry per table, each an array of rows exactly as they sit in the database, so a restore is an insert rather than a parse.
- `secrets` appears only in an encrypted archive, and only when the appliance actually has a connector master key to carry: it holds that key, deliberately outside `tables` so no restore can write key material into a database table by accident (§7.6).
- `credentials_included` is what the Backup page and `system_backups` report, so a console operator can see whether an archive is credential-bearing without opening it.

#### Table coverage

Archived unconditionally: `interfaces`, `firewall_rules`, `nat_dnat`, `nat_snat_pools`, `nat_nptv6`, `nat_settings`, `ipsec_tunnels`, `wireguard_tunnels`, `wireguard_peers`, `openvpn_servers`, `webfilter_settings`, `webfilter_categories`, `ha_settings`, `ha_virtual_ips`, `qos_shapers`, `qos_rules`, `pki_authorities`, `pki_certificates`, `roles`, `permissions`, `role_permissions`, `users`, `user_roles`, `ldap_servers`.

Archived when the table exists on the appliance: `plugin_repos` (the curated-repository trust anchors of §5.2, in both archive forms, because a restored appliance must reach its Class C packages without the vendor still serving the key), the installed-plugin state `plugins` and `plugin_grants` (no secret: a manifest is public and a grant is a capability the operator already approved), plus the Phase 2 connector tables `connector_instances` and `connector_targets`. Included **only inside an encrypted archive**: `connector_secrets`, the connector master key, and any `plugin_settings` row whose `is_secret` flag is set — the non-secret rows of `plugin_settings` travel in both forms, like the rest of the plugin configuration.

A plaintext archive therefore never contains a connector credential, nor a plugin's secret-valued setting, nor the means to read one. A restore writes `plugin_settings` by replacing only the rows an archive names, so a plaintext archive — which by design carries no secret rows — never truncates away the plugin secrets already on the target.

#### Register

Every archive written locally is recorded in `system_backups` (filename, path, byte size, `is_encrypted`, `credentials_included`, `sha256_checksum`, `backup_type`, `storage_destination`, `created_by`, description, timestamp). The register is what the Backup page lists, what `microctl backup verify` sweeps, and what a restore looks up when it has to decide whether an archive was tampered with (§17.3).

---

### 17.2 Cryptographic Envelope Architecture (`.mbk.enc`)

1. **Passphrase input:** at least **12 characters**, in the Web UI and in `microctl backup create --passphrase` alike, because the passphrase stands between a recovered archive and every connector credential it carries. The Web UI additionally refuses a passphrase identical to the operator's login password.
2. **Key derivation (PBKDF2-SHA256):** a cryptographically random **16-byte salt** per archive is generated with `random_bytes()`, and the passphrase is stretched with 100 000 iterations of HMAC-SHA256 into a 256-bit key. The salt travels in the envelope, and the magic string is versioned (`MICRON_ENC_V1`), so a later change of derivation has somewhere to be distinguished instead of silently misread — with the current derivation kept for reading archives already written.
3. **Authenticated encryption (AES-256-GCM):** a unique **12-byte nonce** per archive, producing ciphertext and a **16-byte authentication tag**.
4. **Envelope assembly:** the envelope is a JSON object, so the header is self-describing rather than a fixed binary offset — an archive can be inspected, and a future format change detected, with nothing more than a JSON reader:

   | Field | Size | Purpose |
   | :--- | :--- | :--- |
   | `magic` | — | The literal `MICRON_ENC_V1`, which identifies an encrypted archive |
   | `salt` | 16 bytes (base64) | Salt for the key derivation |
   | `iv` | 12 bytes (base64) | AES-GCM nonce |
   | `tag` | 16 bytes (base64) | GCM integrity and authentication tag |
   | `ciphertext` | variable (base64) | The encrypted payload document of §17.1 |

5. **Tamper detection guarantee:** the GCM tag is validated **before any plaintext is written to disk**, and a wrong passphrase, an altered byte or a truncated file all fail the same way — decryption returns nothing and the restore aborts with an error, leaving the appliance's configuration untouched.
6. **Recognition is structural:** `BackupEngine::isEncryptedArchive()` reads the `magic` field rather than searching the file for the marker, so a *plaintext* payload that merely mentions `MICRON_ENC_V1` — an archive of a configuration that quotes this documentation, for instance — is not mistaken for an encrypted one and asked for a passphrase it does not have.

---

### 17.3 Unencrypted Plain Mode (`.mbk`)

For rapid snapshots inside an isolated, physically secure management network the same payload document is written without encryption, which keeps the archive inspectable and diffable:

- **Security warning in the UI:** when "Without Encryption" is selected the Web interface states that the archive contains sensitive plaintext configuration and must be stored on access-controlled media. It is a warning rather than an acknowledgement step; the connector credentials and the connector master key are simply not in a plain archive, so the exposure is configuration rather than secrets.
- **Integrity, not authenticity:** the SHA-256 in the register covers the **plaintext payload**, and is the value `microctl backup verify` recomputes. It detects accidental corruption — the archive that was half-copied, or that a storage layer silently truncated. It is an unkeyed digest stored beside the archive's own metadata, so it is not a MAC and is not offered as one: an attacker who can rewrite the archive can rewrite the digest too. Authenticity comes from the GCM tag, and therefore from encrypting the archive.
- **Digest semantics depend on the mode, and this is the subtlety every caller has to get right:** a plain archive's digest is the digest of the bytes on disk; an encrypted archive's digest covers the plaintext, so it **cannot be recomputed without the passphrase**. `microctl backup verify` reports such an archive as `UNVERIFIABLE` rather than verified — never as OK — and only fails on it when `--strict` is given. The recorded **byte size** needs no passphrase, which is what still catches a truncated or half-copied encrypted archive on a console where the passphrase is not to hand.

---

### 17.4 Restoration Engine & Safety Workflow

```
                       ADMIN UPLOADS BACKUP (.mbk / .mbk.enc)
                                       │
                                       ▼
                       [ DECRYPTION & FORMAT CHECK ]
                       ├── Envelope magic present? ──► AES-256-GCM decrypt, tag validated
                       ├── JSON decoded
                       └── `tables` present, else "corrupt or incompatible package"
                                       │
                                       ▼
                       [ SELECTION & REMAPPING ]
                       ├── Selective sections (9, below)
                       └── Old NIC → new NIC map, applied to interfaces.physical_device
                                       │
                                       ▼
                       [ ATOMIC DATABASE RESTORE (ONE TRANSACTION) ]
                       ├── Connector key policy first: install / match / replace-with-confirmation
                       ├── Per table: truncate, then insert every archived row
                       └── Failure ──► transaction rolls back, previous configuration kept
                                       │
                                       ▼
                       [ POST-RESTORE VERIFICATION ]
                       ├── Every restored connector secret is decryption-verified
                       └── Unusable key ──► connectors flagged `needs_credentials`
                                       │
                                       ▼
                       [ DAEMON RECONCILE ]
                       ├── Curated plugin repositories re-applied to apt (declarative, idempotent)
                       └── `system.commit` — micrond recompiles and applies the restored config
```

**Selective restoration** is a curated map rather than a free choice of tables, so an operator cannot restore half a subsystem: `network` (`interfaces`), `firewall` (`firewall_rules`, `nat_dnat`, `nat_snat_pools`, `nat_nptv6`, `nat_settings`), `vpn` (`openvpn_servers`, `wireguard_tunnels`, `wireguard_peers`, `ipsec_tunnels`), `ha` (`ha_settings`, `ha_virtual_ips`), `qos` (`qos_shapers`, `qos_rules`), `certificates` (`pki_authorities`, `pki_certificates`), `identity` (`users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `ldap_servers`), `webfilter` (`webfilter_settings`, `webfilter_categories`) and `repositories` (`plugin_repos`). Selecting nothing restores every table the archive carries. A section may only name tables the archive actually carries, which is asserted by `BackupRepositoryRestoreTest`.

**Atomicity, stated precisely.** The table restore runs inside a single database transaction: every truncate and insert either lands or the transaction rolls back and the previous configuration is kept, and the flash message says so. What is **not** implemented is the staged activation this section previously described — staging the restored configuration in a sandbox, applying it under a 60-second watchdog and auto-rolling-back an apply that is not confirmed within it. A restore commits its database state and then asks `micrond` to recompile; if the daemon refuses that commit, the restore report does not yet say so. That belongs with the commit watchdog work in §17.7.

**Degraded restores are reported, never hidden** (§7.6). A connector master key that is absent, or different from this appliance's and not replaced with explicit confirmation, makes the connector ciphertext undecryptable — so those rows are skipped, the connectors are marked `needs_credentials`, and the result is surfaced as an error-level notice naming what must be re-entered, rather than an unqualified "success".

---

### 17.5 Hardware Interface Remapping Wizard

Restoring a backup taken from a 4-port appliance (`eth0`…`eth3`) onto replacement hardware whose kernel names its ports `enp1s0`, `enp2s0`… would otherwise leave every policy bound to an interface that does not exist:

- The restore form takes an **old → new interface map** (repeated source/target pairs on the Backup page, or `--nic-map eth0=enp1s0,eth1=enp2s0` on `microctl backup restore`), and the map is applied to `interfaces.physical_device` before the rows are written — so VLAN parents and firewall bindings, which reference the interface record, follow the remap.
- The map is **operator-supplied**, because only the operator knows which physical port took over which role. `BackupEngine::inspectHardwareDifferences()` exists to compare the archive's NIC inventory against the appliance's current one and name the missing and new devices, but nothing calls it yet: the wizard that detects a mismatch and pre-fills the matrix is planned (§17.7). Until then a restore onto dissimilar hardware needs the map typed in, and a restore that does not need one simply omits it.

---

### 17.6 Automated Scheduled Backups & Remote Export — *planned*

**None of this is built.** An archive is created when an operator creates one, from the Backup page or `microctl backup create`; the register's `storage_destination` column currently only ever holds `local`, and `microctl backup verify` treats any other value as `REMOTE` — a row registered against a destination the appliance cannot read — rather than pretending an offsite copy exists. Specifically:

- **Scheduling:** nothing creates a snapshot on a timer. The appliance *seeds* a `cron_backup_nightly` job (migration `000028`) that is **disabled and carries no run history**: it names `php spark backup:create`, the engine behind `microctl backup create`, so the preset is real and an administrator can enable it deliberately. It ships off because neither retention (below) nor a passphrase source exists — an enabled job could only write unbounded **plaintext** archives. What the seed used to declare is gone: an enabled job running a `/usr/local/bin` helper that ships nowhere in the tree, reporting `last_status: success` over an encrypted `.embk` archive no code emits — an appliance asserting a backup it had never taken. Migration `2026-10-06-000099` corrects both on installs that already hold the row. Create snapshots from the Backup page or `microctl backup create`.
- **Retention:** nothing prunes. The local store (`writable/backups/`) grows until an operator deletes an archive from the Backup page, which removes the file and its register row together.
- **Remote export:** there is no SFTP/SCP, S3-compatible or Micron Central transfer code. A register row labelled `s3` or `central` records an intent that nothing acts on.

Implementing any of these means implementing the transfer *and* the failure reporting, because a scheduled backup that silently fails is worse than no schedule: the first sign of trouble would be a restore that has nothing to restore. It also means giving the seeded row a binary to call, or removing it.

---

### 17.7 Backup & Disaster Recovery — Implemented vs Planned

| Capability | State |
| :--- | :--- |
| Plain JSON archive (`.mbk`) with SHA-256 payload digest in the register | Implemented |
| AES-256-GCM envelope (`.mbk.enc`), PBKDF2-SHA256, 12-character minimum passphrase | Implemented |
| Selective restore by curated section, NIC remapping, connector key policy and post-restore credential verification | Implemented |
| `microctl backup create` / `restore` / `verify`, including the `--strict` drift gate | Implemented |
| Argon2id key derivation (memory-hard, GPU-resistant) in place of PBKDF2 | Planned — needs `ext-sodium` (`php8.4-sodium`) in the ISO package manifest, with the current derivation kept for reading archives already written |
| Gzip tar container with `manifest.json`, `config.sql`, `database.db` and `secrets/` | Planned — the JSON document is the shipped format; a container is only worth it when archives must carry files as well as rows |
| Keyed integrity (HMAC) for plaintext archives | Planned — and only meaningful with a key that does not travel in the archive, or it cannot be verified on the replacement hardware the plain format exists for |
| Explicit `format` validation on read (today only `tables` is required) | Planned |
| Scheduled snapshots, local retention/rotation, SFTP/S3/Central export | Planned — §17.6. The seeded `cron_backup_nightly` preset names `php spark backup:create` and ships disabled with no run history, so nothing is scheduled and nothing claims a backup happened |
| Staged restore activation under a 60-second watchdog with automatic rollback, and a restore that reports a refused daemon commit | Planned |
| Automatic NIC-mismatch detection and a pre-filled remapping matrix | Planned — `inspectHardwareDifferences()` exists, unwired (§17.5) |
---

## 18. Unified Database Schema (Complete DDL for SQLite & MySQL)

The complete configuration schema is structured for 100% interoperability across both SQLite 3 (edge) and MySQL 8.4 / MariaDB (enterprise):

```sql
-- 1. Interfaces & Physical Ports
CREATE TABLE interfaces (
    id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    physical_device VARCHAR(32) NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    ip_assignment_type VARCHAR(16) NOT NULL, -- 'static', 'dhcp', 'pppoe'
    ipv4_address VARCHAR(45) NULL,
    ipv4_netmask INT NULL,
    ipv4_gateway VARCHAR(45) NULL,
    ipv6_assignment_type VARCHAR(16) DEFAULT 'disabled',
    ipv6_address VARCHAR(45) NULL,
    ipv6_prefix INT NULL,
    mtu INT DEFAULT 1500,
    vlan_tag INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Firewall Filter Rules
CREATE TABLE firewall_rules (
    id VARCHAR(36) PRIMARY KEY,
    sequence INT NOT NULL,
    interface_id VARCHAR(32) NOT NULL,
    action VARCHAR(8) NOT NULL,              -- 'pass', 'block', 'reject'
    direction VARCHAR(8) NOT NULL,           -- 'in', 'out'
    protocol VARCHAR(16) NOT NULL,          -- 'tcp', 'udp', 'icmp', 'any'
    source_type VARCHAR(16) NOT NULL,        -- 'any', 'network', 'ip', 'alias'
    source_value VARCHAR(128) NOT NULL,
    source_port VARCHAR(32) NULL,
    dest_type VARCHAR(16) NOT NULL,
    dest_value VARCHAR(128) NOT NULL,
    dest_port VARCHAR(32) NULL,
    log_enabled TINYINT(1) DEFAULT 0,
    description TEXT NULL,
    enabled TINYINT(1) DEFAULT 1,
    FOREIGN KEY (interface_id) REFERENCES interfaces(id) ON DELETE CASCADE
);

-- 3. NAT Rules (Port Forwarding & Masquerade)
CREATE TABLE nat_rules (
    id VARCHAR(36) PRIMARY KEY,
    rule_type VARCHAR(16) NOT NULL,          -- 'snat', 'dnat', 'masquerade'
    interface_id VARCHAR(32) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    source_address VARCHAR(128) NULL,
    dest_port INT NULL,
    target_ip VARCHAR(45) NOT NULL,
    target_port INT NULL,
    description TEXT NULL,
    enabled TINYINT(1) DEFAULT 1,
    FOREIGN KEY (interface_id) REFERENCES interfaces(id) ON DELETE CASCADE
);

-- 4. LDAP / Active Directory Directory Configurations
CREATE TABLE ldap_servers (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    hostname VARCHAR(128) NOT NULL,
    port INT DEFAULT 636,
    encryption VARCHAR(16) DEFAULT 'ldaps',   -- 'none', 'starttls', 'ldaps'
    base_dn VARCHAR(255) NOT NULL,
    bind_dn VARCHAR(255) NOT NULL,
    bind_password VARCHAR(255) NOT NULL,
    user_search_base VARCHAR(255) NOT NULL,
    user_search_filter VARCHAR(255) DEFAULT '(&(objectClass=user)(sAMAccountName={username}))',
    group_search_base VARCHAR(255) NULL,
    admin_group_mapping VARCHAR(255) NULL,
    schema_type VARCHAR(16) DEFAULT 'ad',      -- 'ad' (Active Directory), 'openldap' (RFC 4519)
    group_attribute VARCHAR(32) DEFAULT 'memberOf', -- 'memberOf', 'member', 'memberUid'
    default_role_id VARCHAR(36) NULL,
    timeout INT DEFAULT 5,
    fallback_local TINYINT(1) DEFAULT 1,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4.1 Enterprise RADIUS AAA Servers (RFC 2865 / RFC 2866)
CREATE TABLE radius_servers (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    hostname VARCHAR(128) NOT NULL,
    auth_port INT DEFAULT 1812,
    acct_port INT DEFAULT 1813,
    secret VARCHAR(255) NOT NULL,              -- Shared secret key
    auth_type VARCHAR(16) DEFAULT 'pap',       -- 'pap', 'chap', 'mschapv2'
    nas_identifier VARCHAR(128) NULL,
    nas_ip VARCHAR(45) NULL,
    timeout INT DEFAULT 5,
    retries INT DEFAULT 3,
    acct_enabled TINYINT(1) DEFAULT 0,
    acct_server VARCHAR(128) NULL,
    default_role_id VARCHAR(36) NULL,
    group_attribute VARCHAR(32) DEFAULT 'Filter-Id', -- 'Filter-Id', 'Class', 'Cisco-AVPair'
    is_primary TINYINT(1) DEFAULT 1,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4.2 External Group to RBAC Role Mappings
CREATE TABLE auth_group_mappings (
    id VARCHAR(36) PRIMARY KEY,
    auth_type VARCHAR(16) DEFAULT 'ldap',      -- 'ldap', 'radius'
    server_id VARCHAR(36) NULL,                -- Target server ID or NULL for global
    external_group VARCHAR(255) NOT NULL,      -- AD/LDAP Group DN/CN or RADIUS Attribute value
    role_id VARCHAR(36) NOT NULL,              -- Target MicronOS Role ID
    priority INT DEFAULT 10,                   -- Resolution priority (lower number = higher precedence)
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- 5. System Users & Two-Factor Authentication
CREATE TABLE users (
    id VARCHAR(36) PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(128) NULL,
    password_hash VARCHAR(255) NULL,           -- NULL for LDAP / RADIUS users
    auth_source VARCHAR(16) DEFAULT 'local',    -- 'local', 'ldap', 'radius'
    role VARCHAR(32) DEFAULT 'Operator',        -- Default role slug or UUID
    ldap_dn VARCHAR(255) NULL,                 -- Cached Directory Distinguished Name
    external_group VARCHAR(255) NULL,          -- Cached Directory / RADIUS Group
    totp_enabled TINYINT(1) DEFAULT 0,
    totp_secret VARCHAR(128) NULL,             -- AES-256-GCM encrypted
    totp_scratch_codes TEXT NULL,               -- Hashed single-use recovery codes
    webauthn_enabled TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. OpenVPN Road Warrior Configuration
CREATE TABLE openvpn_servers (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    interface_name VARCHAR(16) DEFAULT 'tun0',
    protocol VARCHAR(8) DEFAULT 'udp',        -- 'udp', 'tcp'
    listen_port INT DEFAULT 1194,
    tunnel_subnet VARCHAR(45) NOT NULL,       -- e.g. 10.8.0.0/24
    auth_mode VARCHAR(24) DEFAULT 'cert_ldap_2fa', -- 'cert_only', 'cert_user', 'cert_ldap_2fa'
    ldap_server_id VARCHAR(36) NULL,
    totp_enforced TINYINT(1) DEFAULT 1,
    dns_servers TEXT NULL,
    routes_pushed TEXT NULL,
    enabled TINYINT(1) DEFAULT 1,
    FOREIGN KEY (ldap_server_id) REFERENCES ldap_servers(id)
);

-- 7. WireGuard Tunnels & Ephemeral 2FA Leases
CREATE TABLE wireguard_tunnels (
    id VARCHAR(36) PRIMARY KEY,
    interface_name VARCHAR(32) NOT NULL UNIQUE,
    listen_port INT DEFAULT 51820,
    private_key VARCHAR(128) NOT NULL,
    public_key VARCHAR(128) NOT NULL,
    ipv4_address VARCHAR(45) NOT NULL,
    ipv4_subnet INT NOT NULL,
    enabled TINYINT(1) DEFAULT 1
);

CREATE TABLE wireguard_peers (
    id VARCHAR(36) PRIMARY KEY,
    tunnel_id VARCHAR(36) NOT NULL,
    name VARCHAR(64) NOT NULL,
    public_key VARCHAR(128) NOT NULL,
    preshared_key VARCHAR(128) NULL,
    endpoint VARCHAR(128) NULL,
    allowed_ips TEXT NOT NULL,
    persistent_keepalive INT DEFAULT 25,
    enabled TINYINT(1) DEFAULT 1,
    FOREIGN KEY (tunnel_id) REFERENCES wireguard_tunnels(id) ON DELETE CASCADE
);

CREATE TABLE vpn_ephemeral_leases (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    vpn_type VARCHAR(16) NOT NULL,           -- 'wireguard', 'openvpn', 'ipsec'
    assigned_ip VARCHAR(45) NOT NULL,
    client_public_key VARCHAR(128) NULL,
    authenticated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    status VARCHAR(16) DEFAULT 'active',     -- 'active', 'expired', 'revoked'
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 7.1 IPsec / strongSwan Tunnels
CREATE TABLE ipsec_tunnels (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    ike_version VARCHAR(8) DEFAULT 'ikev2',   -- 'ikev1', 'ikev2'
    mode VARCHAR(16) DEFAULT 'site2site',     -- 'site2site', 'roadwarrior'
    remote_gateway VARCHAR(128) NULL,
    auth_type VARCHAR(16) DEFAULT 'psk',      -- 'psk', 'rsa', 'eap'
    psk_secret VARCHAR(255) NULL,
    local_subnet VARCHAR(128) DEFAULT '192.168.1.0/24',
    remote_subnet VARCHAR(128) NULL,
    ike_cipher VARCHAR(64) DEFAULT 'aes256gcm16-prfsha384-ecp384',
    esp_cipher VARCHAR(64) DEFAULT 'aes256gcm16-ecp384',
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Web Filtering & Proxy Settings
CREATE TABLE webfilter_settings (
    id INT PRIMARY KEY DEFAULT 1,
    mode VARCHAR(24) NOT NULL,              -- 'disabled', 'transparent_sni', 'ssl_bump'
    http_port INT DEFAULT 3128,
    https_port INT DEFAULT 3129,
    icap_antivirus_enabled TINYINT(1) DEFAULT 1,
    safesearch_enabled TINYINT(1) DEFAULT 1,
    youtube_restricted_mode VARCHAR(16) DEFAULT 'moderate',
    cache_enabled TINYINT(1) DEFAULT 0,
    cache_size_mb INT DEFAULT 4096,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE webfilter_categories (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,              -- 'Adult', 'Gambling', 'SocialMedia', 'Malware'
    action VARCHAR(16) NOT NULL,            -- 'block', 'allow', 'bypass_ssl'
    description TEXT NULL,
    enabled TINYINT(1) DEFAULT 1
);

-- 9. Backup Manifests & Scheduled Jobs
CREATE TABLE system_backups (
    id VARCHAR(36) PRIMARY KEY,
    filename VARCHAR(128) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT NOT NULL,
    is_encrypted TINYINT(1) DEFAULT 1,
    sha256_checksum VARCHAR(64) NOT NULL,
    backup_type VARCHAR(16) DEFAULT 'full',  -- 'full', 'selective'
    storage_destination VARCHAR(32) DEFAULT 'local', -- 'local', 'sftp', 's3', 'central'
    created_by VARCHAR(64) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 10. Transactional Engine & Audit Logging
CREATE TABLE config_transactions (
    id VARCHAR(36) PRIMARY KEY,              -- generated by the application, not the database
    admin_user VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL,             -- 'staged', 'applied', 'confirmed', 'rolled_back'
    candidate_hash VARCHAR(64) NOT NULL,
    backup_path VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NULL,               -- when the candidate config reached the appliance
    confirmed_at TIMESTAMP NULL              -- resolution stamp: confirmed OR rolled back
);

CREATE TABLE audit_logs (
    id BIGINT PRIMARY KEY,
    admin_user VARCHAR(64) NOT NULL,
    client_ip VARCHAR(45) NOT NULL,
    action_type VARCHAR(64) NOT NULL,
    target_resource VARCHAR(128) NOT NULL,
    old_state JSON NULL,
    new_state JSON NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- The four NOT NULL columns above are not a preference: every appliance built
-- before their nullability was relaxed already enforces them, so a writer that
-- supplies only what the current migration file declares fails there — inside a
-- bare `catch (\Throwable)` that discards the reason. Every writer now supplies
-- all four. `tests/app/Models/ModelSchemaConsistencyTest.php` holds every model's
-- declared table, columns, timestamps, casts, validation rules and primary key to
-- the schema the migrations build, and fails any insert that omits a required
-- column or keys a column no table has.

-- 11. PPPoE Subscriber Access Server (BRAS), RADIUS & EAP-TLS
CREATE TABLE pppoe_servers (
    id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    interface VARCHAR(32) NOT NULL,
    service_name VARCHAR(64) NULL,
    ac_name VARCHAR(64) NULL,
    local_ip VARCHAR(45) NOT NULL,
    remote_start VARCHAR(45) NOT NULL,
    remote_end VARCHAR(45) NOT NULL,
    dns1 VARCHAR(45) NULL,
    dns2 VARCHAR(45) NULL,
    auth_required TINYINT(1) DEFAULT 1,
    auth_backend VARCHAR(16) DEFAULT 'local',   -- 'local' | 'radius'
    radius_server VARCHAR(128) NULL,
    radius_port INT DEFAULT 1812,
    radius_secret VARCHAR(255) NULL,            -- encrypted at rest
    radius_timeout INT DEFAULT 5,
    radius_retries INT DEFAULT 3,
    radius_nas_id VARCHAR(128) NULL,
    radius_server2 VARCHAR(128) NULL,           -- failover auth server
    radius_accounting TINYINT(1) DEFAULT 0,
    radius_acct_port INT DEFAULT 1813,
    radius_acct_server VARCHAR(128) NULL,
    eap_tls TINYINT(1) DEFAULT 0,
    eap_ca_cert TEXT NULL,
    eap_server_cert TEXT NULL,
    eap_server_key TEXT NULL,                   -- encrypted at rest
    eap_client_cert TEXT NULL,
    eap_crl TEXT NULL,
    idle_timeout INT DEFAULT 300,
    max_sessions INT DEFAULT 64,
    description TEXT NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pppoe_server_users (
    id VARCHAR(36) PRIMARY KEY,
    server_id VARCHAR(32) NOT NULL,
    username VARCHAR(128) NOT NULL,
    password VARCHAR(255) NULL,                 -- encrypted at rest
    static_ip VARCHAR(45) NULL,
    dns1 VARCHAR(45) NULL,
    dns2 VARCHAR(45) NULL,
    rate_limit VARCHAR(64) NULL,                -- down/up in kbps
    description TEXT NULL,
    enabled TINYINT(1) DEFAULT 1,
    FOREIGN KEY (server_id) REFERENCES pppoe_servers(id) ON DELETE CASCADE
);

CREATE TABLE pppoe_backoff_states (
    interface VARCHAR(32) PRIMARY KEY,
    failure_count INT DEFAULT 0,
    last_failure_at DATETIME NULL,
    next_retry_at DATETIME NULL,
    last_reason TEXT NULL,
    alert_count INT DEFAULT 0,
    updated_at DATETIME NULL
);

CREATE TABLE pppoe_session_history (
    id VARCHAR(36) PRIMARY KEY,
    subscriber_id VARCHAR(32) NULL,
    username VARCHAR(128) NOT NULL,
    server_id VARCHAR(32) NULL,
    interface VARCHAR(32) NULL,
    assigned_ip VARCHAR(45) NULL,
    login_at DATETIME NOT NULL,
    logout_at DATETIME NULL,
    uptime_seconds INT DEFAULT 0,
    terminate_cause VARCHAR(64) NULL,
    rx_bytes BIGINT DEFAULT 0,
    tx_bytes BIGINT DEFAULT 0,
    rate_limit VARCHAR(64) NULL,
    session_timeout INT NULL,
    idle_timeout INT NULL,
    interim_interval INT NULL,
    framed_ip VARCHAR(45) NULL,
    radius_attributes TEXT NULL,               -- JSON reply-attribute map
    created_at DATETIME NOT NULL
);

-- 12. Policy-Based Routing, Multi-WAN & Internet Service Database
CREATE TABLE routing_pbr_rules (
    id VARCHAR(36) PRIMARY KEY,
    sequence INT NOT NULL,
    source VARCHAR(128) NULL,
    match_objects TEXT NULL,                    -- JSON: services, FQDNs, ports, DSCP
    gateway VARCHAR(128) NULL,
    vrf VARCHAR(64) NULL,
    enabled TINYINT(1) DEFAULT 1,
    description TEXT NULL
);

CREATE TABLE wan_gateways (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    address VARCHAR(128) NULL,
    interface VARCHAR(32) NULL,
    monitor_ip VARCHAR(45) NULL,
    weight INT DEFAULT 1,
    enabled TINYINT(1) DEFAULT 1
);

CREATE TABLE wan_gateway_groups (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    trigger VARCHAR(32) DEFAULT 'down',         -- 'down' | 'loss' | 'latency'
    strategy VARCHAR(16) DEFAULT 'weighted',    -- 'round-robin' | 'weighted' | 'primary'
    sticky TINYINT(1) DEFAULT 0,                -- pin each flow to one member
    enabled TINYINT(1) DEFAULT 1
);

CREATE TABLE wan_gateway_group_members (
    group_id VARCHAR(36) NOT NULL,
    gateway_id VARCHAR(36) NOT NULL,
    weight INT DEFAULT 1,
    position INT DEFAULT 0,
    PRIMARY KEY (group_id, gateway_id)
);

CREATE TABLE internet_services (
    id VARCHAR(36) PRIMARY KEY,
    key VARCHAR(64) NOT NULL UNIQUE,            -- catalogue key
    name VARCHAR(128) NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    resolved_prefixes TEXT NULL,                -- cached CIDR list
    prefix_count INT DEFAULT 0,
    last_sync_at DATETIME NULL,
    last_status VARCHAR(32) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 13. DNS Resolver (Unbound): Settings, DNSBL Feeds & Local Overrides
CREATE TABLE dns_settings (
    id VARCHAR(32) PRIMARY KEY,                 -- single 'global' row
    enable_dnssec TINYINT(1) DEFAULT 1,
    enable_dnsbl TINYINT(1) DEFAULT 1,
    upstream_mode VARCHAR(32) DEFAULT 'dot',    -- 'standard' or 'dot' (DNS-over-TLS)
    upstream_servers TEXT NULL,                 -- JSON array of upstream resolvers
    cache_min_ttl INT DEFAULT 60,
    cache_max_ttl INT DEFAULT 86400,
    blocked_query_action VARCHAR(32) DEFAULT 'nxdomain', -- 'nxdomain' or '0.0.0.0'
    dns_server_override TINYINT(1) DEFAULT 1,    -- DHCP/PPP/OpenVPN-assigned servers may override the list
    dns_resolution_behavior VARCHAR(32) DEFAULT 'local-fallback', -- local-fallback | local-only | remote-only
    updated_at DATETIME NULL
);

CREATE TABLE dynamic_dns_servers (              -- DHCP/PPP/OpenVPN-assigned resolvers (DNS Server Override)
    id VARCHAR(64) PRIMARY KEY,
    source VARCHAR(16) NOT NULL,                -- dhcp | ppp | openvpn
    interface_id VARCHAR(64) NULL,              -- interfaces.id when the link is a configured interface
    interface_name VARCHAR(64) NULL,            -- daemon-visible link name, e.g. WAN
    address VARCHAR(45) NOT NULL,               -- literal IPv4/IPv6 resolver
    assigned_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE TABLE dns_feeds (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    url TEXT NOT NULL,
    category VARCHAR(64) DEFAULT 'Ads & Tracking',
    entries_count INT DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    last_updated DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE TABLE dns_overrides (                   -- DNSBL whitelist / blacklist rules
    id VARCHAR(64) PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    action VARCHAR(16) DEFAULT 'blacklist',     -- 'whitelist' or 'blacklist'
    is_wildcard TINYINT(1) DEFAULT 0,
    description VARCHAR(255) NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE TABLE dns_hosts (                       -- Local DNS Host Overrides (split-horizon A records)
    id VARCHAR(36) PRIMARY KEY,
    hostname VARCHAR(128) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

CREATE TABLE dns_domain_overrides (            -- Domain Overrides (per-domain forwarding)
    id VARCHAR(36) PRIMARY KEY,
    domain VARCHAR(253) NOT NULL,               -- zone forwarded to dns_server
    dns_server VARCHAR(64) NOT NULL,            -- forwarder as an IPv4/IPv6 literal
    port INT DEFAULT 53,
    description VARCHAR(255) NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);

-- firewall_addresses additionally supports resolved objects (migration 49):
--   object_type IN ('host','network','fqdn','internet-service','geoip')
--   resolved_prefixes TEXT   -- cached CIDRs from offline resolution
```

---

## 19. CodeIgniter 4 Management Plane Architecture & CLI (`microctl`)

```
micronos/
├── app/
│   ├── Config/
│   │   ├── Database.php          # Dynamic SQLite (local) or MySQL (remote)
│   │   ├── Routes.php            # Security & routing definitions
│   │   └── Firewall.php          # Appliance parameters
│   ├── Controllers/
│   │   ├── AuthController.php    # Local & LDAP Auth, TOTP 2FA, WebAuthn
│   │   ├── Dashboard.php         # Real-time WebSocket stats
│   │   ├── Interfaces.php        # Ethernet, VLANs, Bridges, PPPoE
│   │   ├── FirewallRules.php     # Rule ordering, inspection, aliases
│   │   ├── Nat.php               # Port forwards, Outbound NAT
│   │   ├── Routing.php           # Static, BGP, OSPF, Gateways
│   │   ├── ProxyWebfilter.php    # Squid, SNI Filtering, SSL-Bump, Categories
│   │   ├── (ICAP scanner)        # SquidConfigCompiler ICAP stanzas + WebfilterAvDetectionModel
│   │   ├── VpnOpenvpn.php        # OpenVPN Server, 2FA dynamic challenge, certs
│   │   ├── VpnWireguard.php      # Peer generator, QR codes, keepalives
│   │   ├── VpnUserPortal.php     # Self-service .ovpn bundles & WireGuard 2FA
│   │   ├── ServicesDhcp.php      # Kea DHCP leases and static reservations
│   │   ├── ServicesDns.php       # Unbound DNS, Sinkhole blocklists
│   │   ├── SystemBackup.php      # Encrypted/Plain backups, S3/SFTP sync, restore wizard
│   │   ├── SystemCommit.php      # watchdog transactional engine
│   │   ├── Pppoe.php             # PPPoE client + access server, RADIUS, EAP-TLS
│   │   └── InternetServices.php  # Internet Service DB, FQDN / GeoIP resolution
│   ├── Libraries/
│   │   ├── BackupEngine.php      # AES-256-GCM + PBKDF2-SHA256 encryption & JSON archive packaging
│   │   ├── RestoreManager.php    # Pre-flight check, schema migration, NIC remap compiler
│   │   ├── LdapClient.php        # Active Directory / OpenLDAP wrapper
│   │   ├── TotpEngine.php        # RFC 6238 TOTP verification & QR generation
│   │   ├── IpcClient.php         # Communicates with /run/micron/micrond.sock
│   │   ├── NftablesCompiler.php  # Emits the micron_filter / micron_nat rulesets
│   │   ├── PppoeCompiler.php     # PPPoE peers/secrets, RADIUS & EAP-TLS config
│   │   ├── RadiusAttributes.php  # RADIUS reply-attribute normalisation
│   │   ├── AddressResolver.php   # FQDN / Internet-Service / GeoIP → prefixes
│   │   ├── ServiceDatabase.php   # Public prefix-feed synchronisation
│   │   ├── CronJobLog.php        # Bounded scheduled-job run history
│   │   ├── ConfigCompiler.php    # Compiles DB records into nftables/openvpn/squid
│   │   ├── BlocklistUpdater.php  # Pulls Shallalist / UT1 / URLhaus updates
│   │   └── AuditLogger.php       # Comprehensive immutable logging
│   ├── Models/
│   │   ├── UserModel.php
│   │   ├── LdapModel.php
│   │   ├── BackupModel.php
│   │   ├── OpenvpnModel.php
│   │   ├── WireguardModel.php
│   │   ├── InterfaceModel.php
│   │   ├── WebfilterModel.php
│   │   ├── NatModel.php
│   │   └── RuleModel.php
│   └── Views/
│       ├── layouts/main.php      # Modern responsive dark/light dashboard
│       ├── auth/login.php        # Username/Password + TOTP 2FA input
│       ├── dashboard/index.php   # Live charts (Chart.js / ApexCharts)
│       ├── system/backup.php     # Encrypted / Plain backup download & S3 sync
│       ├── system/restore.php    # Restore upload, passphrase prompt, NIC remapper
│       ├── vpn/openvpn.php       # 2FA road warrior server config
│       ├── vpn/portal.php        # User self-service portal
│       ├── webfilter/index.php   # Category checklists, custom URL blocks
│       └── firewall/rules.php    # Drag-and-drop rule reordering
├── bin/
│   └── microctl                  # Terminal CLI management utility (0755)
│                                 #   staged into the image at /usr/local/bin by
│                                 #   packaging/live-build/stage-overlay.sh
├── daemon/                       # Go or Rust IPC root daemon (micrond)
│   ├── main.go
│   ├── compiler/
│   └── executor/
└── public/
    └── assets/                   # CSS, JS, SVG assets (no heavy frameworks)
```

### 19.1 Emergency Terminal CLI Tool (`microctl`)
For local console (VGA / Serial COM) recovery if Web access is disabled:
- `microctl status`: Displays interface IP addresses, gateway status, and CPU/RAM load.
- `microctl interface set-lan 192.168.1.1/24`: Reconfigures management LAN IP.
- `microctl firewall reset`: Restores default permissive LAN-to-WAN rules.
- `microctl backup create [--encrypt --passphrase "Correct-Horse-9!" ] [--out <dir>]`: Generates a configuration snapshot. A passphrase encrypts it (`.mbk.enc`, the authenticated `MICRON_ENC_V1` envelope); without one it is written plain (`.mbk`). It is stored in the appliance backup store and listed on the Backup page, so a console snapshot is first-class rather than a file nobody finds — `--out` writes it elsewhere. A passphrase shorter than 12 characters is refused, exactly as in the Web UI, because it is what stands between a recovered archive and every connector credential it carries.
- `microctl backup restore <path> [--passphrase <pw>] [--replace-key] [--nic-map <src:dst,...>] [--force]`: Restores a snapshot from the console. Encryption is detected from the envelope rather than the file extension, so a renamed archive still restores; a registered archive must still match the SHA-256 the Backup page recorded (`--force` overrides); the connector-key policy and the post-restore credential verification are the ones the engine already enforces; and the curated plugin repositories are re-applied to the daemon.
- `microctl backup verify [--passphrase <pw>] [--strict]`: Checks every archive in the register against the SHA-256 recorded for it when it was written, and exits non-zero if any has drifted or gone missing. The recorded digest covers the *payload*, not the bytes on disk, so an encrypted archive needs its passphrase before it can be recomputed; the recorded byte count needs none, which is what catches a truncated or half-copied encrypted archive on a console where the passphrase is not to hand. An archive that could not be checked is reported as unverifiable — never counted as verified, and not a failure unless `--strict` is given, which turns the sweep into a gate for cron.
- `microctl config rollback`: Discards the unconfirmed configuration transaction and has `micrond` reload the snapshot it captured before the last apply — the console equivalent of the layout's Rollback action. A rollback that fails is reported as a failure, never as a success.
- `microctl admin reset-password`: Resets local break-glass admin password and clears 2FA secret.

Each of these is a thin console front end over a Spark command of the same purpose (`backup:create`, `backup:restore`, `backup:verify`, `config:rollback`), which can also be run directly as `php spark <command>` on an appliance where `microctl` is not on the recovery shell's `PATH`.

**Packaging.** The ISO build stages `bin/microctl` into the appliance overlay as `/usr/local/bin/microctl` (0755), so the command is on root's `PATH` on the installed system, and its bash completion alongside it at `/usr/share/bash-completion/completions/microctl` for `bash-completion` to lazy-load. The same staging step — `packaging/live-build/stage-overlay.sh`, run by `auto/config` before `lb config` — stages the **whole web application** from the repository into `/usr/local/micron`, replacing that tree rather than merging into it, so the image cannot ship a tree that was hand-copied a release ago or copied minus a directory added since. What ships is an allow-list (`app`, `bin`, `installer`, `public`, `system`, `vendor`, `spark`, `preload.php`, the composer manifests, `env` and the licence/readme), which keeps development state out by construction: `.env`, `tests/`, `docs/`, `packaging/`, the build output and the `writable/` state — including the development configuration database — are never named, and `writable/` is staged as empty directories only. Because the application lives at `/usr/local/micron` while the CLI lives on `PATH`, the script resolves its own application root rather than assuming `dirname(__DIR__)` holds `spark`; `MICRON_ROOT` overrides the search for unusual deployments. The staging step and the package manifest are covered by `tests/packaging/TerminalCliOverlayTest.php`, which runs the staging script and executes the completion, and by `tests/packaging/WebAppOverlayTest.php`, which runs it, compares the staged application against the repository byte-for-byte in both directions, checks that no development state reached the overlay and boots the staged console, and by `tests/packaging/OfflineAssetOverlayTest.php`, which checks that every asset the UI references landed byte-for-byte inside the appliance's `DocumentRoot` — so an image cannot be built with the CLI, the application or the interface's assets silently missing or stale.

---

## 20. Appliance Lifecycle, Dual A/B Root Partitions & Hardware Watchdog

To prevent corruptions and ensure appliance reliability across thousands of remote branches:

```
┌────────────────────────────────────────────────────────┐
│               HARDWARE APPLIANCE STORAGE               │
├──────────────────────────┬─────────────────────────────┤
│ Slot A (SquashFS v1.0.0) │ Slot B (SquashFS v1.1.0)    │
│ Active / Read-Only       │ Inactive / Upgrade Target   │
├──────────────────────────┴─────────────────────────────┤
│ Persistent State Layer (OverlayFS /var /etc /data)     │
├────────────────────────────────────────────────────────┤
│ Configuration Database (/var/lib/micron/config.db)     │
└────────────────────────────────────────────────────────┘
```

1. **Read-Only Root Filesystems:** Base operating system files reside in read-only SquashFS images. Power failures cannot corrupt system binaries.
2. **Dual-Partition A/B Upgrades (design target — see status note below):** Firmware updates are written to the inactive slot. The bootloader boots the new slot; if kernel panic or network failure occurs within 3 minutes, the bootloader automatically falls back to the previous slot.
3. **Hardware Watchdog Timer (`/dev/watchdog`):** `micrond` pings the motherboard hardware watchdog every 15 seconds. If the system kernel freezes, the hardware automatically reboots the appliance.

> **Implementation status — the slot half of item 2 is now real.** `installer/install.sh` provisions `ESP` + swap + `ROOT_A` + `ROOT_B` + `DATA`, formats and labels every slot, mounts the persistent layer at `/var/lib/micron`, lists slot B in `fstab` by UUID as `noauto`, installs the boot-slot selector as `/etc/grub.d/09_micron_slots`, seeds the GRUB environment block, and installs `micron-healthcheck.service` as the only code permitted to mark a slot good. The selector boots a slot only once a previous boot proved it healthy, and falls back to the other slot otherwise. **What is still missing is everything that writes to slot B:** no updater image-lands in the inactive slot yet (Phase 3 of the specification), and `micrond` does not yet gate its watchdog ping on the health check. So the three-minute fallback above is *armed* but has no new image to fall back from — slot B stays empty until the platform updater lands. Item 1 (read-only roots) and item 3 (hardware watchdog) are unchanged. The full mechanism, the slot contract and the activation/rollback flow are specified in the **[Update & Upgrade Mechanism Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html)**. Also note that any application-only fix still requires a full platform image rebuild, because the web application is deployed to `/usr/local/micron` on the read-only root — that specification relocates it to the persistent layer so application updates no longer need a reboot.

---

## 21. System Services Lifecycle & Daemon Watchdog Manager

In high-availability enterprise firewall deployments, daemon stability is paramount. A single unmonitored crash of a routing daemon (`frr`), packet filter (`nftables`), or VPN gateway (`wireguard`) can isolate thousands of branch offices or road warriors.

MicronOS implements a **centralized Services Management & Watchdog Architecture**:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   MANAGEMENT PLANE (/services)                         │
│   Web UI Inventory ◄──► Start / Stop / Restart / Boot State / Logs     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ IPC: system.service
┌───────────────────────────────────▼────────────────────────────────────┐
│                    PRIVILEGED DAEMON (micrond)                         │
│                                                                        │
│   systemctl start/stop/restart/enable/disable                          │
│   journalctl -u {service} -n 30 --no-pager                             │
│   PID / Memory / RSS telemetry monitoring                              │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                  SERVICE WATCHDOG AUTO-RECOVERY                        │
│                                                                        │
│   Periodically probes active daemons every 30s.                        │
│   If monitored daemon terminates unexpectedly:                         │
│     1. Restarts daemon automatically via systemd                       │
│     2. Captures core dump / exit code to /var/log/watchdog.log         │
│     3. Generates high-priority Alert in Management Plane & Syslog      │
└────────────────────────────────────────────────────────────────────────┘
```

### Supported Core Security Daemons:
1. **`nftables`** (Firewall Subsystem): Stateful packet inspection, NAT compiler, connection tracking.
2. **`wireguard`** (VPN Subsystem): High-speed in-kernel ChaCha20-Poly1305 cryptokey mesh tunnels (`wg0`).
3. **`openvpn`** (VPN Subsystem): Multi-client SSL/TLS dynamic challenge 2FA remote access server (`tun0`).
4. **`frr`** (Routing Subsystem): FRRouting suite managing `bgpd`, `ospfd`, `ospf6d`, `ripd`, `isisd`, and `bfdd`.
5. **`kea-dhcp4`** (Network Subsystem): High-throughput dynamic host configuration daemon.
6. **`unbound`** (Network Subsystem): Recursive validating DNSSEC resolver with DNSBL bloom-filter sinkhole, database-driven local host overrides (`local-data`) and Domain Overrides (`forward-zone` per-zone forwarding).
7. **`gateway monitor`** (Network Subsystem): Multi-WAN gateway latency, jitter (`RTTsd`), and packet loss probe.
8. **`squid`** (Security Subsystem): Content filtering, transparent TPROXY, and SSL-Bump proxy.
9. **`keepalived`** (HA Subsystem): VRRPv2/v3 failover and virtual IP migration engine.
10. **`conntrackd`** (HA Subsystem): Real-time TCP/UDP connection state replication across firewall nodes.
11. **`suricata`** (Security Subsystem): Multi-threaded deep packet inspection (IDS/IPS) engine.
12. **`chrony`** (System Subsystem): Precision Network Time Protocol (NTP) synchronizer.

### Per-Page Daemon Controls

Every subsystem page carries the same service-control widget in its header (`App\Libraries\ServiceStatus::widget()` rendering `partials/service_controls`): a status LED, the daemon name and run-state, and **Start / Restart / Stop** buttons. It polls `/services/status` every 15 seconds (pausing when the tab is hidden and while an action is in flight), collapses to an icon toggle on narrow viewports, and opens the shared journal viewer (`partials/service_logs_modal`, backed by `GET /services/logs/{id}`) when the LED is clicked. Actions post to `POST /services/{id}/control`, which validates the action, updates the `system_services` row, writes an `audit_logs` entry, and dispatches `system.service` to `micrond`. This keeps the on-call operator one click from restarting `squid`, `suricata`, `frr` or any other daemon without leaving the page they are troubleshooting on.

---

### 21.1 DNS Overrides: Local Host Maps & Per-Domain Forwarding

Both override types are managed on the DNS Resolver page under **Host Overrides** (`/dns?tab=hosts`) and are compiled straight from the database by `UnboundDnsCompiler`, so `unbound.conf` is never hand-edited:

| Override | Table | UI card | Generated stanza |
| :--- | :--- | :--- | :--- |
| Local DNS Host Override | `dns_hosts` | *Local DNS Host Overrides (Split-Horizon / A Records)* | `local-data: "nas.corp.internal. IN A 192.168.1.102"` |
| Domain Override | `dns_domain_overrides` | *Domain Overrides (Per-Domain Forwarding)* | `forward-zone:` / `name: "corp.internal."` / `forward-addr: 10.0.0.53@5353` |

**Domain Overrides** forward every query for a zone to a dedicated upstream resolver (for example an internal AD or split-horizon DNS server). Each entry carries the domain/zone, a forwarder that must be a literal IPv4 or IPv6 address, an optional port (1–65535, default 53), a description, and an enable flag so a rule can be parked without deleting it. Domains are lowercased and stripped of a trailing dot on save; Unbound resolves the most specific forward-zone, so `branch.corp.internal` wins over `corp.internal`, which in turn wins over the root `.` DNS-over-TLS forward zone.

Because `local-data` makes Unbound create an implicit transparent zone, host overrides answer only the names they define while sibling names in the same zone still resolve upstream:

```unbound
# Compiled from dns_hosts + dns_domain_overrides
server:
    local-data: "gateway.micronos.internal. IN A 192.168.1.1"
    local-data: "nas.corp.internal. IN A 192.168.1.102"

forward-zone:
    name: "."
    forward-tls-upstream: yes
    # Cloudflare & Quad9 DNS over TLS (DoT)
    forward-addr: 1.1.1.1@853#cloudflare-dns.com

forward-zone:
    name: "corp.internal."
    # Internal AD resolver
    forward-addr: 10.0.0.53@5353
```

Create, update, toggle and delete operations validate the input and then fire `dns.config.reload` over the IPC bridge, so the resolver picks the change up without a service restart. Routes are guarded by the `dns.view` / `dns.edit` permission pair (`dns.override.manage` is reserved for host-override management).

---

### 21.2 Appliance DNS Resolution: Server Override & Resolution Behavior

§21.1 describes how the appliance **answers DNS for LAN clients**. The two controls in §21.2 describe something different: how the **firewall itself** resolves names for its own needs — update checks, package and plugin installs, NTP, certificate renewal. pfSense draws the same line, and both sections live on `/dns?tab=settings` as independent forms, so saving one can never blank the other's fields.

| Control | Column | Semantics |
| :--- | :--- | :--- |
| **DNS Server Override** | `dns_server_override` | When set, DNS servers assigned by a DHCP/PPP server on WAN or by a remote OpenVPN peer (Pull DNS) may override the manually configured list. When cleared, only the servers configured on the DNS page are used. Defaults to **set**, matching pfSense, so existing installs keep their behaviour. |
| **DNS Resolution Behavior** | `dns_resolution_behavior` | How the firewall itself queries: `local-fallback` (default), `local-only`, or `remote-only`. |

The behavior is a closed set — `local-fallback` (consult 127.0.0.1 first, then the configured remote servers), `local-only` (127.0.0.1 only: the more secure choice, since it guarantees the firewall's own queries inherit the resolver's DNSSEC, DoT and blocklist policy) and `remote-only` (the configured remote servers only: useful when the local resolver is deliberately strict for clients while the firewall still needs unrestricted DNS for updates). A value outside the set is rejected on save rather than coerced; an *absent* value (a client that predates the field) falls back to the documented default, as does an unknown value already stored in the database.

**Compilation.** `App\Libraries\SystemResolverCompiler` compiles the appliance stub resolver file from `dns_settings` and the dynamic record, daemon-free like every other compiler:

```resolv.conf
# Micron Firewall OS (MicronOS) — Appliance Stub Resolver
# DNS Resolution Behavior: Use local DNS (127.0.0.1), fall back to remote DNS Servers (Default)
# DNS Server Override: enabled — servers assigned by DHCP/PPP on WAN or a remote OpenVPN server may override this list

# dynamic-wan-override: enabled
# Dynamic WAN/OpenVPN-assigned servers merged ahead of the configured list: 10.0.0.138, 10.8.0.1
nameserver 127.0.0.1
nameserver 10.0.0.138
nameserver 10.8.0.1
nameserver 1.1.1.1
nameserver 1.0.0.1
nameserver 9.9.9.9
```

`local-only` emits 127.0.0.1 alone and ignores remote servers entirely; `remote-only` emits only the remote servers. The `# dynamic-wan-override:` directive is what the interface up/down hooks honor, so the override **never applies to the Unbound resolver acting for clients** — it only governs the firewall's own stub resolver. Remote addresses are taken from `upstream_servers` (the DNS page's managed list), validated as literals, deduplicated and capped at eight; `resolv.conf` names servers by address, so the DoT port and SNI are Unbound's business and are intentionally not honored here.

**The dynamic half.** The checkbox alone does nothing until something records what the dynamic links were actually handed. That record is `dynamic_dns_servers`, one row per (source, interface, address), written by `App\Libraries\DynamicDnsRegistry`. On a successful interface change — `Interfaces::apply()`, `Pppoe::apply()`, `VpnOpenvpn::restart()` — `App\Libraries\ApplianceResolver` asks the data plane (the read-only `interface.dynamic_dns` RPC, which scrapes the per-link files the DHCP/PPP/OpenVPN up-scripts write under `/run/micron/dynamic-dns/<source>/<link>.dns`) and reconciles that table, then re-applies the compiled file. While the override is checked the recorded servers are merged **ahead** of the manually configured ones (`local-fallback` and `remote-only`); when it is unchecked they are **dropped** — ignored by the compiler *and* cleared from the table. A failed report never discards an existing record: a transient RPC error leaves the known servers in place. The stored addresses are shown on the settings card, annotated as merged, ignored, or unused. Because the compiler caps the merged list at eight — the same ceiling `dns.resolver.apply` enforces — a WAN that hands out a long resolver list can never produce a file the daemon rejects. Two invariants hold throughout: this never touches the Unbound resolver that answers LAN clients, and the dynamic addresses are never handed to DHCP clients.

**Two safety rules, enforced at both ends.** The compiler never emits a file without a `nameserver` (a `remote-only` policy with nothing configured degrades to 127.0.0.1 with a loud comment), and `Dns::saveResolverSettings()` refuses to persist that combination at all: an empty stub resolver would break every name lookup on the appliance, including the update check that would be needed to fix it. `POST dns/resolver` therefore validates the closed set and the resolvability precondition before writing, then hands the compiled content to `dns.resolver.apply` over the IPC bridge.

**Data-plane validation.** `micrond` does not trust the control plane: `dns.resolver.apply` accepts only blank lines, comments and `nameserver <address>`; refuses anything else (`search`, `options`, `include`, an address carrying a payload), an empty server set, more than eight servers or an oversized payload; refuses a `local-*` policy that does not actually name 127.0.0.1; writes `/etc/resolv.conf` atomically via a temp file plus rename; and flushes the resolver cache. A cache-flush failure degrades to a warning — the file is already in effect and is read per lookup, so no service restart is required. A rejected payload leaves the previous file untouched.

---

## 22. Cron Job Scheduler & Automated Maintenance Engine

To guarantee unattended reliability, MicronOS provides a dedicated **Scheduled Task Engine** (`/cron`), delivering full parity with the BSD cron manager.

### Crontab Architecture & Compilation:
- All scheduled jobs are stored relationally in the database (`cron_jobs` table).
- When a task is added, edited, or toggled, the compiler deterministically synthesizes `/etc/cron.d/micronos`:
  ```
  # /etc/cron.d/micronos — Auto-generated by MicronOS. DO NOT EDIT DIRECTLY.
  SHELL=/bin/sh
  PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
  
  30 3 * * * root /usr/local/bin/dnsbl-update --reload-unbound
  0 0 * * * root /usr/sbin/logrotate /etc/logrotate.conf
  15 4 1 * * root /usr/local/bin/micron-cert-renew --check-expiry 30
  */15 * * * * root /usr/local/bin/dyndns-update --iface eth0
  ```

  Only *enabled* jobs are compiled, which is why the seeded `cron_backup_nightly` preset is absent above: it ships disabled. It previously appeared here pointing at `/usr/local/bin/micron-backup`, a helper that is not shipped — a job that could only fail, while its row reported success. It now names `php spark backup:create` and waits for an administrator; scheduled backups are still planned — see §17.6. Note that the remaining `/usr/local/bin/*` presets above name helper binaries this tree does not ship either, so they fail the same way; unlike the backup row, they still carry `000028`'s sample run output.

### Standard Pre-Configured Maintenance Presets:
1. **Nightly Configuration Snapshot**: Runs `php spark backup:create` — the same engine behind `microctl backup create` — which writes a plain JSON `.mbk` archive and registers it on the Backup page. Pass `--passphrase` (12 characters minimum) to write an authenticated AES-256-GCM `.mbk.enc` envelope instead. *(Seeded **disabled**: no timer runs it, nothing prunes the store, and no remote target receives a copy — §17.6. Until then, snapshots are created on demand from the Backup page or `microctl backup create`.)*
2. **DNSBL Threat Feeds Update**: Downloads the latest threat lists (Spamhaus, URLhaus, StevenBlack, PhishTank), compiles deduplicated bloom filters, and reloads Unbound without dropping queries.
3. **Log Rotation**: Midnight execution of `logrotate` to prevent storage exhaustion from high-volume firewall packet logs.
4. **Certificate Expiration Verification**: Validates expiration dates on all X.509 server and user certificates, sending alerts at 30, 14, and 7 days.
5. **Dynamic DNS Updates**: Verifies public IP against dynamic DNS providers (Cloudflare, DuckDNS, DynDNS) every 15 minutes.
6. **Manual "Run Now" Execution**: Web administrators can execute any scheduled job on demand, capturing live exit codes and stdout/stderr directly into an inspection modal.
7. **Address Resolution** (`cron_address_resolve`): Re-resolves FQDN / Internet-Service / GeoIP address objects into prefixes and prunes stale entries.
8. **Internet Service Sync** (`cron_service_sync`): Downloads and parses the enabled Internet Service prefix feeds.
9. **PPPoE Redial** (`cron_pppoe_redial`): Health-aware re-establishment of downed PPPoE WAN links with escalating backoff.

### 22.1 Run History & Output Capture

A scheduled job that fails silently is worse than no job at all. MicronOS records a bounded, machine-parsable **run history** for jobs that maintain their own state, using the `CronJobLog` helper:

- Each execution appends a timestamped entry containing a **one-line summary**, a **status** (`success` / `warning` / `failed`), and optional detail, rather than overwriting the previous result.
- The history is stored in the job's own `last_output` column, delimited by a leading `[` marker, and is **bounded** two ways: at most the most recent *N* entries (default 20) and a hard byte cap. A job can therefore run forever without the `cron_jobs` row growing without bound.
- `CronJobLog` never throws: a logging failure degrades to “no history” and never aborts the job itself.
- The UI renders the history as a compact table (time, status, summary, detail) and can count repair events across the retained window.

This mechanism is used by the PPPoE redial job, where each run records how many links were **repaired** versus **skipped**, and is available to any future stateful job.

---

## 23. Enterprise Network Address Translation (NAT) & Hairpin Engine

MicronOS provides a 4-tier NAT architecture fully comparable with enterprise firewalls:

1. **Port Forwarding (Inbound DNAT)**:
   - Maps inbound WAN ports to internal hosts with protocol selection (TCP, UDP, both).
   - Built-in service presets: HTTP (80), HTTPS (443), WireGuard (51820), OpenVPN (1194), Plex (32400), Minecraft (25565), SIP (5060).
   - Integrated **NAT Reflection (Hairpin NAT)** allowing LAN clients to access internal forwarded servers using the public WAN IP/FQDN without requiring split-DNS.

2. **1:1 NAT (VIP Mappings)**:
   - Bi-directional static address translation mapping an external public IP / Virtual IP (VIP) to an internal private host with destination filtering and NAT reflection.

3. **Outbound NAT (SNAT & Masquerading)**:
   - 4 compilation modes:
     - **Automatic**: Masquerades all internal RFC1918 subnets out all WAN interfaces.
     - **Hybrid (Recommended)**: Applies automatic rules, but custom user rules are compiled first.
     - **Manual (Advanced)**: Disables automatic rules, compiling only user-specified egress rules.
     - **Disabled**: Operates as a pure router with source IPs unmodified.
   - **Static Port Preservation**: Disables port randomization on outbound connections. Mandatory for SIP VoIP telephony (prevents one-way audio dropouts) and gaming consoles requiring Strict NAT avoidance.

4. **NPTv6 (IPv6-to-IPv6 Network Prefix Translation - RFC 6296)**:
   - Stateless, checksum-neutral IPv6 translation mapping internal Unique Local Addresses (ULA `fd00::/8`) to ISP-assigned Global Unicast Addresses (GUA `2001:db8::/32`).

---

## 24. SNMP Agent & Enterprise Network Monitoring (v1/v2c/v3 & Traps)

MicronOS includes full native integration with the **Net-SNMP Agent (`snmpd`)**, allowing Network Management Systems (NMS) such as Zabbix, PRTG, LibreNMS, and Nagios to poll appliance health and receive proactive event traps.

### Architecture & Supported Capabilities:
1. **Multi-Version Protocol Support**:
   - **SNMPv1 & SNMPv2c**: Configurable community strings (`read_community`, `write_community`) with strict CIDR subnet access-control lists (ACL) preventing unauthorized polling from untrusted networks.
   - **SNMPv3 User-Based Security Model (USM)**:
     - Authentication Protocols: `SHA-256`, `SHA-512`, `SHA-1`, `MD5`.
     - Privacy & Encryption Protocols: `AES-256`, `AES-192`, `AES-128`, `DES`.
     - Security Levels: `authPriv` (Auth & Encryption), `authNoPriv`, `noAuthNoPriv`.
2. **Standard MIBs & Metrics Exported**:
   - `HOST-RESOURCES-MIB`: Multi-core CPU load, system RAM, swap utilization, storage volumes.
   - `IF-MIB`: Live interface throughput (octets in/out), packet errors, carrier drops, interface operational status.
   - `IP-MIB` / `TCP-MIB` / `UDP-MIB`: Active TCP connections, socket buffers, routing tables.
   - Custom Enterprise OID tree (`.1.3.6.1.4.1.59999`): Active Netfilter conntrack state count, VPN tunnels, and DNSBL blocked query metrics.

### Sample MIB Walk & Daemon Log Output:
```text
# Local MIB Walk Test (snmpwalk -v2c -c public 127.0.0.1:161 system)
SNMPv2-MIB::sysDescr.0 = STRING: MicronOS Enterprise Firewall 24.1 (Linux 6.6.21-micron-amd64)
SNMPv2-MIB::sysObjectID.0 = OID: SNMPv2-SMI::enterprises.59999.1.1
SNMPv2-MIB::sysUpTimeInstance = Timeticks: (12489200) 14 days, 10:55:20.00
SNMPv2-MIB::sysContact.0 = STRING: noc@micronos.network
SNMPv2-MIB::sysName.0 = STRING: micronos-fw01.internal
SNMPv2-MIB::sysLocation.0 = STRING: Datacenter Rack 04 (Unit 12)
HOST-RESOURCES-MIB::hrProcessorLoad.1 = INTEGER: 12% (Core 0)
HOST-RESOURCES-MIB::hrProcessorLoad.2 = INTEGER: 8% (Core 1)
IF-MIB::ifInOctets.2 = Counter32: 894102914
IF-MIB::ifOutOctets.2 = Counter32: 612849102

# Live snmpd Daemon Execution Stream (journalctl -u snmpd -n 5)
2026-10-02 18:40:12 [INFO] snmpd[1842]: NET-SNMP version 5.9.3 started on UDP 0.0.0.0:161
2026-10-02 18:41:05 [AUTH-OK] snmpd[1842]: Connection from UDP: [192.168.1.50]:49182 community='public' authorized
2026-10-02 18:41:06 [QUERY] snmpd[1842]: GETNEXT request for OID: .1.3.6.1.2.1.1.1 (sysDescr) responded with 84 octets
2026-10-02 18:42:30 [TRAP-OUT] snmptrap[2104]: Dispatched trap to 192.168.1.50:162 [coldStart enterprise=.1.3.6.1.4.1.59999]
```

3. **Proactive SNMP Traps & Informs**:
   - Sends asynchronous trap notifications to remote trap collectors (`UDP 162`) upon:
     - Physical network interface link carrier state changes (`IF-MIB::linkUp` / `linkDown`).
     - VRRP High Availability failovers and virtual-address ownership changes.
     - Critical resource exhaustion (CPU load >90%, Memory >90%, State table >85%).
     - Administrative authentication failures (brute-force defense).

---

## 25. SIEM Integration & Real-Time Event Log Shipping (CEF, LEEF, JSON ECS)

To meet compliance requirements (PCI-DSS, SOC 2, ISO 27001) and empower central Security Operations Centers (SOC), MicronOS includes a **dedicated SIEM Log Shipping Engine** (`/siem`).

### Telemetry Pipeline & Protocols:
- **Transport Mechanisms**:
  - `UDP`: High-speed, low-overhead standard log shipping (`UDP 514`).
  - `TCP`: Reliable delivery with connection keepalives.
  - `TLS (mTLS - RFC 5425)`: Mutual TLS encrypted log shipping with client certificate authentication and remote CA validation (`TCP 6514`).
- **Standardized Enterprise Schema Encodings**:
  - **CEF (Common Event Format)**: Native format for HP ArcSight, Microsoft Sentinel, and Rapid7 InsightIDR.
  - **LEEF (Log Event Extended Format)**: Native format for IBM QRadar SIEM DSM auto-parsing.
  - **JSON / ECS (Elastic Common Schema)**: Structured key-value schema for Splunk, Elastic SIEM / ELK, Wazuh, and Graylog.
  - **RFC 5424 / RFC 3164**: Structured and unstructured IETF/BSD syslog.

### Real-Time Standardized SIEM Payloads:
```text
# 1. ArcSight / Microsoft Sentinel Common Event Format (CEF)
CEF:0|MicronOS|SecurityAppliance|24.1|FIREWALL:PASS|Default Allow LAN outbound|3|src=192.168.1.100 dst=1.1.1.1 spt=54820 dpt=53 proto=UDP act=pass app=DNS msg=MicronOS Firewall Rule

# 2. IBM QRadar Log Event Extended Format (LEEF)
LEEF:2.0|MicronOS|SecurityAppliance|24.1|FIREWALL_PASS|src=192.168.1.100	dst=1.1.1.1	spt=54820	dpt=53	proto=UDP	act=pass	devTime=2026-10-02T19:30:00Z	msg=MicronOS Allow LAN

# 3. Splunk / Elastic SIEM / Wazuh JSON ECS Payload
{
  "@timestamp": "2026-10-02T19:30:00.124Z",
  "event": { "kind": "event", "category": ["network", "firewall"], "action": "firewall-rule-pass" },
  "observer": { "vendor": "MicronOS", "product": "SecurityAppliance", "version": "24.1", "hostname": "micronos-fw01" },
  "source": { "ip": "192.168.1.100", "port": 54820 },
  "destination": { "ip": "1.1.1.1", "port": 53 },
  "network": { "transport": "udp", "protocol": "dns" },
  "rule": { "name": "Default allow LAN to Internet", "id": "101" }
}

# 4. Live Rsyslog Shipping Stream (/var/log/siem_forwarder.log)
2026-10-02 19:30:01 [DELIVERED] TLS://siem.internal.corp:6514 [mTLS verified] 284 bytes sent (Firewall PASS 192.168.1.142 -> 142.250.190.46)
2026-10-02 19:30:05 [DELIVERED] TLS://siem.internal.corp:6514 [mTLS verified] 392 bytes sent (Suricata ALERT ET SCAN Potential SSH Brute-Force)
2026-10-02 19:30:11 [DELIVERED] TLS://siem.internal.corp:6514 [mTLS verified] 210 bytes sent (WireGuard AUTH Handshake Established peer=wg-remote-admin)
```

- **Granular Subsystem Forwarding Toggles**:
  - Netfilter Firewall packet inspection (Pass, Drop, Reject).
  - Suricata IDS/IPS alert logs and matched CVE threat signatures.
  - VPN tunnel sessions (WireGuard handshakes, OpenVPN 2FA logins/disconnects).
  - Squid web filter access logs, blocked categories, and SSL-Bump hits.
  - Administrative audit logs (privilege elevation, config commits, SSH logins).
  - Kea DHCP lease grants and IP renewals.
- **Resilience & DDoS Protection**:
  - **Local Disk Spooling**: Buffers up to `10 GB` on local SSD during network or collector downtime; drains automatically upon reconnection.
  - **Log Rate-Limiting**: Caps outbound log velocity (e.g. `5,000 events/sec`) to prevent management link saturation during volumetric DDoS attacks.

---

## 26. Detailed Phased Implementation Roadmap

### Phase 1: Core Appliance & Security MVP
- [x] Comprehensive Architecture & Database Specification (SQLite + MySQL).
- [x] CodeIgniter 4 base dashboard with dark-mode responsive UI & real-time traffic graph.
- [x] Interface management (Ethernet, Static, DHCP Client, VLAN 802.1Q, Bridge, Loopback).
- [x] Interface traffic & link parameters (MSS clamping, MAC override, Speed/Duplex, Description).
- [x] nftables core engine with stateful filtering and 4-tier NAT (DNAT, 1:1, Outbound SNAT, NPTv6).
- [x] Kea DHCP Server with advanced DHCP options & static leases.
- [x] Unbound DNS Resolver & DNSBL Threat Shield (500k+ blocklists, DoT, query telemetry) with Local Host Overrides and Domain Overrides (per-domain forwarding) at `/dns?tab=hosts`.
- [x] Appliance DNS Resolution Behavior at `/dns?tab=settings`: DNS Server Override for DHCP/PPP/OpenVPN-assigned servers — with the interface path recording what the dynamic links assign and merging them into the appliance resolver while the override is enabled (dropping them when it is not) — and a `local-fallback` / `local-only` / `remote-only` policy for the firewall's own queries, compiled into `/etc/resolv.conf` and validated by the daemon (§21.2).
- [x] System Services Manager (`/services`) with daemon lifecycle controls & watchdog.
- [x] Cron Scheduled Task Manager (`/cron`) with crontab synchronization and bounded run history.
- [x] Net-SNMP Agent (`/snmp`) with v1/v2c communities, SNMPv3 USM users, and Traps.
- [x] SIEM Log Forwarding (`/siem`) with CEF, LEEF, JSON ECS, and mTLS transport.
- [x] Transactional candidate commit & automatic rollback engine.
- [x] Role-Based Access Control (4-table normalised RBAC with a 96-permission catalogue).
- [x] Policy-Based Routing (`/routing` PBR) with match objects and ordered policies.
- [x] Multi-WAN gateways, gateway groups, and sticky (flow-pinned) connections.
- [x] Internet Service Database with offline FQDN / GeoIP / service-prefix resolution.
- [x] PPPoE client (WAN uplink) with health-aware redial and escalating backoff.
- [x] PPPoE subscriber access server (BRAS) with RADIUS auth/accounting and EAP-TLS.

### Phase 2: Enterprise Identity & Road Warrior 2FA VPNs
- [ ] LDAP & Active Directory integration for Web Admin and VPN users.
- [ ] TOTP 2FA engine (RFC 6238) with QR code enrollment and scratch codes.
- [ ] OpenVPN Road Warrior server with PAM dynamic-challenge 2FA.
- [ ] User self-service portal for `.ovpn` configuration bundle downloads.
- [ ] WireGuard captive portal 2FA with ephemeral `nftables` session leases.

### Phase 3: Backup & Disaster Recovery Engine
- [x] AES-256-GCM encrypted envelope builder (`.mbk.enc`), PBKDF2-SHA256 key derivation. *(Argon2id: still planned — §17.7.)*
- [x] Plain JSON export (`.mbk`) with a SHA-256 payload digest in the archive register.
- [x] Selective component restoration and a NIC remapping map for cross-machine restores.
- [ ] Automated scheduled cloud exports (SFTP, AWS S3/MinIO, Micron Central), and local retention rotation.
- [ ] Watchdog safety rollback during configuration restoration, and reporting a daemon commit the restore failed to obtain.

### Phase 4: Next-Gen Web Filtering & Proxy Services
- [ ] Squid 6+ deployment with Linux TPROXY transparent integration.
- [ ] TLS SNI "Peek and Splice" filtering (no client certificates needed).
- [x] SSL-Bump deep packet inspection option with automated CA certificate generation.
- [x] C-ICAP and ClamAV integration for live HTTP payload malware scanning.
- [ ] Automated domain blocklist downloader (UT1, Shallalist, URLhaus).
- [ ] SafeSearch & YouTube Restricted Mode header injection.

### Phase 5: Dynamic Enterprise Routing & HA
- [ ] FRRouting management interface (BGP & OSPFv2/v3), BFD, and VRF isolation.
- [ ] Keepalived VRRP failover + `conntrackd` state replication.
- [ ] Configuration auto-synchronization between clustered nodes.

### Phase 6: Threat Prevention & Intelligence
- [ ] Suricata IDS/IPS integration with automatic emerging threats rule updates.
- [ ] GeoIP database integration with rapid nftables set lookups.
- [ ] Unbound DNS sinkhole (ad and malware domain blocking).
- [ ] Real-time live log viewer with WebSocket streaming.

### Phase 7: Central Orchestration (Micron Central Integration)
- [ ] mTLS REST API for centralized multi-branch monitoring.
- [ ] Prometheus metrics exporter (`/metrics`) for LibreNMS / Grafana.
- [ ] Zero-touch provisioning (ZTP) agent for automated cloud onboarding.
- [ ] Bootable ISO installer script using Debian Preseed / Calamares.

### Phase 8: WAN Access & Subscriber Management
- [x] PPPoE client peer/secrets compilation with MTU clamping and DNS handling.
- [x] PPPoE access server (BRAS) with address pools, per-subscriber accounts, and rate limits.
- [x] RADIUS authentication with failover, encrypted shared secrets, and a live test tool.
- [x] RADIUS accounting (Start/Stop/Interim) with per-server toggles.
- [x] EAP-TLS certificate authentication co-existing with PAP/CHAP.
- [x] Per-account RADIUS reply-attribute visibility in live sessions.
- [x] Per-subscriber session history and health-aware redial with escalating backoff.

---

*Document Version:* 2.2.0 (WAN Access, PPPoE/RADIUS/EAP-TLS & Internet Service Database)  
*Project:* Micron Firewall OS (MicronOS)  
*License:* Proprietary / Open Enterprise Architecture  

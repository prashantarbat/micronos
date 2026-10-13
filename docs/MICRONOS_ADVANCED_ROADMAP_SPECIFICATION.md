# Micron Firewall OS (Micron OS) — Advanced Capabilities & Next-Generation Roadmap Specification

## 1. Executive Summary & Prioritized Roadmap

This engineering specification defines the architecture, data structures, daemon protocols, and implementation workflows for the top seven next-generation capabilities prioritized for **Micron OS**, establishing clear parity and competitive advantages over enterprise appliances such as FortiGate (FortiOS), pfSense Plus, and OPNsense Business.

### Prioritized Development Pipeline

| Priority | Subsystem & Feature | Primary Technologies | Target Layer | Status |
| :---: | :--- | :--- | :--- | :---: |
| **1** | **Automated Configuration Diff & Git-Backed Revision History** | `git`, `SQLite3`, Diff renderer | Control Plane & Web GUI | **Completed (v0.1.23)** |
| **2** | **Application Identification & Layer 7 DPI (AppID via nDPI)** | `nDPI`, `nftables` marks, `micrond` | Data Plane & Netfilter | **Completed (v0.1.24)** |
| **3** | **Full SSL/TLS Deep Packet Inspection (MITM CA Engine)** | OpenSSL CA, `Squid` SSL-Bump, ICAP | Data Plane & Proxies | **Completed (v0.1.25)** |
| **4** | **Application-Aware Dynamic SD-WAN (SLA Path Steering)** | Probing daemon, Jitter/Loss SLA, PBR | Routing & Linux Kernel | **Completed (v0.1.26)** |
| **5** | **In-Browser Clientless Web Gateway (HTML5 RDP/SSH)** | WebSockets, Guacamole daemon, TOTP | Management Plane | **Completed (v0.1.27)** |
| **P0-Resilience** | **Inherent System Resilience & Self-Healing Architecture** | SQLite WAL, 4-Step Atomic Writes, Dual-Root Golden Image, Esc/Shift LKGC | Storage, Kernel & Bootloader | **Completed (v0.1.28)** |
| **6** | **Centralized Single Sign-On (SSO) via OIDC & SAML 2.0** | OAuth2 / OIDC, SAML 2.0, Entra ID | IAM & Control Plane | **Completed (v0.1.29)** |
| **7** | **Automated Threat Intelligence Feed Ingest** | AbuseIPDB, Spamhaus, `nftables` sets | Netfilter & Feeds | **Completed (v0.1.30)** |
| **8** | **Captive Portal & Hotspot Voucher Engine** | `nftables` redirect, Voucher rolls, Walled Garden, Splash designer | WLAN & Edge Access | **Active Sprint (v0.1.31)** |
| *Future* | *Centralized Cloud Fleet Controller (Micron Central)* | WebSockets, Mutual TLS, Fleet API | Management Plane | Future |
| *Future* | *BGP EVPN & VXLAN Datacenter Overlay* | FRRouting, Linux VXLAN | Routing & Overlays | Future |
| *Future* | *Cross-VLAN mDNS Reflector & IGMP Proxy* | `avahi`, `igmpproxy` | Multicast & IoT | Future |
| *Future* | *4G/5G Cellular LTE Modem Manager* | `ModemManager`, QMI/MBIM, `wwan0` | Hardware Layer | Future |

---

## 2. Priority 1: Automated Configuration Diff & Git-Backed Revision History (Status: Completed)

### 2.1 Architectural Overview
Micron OS features a complete, git-backed revision history subsystem.
1. Local Git repository located at `WRITEPATH . 'config_repo'` (`/var/lib/micron/config.git` on Linux appliances).
2. Automatic atomic commits on every confirmed configuration change across all subsystems (firewall rules, interfaces, routing, DNS, DHCP, VPN, bonding, wireless, DPI).
3. Serialized normalized JSON configuration snapshot (`config.json`) representing the entire appliance database state.
4. Web GUI **Revision History & Diff Viewer** (`/system/revisions`) featuring:
   - Chronological audit timeline with committer username, timestamp, and message.
   - Side-by-side and inline colorized unified diff highlighting additions and deletions.
   - One-click rollback button that stages the selected historical commit and atomically restores database configuration tables.
   - Manual checkpoint modal for pre-maintenance configuration snapshots.

### 2.2 Semantic Versioning & Incremental Edits Integration
Every confirmed checkpoint and code modification increments the patch version of Micron OS:
- **Format:** `MAJOR.MINOR.PATCH` (e.g. `2.4.56`, `0.1.23`, `2.2.11`).
- **Minor (2nd Digit):**
  - **Odd number (`1, 3, 5, ...`)** $\rightarrow$ **Development Version** (e.g. `0.1.23`, `1.1.24`, `2.3.56`).
  - **Even number (`0, 2, 4, ...`)** $\rightarrow$ **Stable Version** (e.g. `0.2.0`, `2.2.11`, `2.4.12`).
- **Patch (3rd Digit):** Incremental edits performed on the project (`.56`, `.12`, `.11`, `.24`, `.23`).
- **Starting Baseline:** **`0.1.23`** (Development version).
- **Official Naming Standard:** Strictly **"Micron OS"** (with space), not "MicronOS".
- **Runtime Provider:** `App\Libraries\AppVersion` automatically increments the patch upon commit and binds the stability badge to the topbar, system information, login screen, and SIEM telemetry.

---

## 3. Priority 2: Application Identification & Layer 7 DPI (`nDPI`) (Status: Completed)

### 3.1 Packet Classification Architecture
Modern traffic evasion techniques (dynamic ports, port 443 masquerading, WebSockets) render Layer 4 port-based filtering insufficient.
1. The `nDPI` deep packet inspection engine is embedded via `micrond` and kernel netfilter connection tracking (`conntrack`).
2. For each new flow, the initial 8–10 packets are evaluated against nDPI protocol heuristics.
3. Once classified, a numeric protocol ID and netfilter hex mark is assigned to the conntrack session (e.g., `0x1001` for *YouTube*, `0x1061` for *Stratum Cryptomining*, `0x1031` for *BitTorrent*).
4. `nftables` firewall rules match directly on `ct mark` within the `dpi_forward` chain with zero overhead on the remaining packets of the flow.

### 3.2 Implemented Components
- **Database Schema:** `dpi_categories`, `dpi_applications`, `dpi_policies`, `dpi_custom_signatures`, `dpi_settings` (migration `2026-10-10-000106_CreateAppIdAndDpiTables.php`).
- **Compiler:** `App\Libraries\DpiCompiler` generating `/etc/ndpi/ndpi_rules.conf` and `nftables` Layer 7 filtering snippets.
- **Daemon IPC Bridge:** `micrond` JSON-RPC methods `dpi.status`, `dpi.apply`, `dpi.live_flows`, and `dpi.stats`.
- **Web GUI:** Controller `App\Controllers\AppId` and view `app/Views/appid/index.php` offering:
  - Real-time live flow telemetry stream with auto-refresh via `MicronScheduler`.
  - Ordered Application Control rulebase (Block, Allow, Shape/Rate-limit).
  - Searchable application catalogue across 9 categories and 21+ protocols.
  - User-defined regex and host signatures.
  - Global engine tuning parameters (depth, conntrack table max, operating mode).

---

## 4. Priority 3: Full SSL/TLS Deep Packet Inspection (MITM CA Engine) (Status: Active)

### 4.1 Root CA Generation & Automated Endpoint Trust
- Micron OS provisions a dedicated internal Certificate Authority:
  - Algorithm: RSA 4096-bit or ECDSA P-384.
  - Automated deployment endpoints: `/webfilter/download-ca` (DER/PEM), Windows GPO export helper, and SCEP enrollment.
- Stores CA key material in PKI keystore with encrypted private keys.

### 4.2 Transparent Decryption & ICAP Antivirus Pipeline
- Outbound port 443 connections to non-exempt domains are routed into the local SSL-Bump proxy engine (`Squid` 6+ or dedicated MITM worker).
- Synthetic dynamic certificates matching destination Server Alternative Names (SANs) are generated on the fly using OpenSSL memory buffers.
- Decrypted HTTP/1.1 and HTTP/2 payloads are streamed via ICAP to ClamAV (`c-icap`) and web category classifiers before re-encryption and transmission to the client.
- Built-in strict bypass lists for privacy-sensitive and certificate-pinned categories (Banking & Financial Institutions, Healthcare, Government Portals).

### 4.3 Database Schema & Configurations
- **Tables:**
  - `ssl_inspection_profiles`: Profile definitions (deep inspection vs certificate inspection, cipher suites, minimum TLS version).
  - `ssl_bypass_domains`: Domain wildcard patterns and FQDNs exempted from MITM decryption.
  - `ssl_ca_certificates`: Appliance root and intermediate CAs used for MITM signing.

---

## 5. Priority 4: Application-Aware Dynamic SD-WAN (SLA Path Steering) (Status: Completed - v0.1.26)

### 5.1 Real-Time SLA Metric Probing
An autonomous background daemon (`system/micron-sdwan-probe` and `micron-sdwan-probe.service`) continuously monitors each active WAN uplink:
- **Packet Loss (%)**: Measured over sliding ICMP probe bursts per interface.
- **Round-Trip Time (RTT / Latency)**: Sub-millisecond tracking via probes to target anchors (e.g., `8.8.8.8`, `1.1.1.1`, enterprise gateways).
- **Jitter (ms)**: RFC 3550 statistical variance between consecutive packet arrival intervals.
- **Atomic Runtime Persistence**: Probes write `/run/micron/sdwan_sla.json` and sync metrics to the SQLite configuration database.

### 5.2 Dynamic Policy-Based Routing (PBR) Engine
SD-WAN Profiles define performance service-level agreements:
```json
{
  "profile": "VoIP_Collaboration",
  "sla_thresholds": { "max_latency_ms": 100, "max_jitter_ms": 15, "max_packet_loss_pct": 1.0 },
  "preferred_uplink": "wan1",
  "secondary_uplink": "wan2_backup",
  "assigned_traffic": ["Zoom", "Teams", "SIP", "RTP"]
}
```
If `wan1` experiences sudden jitter spikes (>15ms) or packet loss, `SdwanEngine::resolveSteeringDecision()` detects the SLA breach and `SdwanCompiler` emits updated `nftables` policy routing marks and Linux routing table rules (`ip rule add fwmark ... table <id>`), automatically steering traffic to `wan2_backup` without dropping sessions.

### 5.3 Implemented Components & Verification
- **Probe Daemon:** `system/micron-sdwan-probe` (with `--oneshot`, `--interval`, `--samples` CLI options) and `system/micron-sdwan-probe.service`.
- **Daemon IPC Methods:** `sdwan.probe_status`, `sdwan.apply`, `sdwan.sla_report` in `system/micrond/micrond.py`.
- **Compiler & Engine:** `App\Libraries\SdwanCompiler` and `App\Libraries\SdwanEngine`.
- **Web Controller & Views:** `App\Controllers\Sdwan` (`/routing/sdwan`, `/routing/sdwan/sla-report`, `/routing/sdwan/metrics`) and `app/Views/sdwan/index.php`.
- **Packaging:** Integrated into `packaging/live-build/stage-overlay.sh`.
- **Test Verification:** `SdwanTest` (6/6 passing) and `SdwanCompilerTest` (4/4 passing) with 100% success.

---

## 6. Priority 5: In-Browser Clientless Web Application & Desktop Gateway (Status: Completed - v0.1.27)

### 6.1 Architecture (Zero-Install HTML5 RDP/SSH Gateway)
- Eliminates the need for remote employees to install IPsec or WireGuard client software on personal endpoints.
- Remote operators log into the Micron OS Web Portal with username, password, and optional TOTP 2FA.
- The portal exposes an authorized catalogue of internal desktops (**RDP**), servers (**SSH**), virtual consoles (**VNC**), and internal web applications (**HTTP/HTTPS**).
- Connections are mediated through a secure WebSocket tunnel translating graphics primitives to standard HTML5 Canvas at 60 FPS, with clipboard synchronization, audio streaming, and session recording for security audits.

### 6.2 Implemented Components & Verification
- **Control Daemon IPC (`system/micrond/micrond.py`):**
  - `ztna.apply`: Writes `/etc/nginx/sites-available/micron-ztna.conf`, enables symlink, tests syntax via `nginx -t`, and performs atomic reload while ensuring `guacd` daemon is active.
  - `ztna.status`: Live runtime probe of `guacd` and `nginx` service states.
  - `ztna.session_kill`: Administrator remote session termination endpoint.
- **Reverse Proxy Compiler (`App\Libraries\ZtnaCompiler`):**
  - Emits Nginx server block with subrequest authentication (`auth_request /api/ztna/verify-auth`).
  - Injects enterprise identity headers (`X-Remote-User`, `X-Remote-Email`, `X-Remote-Groups`) into upstream web applications.
  - Generates WebSocket tunnel routing to local `guacd` proxy port 4822.
- **HMAC Token Engine (`App\Libraries\GuacamoleTokenGenerator`):**
  - Time-limited, tamper-proof URL-safe Base64 tokens carrying target host, port, protocol, and username.
- **Web Interface & Endpoints (`App\Controllers\ZtnaGateway`):**
  - Admin management console (`/vpn/clientless-gateway`), end-user application launcher (`/vpn/clientless-gateway/portal`), launch dispatcher, and HTML5 canvas viewer (`/vpn/clientless-gateway/connect/{token}`).
- **Appliance Packaging:**
  - Debian package `nginx` included in `packaging/live-build/config/package-lists/micronos.list.chroot` (guacd protocol proxy is containerized/built separately as Debian 13 Trixie does not provide upstream guacd debs).
- **Test Verification:**
  - `ZtnaGatewayTest`: 7/7 passing assertions.
  - Packaging tests: 23/23 passing assertions.
  - Strict decoding guard checks: 100% PASS.

---

## 7. Priority P0-Resilience: Power-Loss Resilience & Self-Healing Architecture (Status: Active Sprint)

### 7.1 The Tri-Partite Failure Boundary
To ensure that Micron OS never bricks, corrupts, or requires manual `fsck` intervention after sudden power cuts, the system implements three non-overlapping protection contracts:
1. **Level 1: Filesystem Immutability (Storage Contract):** Read-only root partition (`ro,noatime,errors=remount-ro`) with volatile runtime state on `tmpfs` (`/run`, `/tmp`). Persistent state isolated to `/var/lib/micron` with hardware write barriers (`barrier=1,commit=5`).
2. **Level 2: Data & Database Durability (Application Contract):** SQLite Write-Ahead Logging (`PRAGMA journal_mode = WAL; PRAGMA synchronous = FULL;`) eliminating torn pages on crash. All file writes in `micrond` and PHP follow the 4-step POSIX atomic write protocol (`write tmp -> fsync fd -> replace -> fsync parent dir`).
3. **Level 3: Boot Arbitration & Automated Self-Healing (System Lifecycle Contract):** Dual A/B boot slots with Slot B pre-populated at installation as a verified golden factory fallback, pre-boot keyboard intercept (Esc/Shift LKGC menu in GRUB), automated 3-strike healthcheck rollback, and Linux hardware watchdog (`/dev/watchdog`) integration.

### 7.2 Implemented Milestones & Components
- **SQLite 3 Hardened WAL Configuration:** `app/Config/Database.php` (`journal_mode=WAL`, `synchronous=FULL`, `busyTimeout=5000`, `wal_autocheckpoint=1000`).
- **4-Step Atomic Write Protocol:** `safe_atomic_write()` helper in `system/micrond/micrond.py` preventing 0-byte file truncation on power drops.
- **Installer Dual-Root Golden Image:** `installer/install.sh` populating both Slot A (`sda3`) and Slot B (`sda4`) at installation time, eliminating the empty-slot fallback panic.
- **Pre-Boot LKGC Recovery Menu:** `installer/grub.d/09_micron_slots` checking `keystatus --escape || keystatus --shift` to provide operator recovery before runtime initialization.
- **Hardware Watchdog:** `RuntimeWatchdogSec=30s` in `/etc/systemd/system.conf`.

---

## 8. Priority 6: Centralized Single Sign-On (SSO) with OIDC & SAML 2.0 (Status: Planned)

### 8.1 Identity Provider (IdP) Integrations
- Supports **OpenID Connect (OIDC)** and **SAML 2.0** protocols for enterprise directory synchronization:
  - Microsoft Entra ID (Azure Active Directory)
  - Okta Identity Cloud
  - Google Workspace
  - Keycloak / Authentik on-premises
- **Role Mapping Engine:** Maps IdP SAML attributes or OIDC group claims to Micron OS granular permissions (e.g. `Entra:SecOps-Network` → `interfaces.*`, `firewall.*`; `Entra:Helpdesk` → `diagnostics.*`, `dashboard.view`).
- Automated Just-In-Time (JIT) administrative user provisioning and session token revocation.

---

## 9. Priority 7: Automated Threat Intelligence Feed Ingest (Status: Planned)

### 9.1 Autonomous Feed Connectors
- Scheduled hourly connectors targeting leading cyber intelligence sources:
  - **AbuseIPDB**: High-confidence aggressive scanners and brute-forcers.
  - **Spamhaus DROP / EDROP**: Don't Route Or Peer hijacked IP subnets.
  - **AlienVault OTX & Emerging Threats**: Botnet command-and-control (C2) IPs.
- Processing pipeline:
  1. HTTPS retrieval with ETag conditional download.
  2. Strict RFC 1918 / RFC 6598 private address filtering (guards against self-denial).
  3. CIDR prefix consolidation and deduplication.
  4. Direct bulk atomic swap into kernel `nftables set threat_intel_blocklist { type ipv4_addr; flags interval; }`.
  5. Inbound and outbound packets matching this set are dropped in the netfilter `prerouting` chain at wire speed with zero userspace CPU penalty.

---

## 10. Priority 8: Captive Portal & Hotspot Voucher Engine (Status: Active Sprint)

### 10.1 Architectural Overview & Core Capabilities
- **Netfilter Wire-Speed Redirection:** High-performance `nftables` prerouting hooks redirecting unauthenticated guest HTTP/HTTPS sessions directly to the local captive portal (ports 8088 / 8443).
- **Multiple Onboarding Models:** Click-Through AUP, Cryptographic Voucher Rolls, Local Credentials, and Enterprise RADIUS.
- **Walled Garden Acceleration:** Netfilter interval set bypassing redirection for Apple CNA, Google ConnectivityCheck, Microsoft NCSI, and payment gateways.
- **Cryptographic Voucher Rolls:** Batch voucher generation with duration, cumulative data volume caps, rate shaping, and device concurrency limits.
- **Dynamic Splash Customizer:** Real-time branding editor (logo, venue title, hospitality greeting, color palette, custom CSS).
- **Session Watchdog:** Real-time quota and idle monitoring via `micrond` with instant conntrack session flushing.

---

## 11. Implementation Roadmap & Execution Log

| Date | Phase | Milestone | Outcome |
| :---: | :---: | :--- | :--- |
| **2026-10-10** | **Priority 1** | Automated Configuration Diff & Git-Backed Revision History | Complete: Git sync, unified diff visualizer, instant rollback, automated version bump. |
| **2026-10-10** | **Priority 2** | Application Identification & Layer 7 DPI (AppID via nDPI) | Complete: nDPI compiler, nftables ct mark integration, live flow telemetry, catalogue and policy rulebase. |
| **2026-10-11** | **Priority 3** | Full SSL/TLS Deep Packet Inspection (MITM CA Engine) | Complete: `InterceptionCa` RSA-4096 root CA auto-provisioned in PKI keystore; `InterceptionCa::forgeCertificate()` generates dynamic per-host synthetic certs; SSL bypass list with category & domain exemptions; `ssl.apply` / `ssl.ca.status` / `ssl.sessions.active` IPC channels; CA download in PEM / DER / `.mobileconfig`; active session telemetry stream. |
| **2026-10-13** | **Priority 4** | Application-Aware Dynamic SD-WAN SLA Probing & Path Steering | **Completed (v0.1.26):** `micron-sdwan-probe` daemon, SLA threshold engine, PBR mark injection, multi-uplink failover and live telemetry. |
| **2026-10-13** | **Priority 5** | In-Browser Clientless Web Gateway (HTML5 RDP/SSH) | **Completed (v0.1.27):** Zero-install HTML5 remote access portal, Guacamole/Nginx tunnel, HMAC token generator, reverse proxy compiler, and micrond ZTNA IPC methods. |
| **2026-10-13** | **P0-Resilience** | Inherent System Resilience & Self-Healing Architecture | **Completed (v0.1.28):** SQLite WAL mode (synchronous=FULL), 4-step POSIX atomic writes, Dual-Root Golden Image cloning, Esc/Shift LKGC GRUB recovery menu, read-only root mount, DatabaseResilienceService auto-healing. |
| **2026-10-13** | **Priority 6** | Centralized Single Sign-On (SSO) with OIDC & SAML 2.0 | **Completed (v0.1.29):** SsoClient with PKCE, SAML 2.0 Web Browser SSO, JIT user provisioning, and role mapping engine. |
| **2026-10-13** | **Priority 7** | Automated Threat Intelligence Feed Ingest | **Completed (v0.1.30):** AbuseIPDB, Spamhaus, OTX feeds into kernel nftables sets with wire-speed packet filtering. |
| **2026-10-13** | **Priority 8** | Captive Portal & Hotspot Voucher Engine | **Active Sprint (v0.1.31):** Netfilter wire-speed redirect, cryptographic voucher rolls, walled garden, dynamic splash designer, and thermal printing. |


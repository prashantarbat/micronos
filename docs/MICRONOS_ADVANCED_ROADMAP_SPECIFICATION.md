# Micron Firewall OS (Micron OS) — Advanced Capabilities & Next-Generation Roadmap Specification

## 1. Executive Summary & Prioritized Roadmap

This engineering specification defines the architecture, data structures, daemon protocols, and implementation workflows for the top seven next-generation capabilities prioritized for **Micron OS**, establishing clear parity and competitive advantages over enterprise appliances such as FortiGate (FortiOS), pfSense Plus, and OPNsense Business.

### Prioritized Development Pipeline

| Priority | Subsystem & Feature | Primary Technologies | Target Layer | Status |
| :---: | :--- | :--- | :--- | :---: |
| **1** | **Automated Configuration Diff & Git-Backed Revision History** | `git`, `SQLite3`, Diff renderer | Control Plane & Web GUI | **Completed (v0.1.23)** |
| **2** | **Application Identification & Layer 7 DPI (AppID via nDPI)** | `nDPI`, `nftables` marks, `micrond` | Data Plane & Netfilter | **Completed (v0.1.24)** |
| **3** | **Full SSL/TLS Deep Packet Inspection (MITM CA Engine)** | OpenSSL CA, `Squid` SSL-Bump, ICAP | Data Plane & Proxies | **In Progress (Active)** |
| **4** | **Application-Aware Dynamic SD-WAN (SLA Path Steering)** | Probing daemon, Jitter/Loss SLA, PBR | Routing & Linux Kernel | Planned |
| **5** | **In-Browser Clientless Web Gateway (HTML5 RDP/SSH)** | WebSockets, Guacamole daemon, TOTP | Management Plane | Planned |
| **6** | **Centralized Single Sign-On (SSO) via OIDC & SAML 2.0** | OAuth2 / OIDC, SAML 2.0, Entra ID | IAM & Control Plane | Planned |
| **7** | **Automated Threat Intelligence Feed Ingest** | AbuseIPDB, Spamhaus, `nftables` sets | Netfilter & Feeds | Planned |
| *Future* | *Centralized Cloud Fleet Controller (Micron Central)* | WebSockets, Mutual TLS, Fleet API | Management Plane | Future |
| *Future* | *BGP EVPN & VXLAN Datacenter Overlay* | FRRouting, Linux VXLAN | Routing & Overlays | Future |
| *Future* | *Captive Portal & Hotspot Voucher Engine* | `hostapd`, `nftables`, RADIUS | WLAN & Edge Access | Future |
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

## 5. Priority 4: Application-Aware Dynamic SD-WAN (SLA Path Steering) (Status: Planned)

### 5.1 Real-Time SLA Metric Probing
An autonomous background daemon (`micron-sdwan-probe`) continuously monitors each active WAN uplink:
- **Packet Loss (%)**: Measured over sliding 100-packet ICMP / UDP bursts.
- **Round-Trip Time (RTT / Latency)**: Sub-millisecond tracking via probes to target anchors (e.g., `8.8.8.8`, `1.1.1.1`, enterprise gateways).
- **Jitter (ms)**: RFC 3550 statistical variance between consecutive packet arrival intervals.

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
If `wan1` experiences sudden jitter spikes (>15ms), the routing engine dynamically updates `nftables` policy routing marks, steering subsequent flows across `wan2_backup` without dropping ongoing user sessions.

---

## 6. Priority 5: In-Browser Clientless Web Application & Desktop Gateway (Status: Planned)

### 6.1 Architecture (Zero-Install HTML5 RDP/SSH Gateway)
- Eliminates the need for remote employees to install IPsec or WireGuard client software on personal endpoints.
- Remote operators log into the Micron OS Web Portal with username, password, and TOTP 2FA.
- The portal exposes an authorized catalogue of internal desktops (**RDP**), servers (**SSH**), and virtual consoles (**VNC**).
- Connections are mediated through a secure WebSocket tunnel translating graphics primitives to standard HTML5 Canvas at 60 FPS, with clipboard synchronization, audio streaming, and session recording for security audits.

---

## 7. Priority 6: Centralized Single Sign-On (SSO) with OIDC & SAML 2.0 (Status: Planned)

### 7.1 Identity Provider (IdP) Integrations
- Supports **OpenID Connect (OIDC)** and **SAML 2.0** protocols for enterprise directory synchronization:
  - Microsoft Entra ID (Azure Active Directory)
  - Okta Identity Cloud
  - Google Workspace
  - Keycloak / Authentik on-premises
- **Role Mapping Engine:** Maps IdP SAML attributes or OIDC group claims to Micron OS granular permissions (e.g. `Entra:SecOps-Network` → `interfaces.*`, `firewall.*`; `Entra:Helpdesk` → `diagnostics.*`, `dashboard.view`).
- Automated Just-In-Time (JIT) administrative user provisioning and session token revocation.

---

## 8. Priority 7: Automated Threat Intelligence Feed Ingest (Status: Planned)

### 8.1 Autonomous Feed Connectors
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

## 9. Implementation Roadmap & Execution Log

| Date | Phase | Milestone | Outcome |
| :---: | :---: | :--- | :--- |
| **2026-10-10** | **Priority 1** | Automated Configuration Diff & Git-Backed Revision History | Complete: Git sync, unified diff visualizer, instant rollback, automated version bump. |
| **2026-10-10** | **Priority 2** | Application Identification & Layer 7 DPI (AppID via nDPI) | Complete: nDPI compiler, nftables ct mark integration, live flow telemetry, catalogue and policy rulebase. |
| **2026-10-10** | **Priority 3** | Full SSL/TLS Deep Packet Inspection (MITM CA Engine) | Active: CA keystore integration, dynamic certificate forging, SSL bypass list. |
| **Future** | **Priorities 4–7** | SD-WAN SLA Steering, HTML5 Gateway, SSO (OIDC/SAML), Threat Intel Feeds | Documented in full; queued for sequential implementation. |

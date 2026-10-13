# Micron Firewall OS (Micron OS)
## Captive Portal & Hotspot Voucher Engine Architecture Specification
### Enterprise Guest WLAN, Voucher Billing, Walled Garden & Netfilter Redirection Blueprint

---

## Table of Contents
1. [Executive Summary & Purpose](#1-executive-summary--purpose)
2. [Architectural Overview & Interception Flow](#2-architectural-overview--interception-flow)
3. [Authentication Modes & Guest Onboarding](#3-authentication-modes--guest-onboarding)
4. [Netfilter Data Plane Enforcement (`nftables`)](#4-netfilter-data-plane-enforcement-nftables)
5. [Voucher Generation, Rolls & Quota Enforcement](#5-voucher-generation-rolls--quota-enforcement)
6. [Dynamic Splash Page Designer & Custom Branding](#6-dynamic-splash-page-designer--custom-branding)
7. [Walled Garden & Allowed Destinations](#7-walled-garden--allowed-destinations)
8. [Session Management, Accounting & Watchdog Daemon](#8-session-management-accounting--watchdog-daemon)
9. [Relational Database Schema (Dual SQLite / MySQL)](#9-relational-database-schema-dual-sqlite--mysql)
10. [Daemon IPC Bridge (`micrond` JSON-RPC Protocol)](#10-daemon-ipc-bridge-micrond-json-rpc-protocol)
11. [Management Control Plane & REST/Web Endpoints](#11-management-control-plane--restweb-endpoints)
12. [Verification, Quality Assurance & Test Strategy](#12-verification-quality-assurance--test-strategy)

---

## 1. Executive Summary & Purpose

Modern corporate campuses, hospitality venues, branch offices, and educational institutions require secure, auditable, and isolated guest network onboarding without compromising corporate LAN assets.

The **Micron OS Captive Portal & Hotspot Voucher Engine** provides an enterprise-grade guest access subsystem directly embedded into the firewall operating system. It combines wire-speed kernel packet redirection (`nftables`), multi-tenant captive zone isolation, cryptographic voucher roll generation, dynamic branding customization, and autonomous session watchdog accounting.

### Key Value Propositions
- **Zero-Dependency Wire-Speed Redirection:** Unauthenticated HTTP/HTTPS requests are intercepted at the Netfilter prerouting stage and diverted to the local captive portal daemon without userland proxy overhead.
- **Multiple Onboarding Models:** Supports click-through Terms of Service (AUP), cryptographic single/multi-use vouchers with bandwidth and data quotas, local credentials, and enterprise RADIUS authentication.
- **Autonomous Watchdog & Quota Enforcement:** Real-time bandwidth and data accounting with automatic session teardown and conntrack flushing upon quota or time expiration.
- **Walled Garden Acceleration:** High-performance IP/CIDR and domain whitelisting allowing access to payment gateways, corporate portals, and captive portal detection endpoints (Apple CNA, Google gstatic, Microsoft connectivity check).
- **Customizable Responsive Branding:** Turnkey CSS/HTML splash page customizer with logo upload, live color palette picker, and thermal printer-friendly voucher ticket generation.

---

## 2. Architectural Overview & Interception Flow

```
                      [ Unauthenticated Guest Device ]
                                    │
                         DHCP Lease (Kea DHCP)
                                    │
                                    ▼
       ┌─────────────────────────────────────────────────────────┐
       │                Linux Netfilter / nftables               │
       ├─────────────────────────────────────────────────────────┤
       │ 1. Is Source MAC/IP in `captive_authorized` set?        │
       │    ├── YES ────────────────────────────► Forward to WAN │
       │    └── NO                                               │
       │         │                                               │
       │ 2. Is Destination in `captive_walled_garden` set?       │
       │    ├── YES ────────────────────────────► Forward to WAN │
       │    └── NO                                               │
       │         │                                               │
       │ 3. Prerouting Redirection:                              │
       │    TCP 80/443 redirected to 127.0.0.1:8088 / 8443       │
       └────────────────────────────┬────────────────────────────┘
                                    │
                                    ▼
       ┌─────────────────────────────────────────────────────────┐
       │             Micron OS Captive Portal Web Engine         │
       │                    (Port 8088 / 8443)                   │
       ├─────────────────────────────────────────────────────────┤
       │ - Serves HTTP 302 Redirect to /portal/auth/{zone_slug}   │
       │ - Responds to Apple CNA, Android/Chrome & Windows tests │
       │ - Renders Branded Portal (Terms / Voucher / RADIUS)     │
       └────────────────────────────┬────────────────────────────┘
                                    │
                    [ Guest Submits Valid Voucher ]
                                    │
                                    ▼
       ┌─────────────────────────────────────────────────────────┐
       │             Session Provisioning & Authorization        │
       ├─────────────────────────────────────────────────────────┤
       │ 1. Validate voucher hash, time quota, and device limits │
       │ 2. Add Source IP & MAC to `nftables` authorized set     │
       │ 3. Record session in `captive_sessions` table           │
       │ 4. Issue `micrond` IPC session authorization signal     │
       │ 5. Redirect guest to original requested destination     │
       └─────────────────────────────────────────────────────────┘
```

### Captive Network Assistant (CNA) Auto-Detection
To trigger instant splash popups on mobile devices (iOS, iPadOS, Android, Windows 11), the Captive Portal HTTP engine detects standard probe requests and returns captive redirect signals:
- **Apple iOS / macOS:** `http://captive.apple.com/hotspot-detect.html` -> HTTP 302 Redirect.
- **Android / Google:** `http://connectivitycheck.gstatic.com/generate_204` -> HTTP 302 Redirect.
- **Microsoft Windows:** `http://www.msftconnecttest.com/connecttest.txt` -> HTTP 302 Redirect.
- **Firefox:** `http://detectportal.firefox.com/success.txt` -> HTTP 302 Redirect.

---

## 3. Authentication Modes & Guest Onboarding

Micron OS supports 4 distinct guest authentication workflows per captive zone:

| Mode | Authentication Protocol | Use Case | Typical Quotas |
| :--- | :--- | :--- | :--- |
| **Click-Through** | Acceptable Use Policy (AUP) checkbox | Free public Wi-Fi, cafes, libraries | 1 hour session, rate-limited |
| **Voucher Code** | Cryptographic token matching | Hotels, conferences, paid hotspots | 24 hours, 2GB quota, 10 Mbps |
| **Local Account** | Micron OS local user database | Contractors, branch office visitors | Multi-day access, role-based |
| **Enterprise RADIUS** | RFC 2865 PAP/MS-CHAPv2 AAA | Corporate guests, eduroam, WISP | Dynamic VLAN, RADIUS accounting |

---

## 4. Netfilter Data Plane Enforcement (`nftables`)

The captive portal data plane is compiled by `App\Libraries\CaptiveCompiler` into `/etc/nftables.d/captive.conf` and loaded into the kernel `inet filter` table.

### Kernel Sets Definition
```nftables
table inet filter {
    # Authorized devices (MAC & IPv4 with automatic kernel timeout)
    set captive_auth_mac_zone1 {
        type ether_addr
        flags timeout
    }
    set captive_auth_ip_zone1 {
        type ipv4_addr
        flags timeout
    }

    # Walled Garden IP addresses & CIDR subnets allowed without auth
    set captive_walled_garden_zone1 {
        type ipv4_addr
        flags interval
        elements = {
            17.0.0.0/8,        # Apple Captive CDN
            142.250.0.0/15,     # Google Connectivity Check
            20.0.0.0/11         # Microsoft Azure CDN
        }
    }

    # Redirection Chain
    chain captive_prerouting_zone1 {
        type nat hook prerouting priority -100; policy accept;
        iifname "eth2" ether saddr @captive_auth_mac_zone1 accept
        iifname "eth2" ip saddr @captive_auth_ip_zone1 accept
        iifname "eth2" ip daddr @captive_walled_garden_zone1 accept
        iifname "eth2" tcp dport 80 redirect to :8088
        iifname "eth2" tcp dport 443 redirect to :8443
    }

    # Forwarding Filter Chain
    chain captive_forward_zone1 {
        iifname "eth2" ether saddr @captive_auth_mac_zone1 accept
        iifname "eth2" ip saddr @captive_auth_ip_zone1 accept
        iifname "eth2" ip daddr @captive_walled_garden_zone1 accept
        iifname "eth2" drop
    }
}
```

---

## 5. Voucher Generation, Rolls & Quota Enforcement

### Voucher Properties
- **Voucher Roll ID:** Cryptographically unique batch identifier (e.g. `ROLL-2026-HOTEL-24H`).
- **Code Format:** Human-friendly uppercase alphanumeric string with high-confusion characters removed (`0/O`, `1/I/l` excluded) formatted in distinct blocks: `ABCD-EFGH-JKLM`.
- **Duration Quota:** Configurable lifetime starting from the moment of first activation (e.g. 30 minutes, 2 hours, 1 day, 7 days).
- **Data Volume Quota:** Total cumulative transfer cap (ingress + egress, e.g. 500 MB, 5 GB).
- **Bandwidth Limits:** Upstream and downstream rate shaping (e.g. 5 Mbps down / 2 Mbps up).
- **Concurrency Cap:** Maximum simultaneous devices allowed per voucher (e.g. 1 device for single-user, 3 devices for family passes).

### Thermal & Sheet Printing
The Web Management Plane includes a dedicated print formatter rendering ready-to-cut voucher tickets complete with QR codes for camera scan-to-connect.

---

## 6. Dynamic Splash Page Designer & Custom Branding

Each captive zone features an isolated branding configuration:
- **Header Logo:** Scalable SVG or PNG image asset.
- **Venue Title & Welcome Message:** Markdown or rich text hospitality instructions.
- **Terms of Service (AUP):** Scrollable legal disclaimer with mandatory acceptance checkbox.
- **Visual Styling:** Configurable CSS variables for brand primary accent, dark/light surface background, and typography.
- **Custom CSS / JS Injection:** Operator-defined CSS stylesheet overrides.

---

## 7. Walled Garden & Allowed Destinations

The Walled Garden subsystem allows unauthenticated guest devices to communicate with specific upstream servers before completing login:
1. **Connectivity Check Endpoints:** Mandatory for iOS CNA and Android Network Login triggers.
2. **Online Payment Gateways:** Stripe, PayPal, or local merchant gateways for credit card voucher purchase.
3. **Corporate Public Portals:** Company homepage, hotel amenities guide, or flight status boards.

---

## 8. Session Management, Accounting & Watchdog Daemon

The `system/micrond/micrond.py` daemon hosts the Captive Session Watchdog:
- **Periodic Accounting Poll:** Queries kernel conntrack byte counters per authorized IP/MAC.
- **Time Expiration:** When `now >= expires_at`, the IP/MAC is automatically removed from the kernel set and conntrack sessions are flushed (`conntrack -D -s <ip>`).
- **Quota Expiration:** When `(bytes_in + bytes_out) >= quota_bytes`, the session is terminated and flagged as `depleted`.
- **Idle Timeout:** If no packets pass for `idle_timeout_seconds` (default: 900s), the session is suspended.

---

## 9. Relational Database Schema (Dual SQLite / MySQL)

### 9.1 `captive_zones`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | VARCHAR(36) PK | UUID zone identifier |
| `name` | VARCHAR(64) | Human-readable zone name (e.g. "Guest Wi-Fi") |
| `interface_id` | VARCHAR(32) | Bound network interface (e.g. "eth2", "vlan100") |
| `auth_mode` | VARCHAR(24) | `clickthrough`, `voucher`, `local`, `radius` |
| `portal_port` | INT | Local HTTP redirect port (default: 8088) |
| `portal_ssl_port` | INT | Local HTTPS redirect port (default: 8443) |
| `session_timeout` | INT | Max session duration in minutes (0 = unlimited) |
| `idle_timeout` | INT | Idle timeout in seconds (default: 900) |
| `default_redirect_url`| VARCHAR(255) | Destination URL after login (or original URL) |
| `enabled` | TINYINT(1) | Administrative enable toggle |
| `created_at` / `updated_at` | DATETIME | Timestamps |

### 9.2 `captive_vouchers`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT PK | Numeric ID |
| `zone_id` | VARCHAR(36) | Parent zone foreign key |
| `roll_id` | VARCHAR(32) | Batch identifier |
| `code` | VARCHAR(32) UNIQUE | Uppercase authorization code |
| `duration_minutes` | INT | Session duration once activated |
| `quota_bytes` | BIGINT | Total allowed byte transfer (0 = unlimited) |
| `bandwidth_down_kbps`| INT | Download speed limit (0 = unlimited) |
| `bandwidth_up_kbps` | INT | Upload speed limit (0 = unlimited) |
| `max_devices` | INT | Concurrent device limit (default: 1) |
| `used_count` | INT | Active devices using voucher |
| `status` | VARCHAR(16) | `active`, `used`, `depleted`, `expired`, `revoked` |
| `first_used_at` | DATETIME NULL | Timestamp of initial activation |
| `expires_at` | DATETIME NULL | Hard expiration timestamp |
| `created_at` | DATETIME | Creation timestamp |

### 9.3 `captive_sessions`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | VARCHAR(36) PK | Session UUID |
| `zone_id` | VARCHAR(36) | Active captive zone |
| `ip_address` | VARCHAR(45) | Client IPv4/IPv6 address |
| `mac_address` | VARCHAR(17) | Client MAC address |
| `auth_type` | VARCHAR(16) | Authentication method used |
| `username` | VARCHAR(64) NULL | User account or voucher code |
| `bytes_in` | BIGINT | Cumulative inbound bytes |
| `bytes_out` | BIGINT | Cumulative outbound bytes |
| `connected_at` | DATETIME | Session start timestamp |
| `expires_at` | DATETIME | Expiration deadline |
| `last_seen_at` | DATETIME | Last packet timestamp |
| `status` | VARCHAR(16) | `authorized`, `expired`, `disconnected` |

### 9.4 `captive_walled_garden`
| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT PK | Primary key |
| `zone_id` | VARCHAR(36) | Associated captive zone |
| `target` | VARCHAR(255) | IP, CIDR subnet, or FQDN hostname |
| `description` | VARCHAR(128) | Operator note (e.g. "Stripe API Gateway") |
| `enabled` | TINYINT(1) | Active flag |

### 9.5 `captive_customizations`
| Field | Type | Description |
| :--- | :--- | :--- |
| `zone_id` | VARCHAR(36) PK | 1:1 zone branding mapping |
| `venue_title` | VARCHAR(128) | Heading rendered on splash portal |
| `welcome_message` | TEXT | Rich hospitality introduction |
| `terms_text` | TEXT | Acceptable Use Policy text |
| `primary_color` | VARCHAR(16) | Hex color code (e.g. `#4f46e5`) |
| `bg_color` | VARCHAR(16) | Background surface color (e.g. `#0f172a`) |
| `logo_path` | VARCHAR(255) NULL | Stored logo asset URI |
| `custom_css` | TEXT NULL | Custom CSS stylesheet overrides |

---

## 10. Daemon IPC Bridge (`micrond` JSON-RPC Protocol)

The management plane communicates with the Linux Netfilter data plane through the following IPC endpoints:

- `captive.apply`: Compiles and commits kernel `nftables` captive rules, sets, and prerouting redirects for all active zones.
- `captive.authorize`: Dynamically inserts a guest IP and MAC into kernel sets `captive_auth_ip_<zone>` and `captive_auth_mac_<zone>` with a specified timeout.
- `captive.revoke`: Removes a guest IP/MAC from kernel sets and executes conntrack session termination (`conntrack -D -s <ip>`).
- `captive.status`: Returns live session counts, active kernel set members, and per-zone traffic volume.
- `captive.watchdog_sync`: Synchronizes live byte counts from Netfilter counters into SQLite `captive_sessions` table.

---

## 11. Management Control Plane & REST/Web Endpoints

### Operator Administration Endpoints
- `GET  /services/captive` — Captive Portal Overview & Zone Matrix.
- `POST /services/captive/save-zone` — Create/Edit Captive Zone configuration.
- `POST /services/captive/delete-zone/{id}` — Remove Captive Zone.
- `GET  /services/captive/vouchers` — Voucher Roll Management & Batch Generator.
- `POST /services/captive/vouchers/generate` — Bulk voucher creation engine.
- `GET  /services/captive/vouchers/print/{roll_id}` — Printable voucher sheet & thermal slips.
- `POST /services/captive/vouchers/revoke/{id}` — Instant voucher revocation.
- `GET  /services/captive/sessions` — Live Connected Guest Sessions & Telemetry.
- `POST /services/captive/sessions/disconnect/{id}` — Manual kick/disconnect with conntrack wipe.
- `GET  /services/captive/branding/{zone_id}` — Splash Page Live Visual Customizer.
- `POST /services/captive/branding/save` — Persist custom branding & assets.

### Guest Facing Portal Endpoints (Port 8088 / 8443)
- `GET  /portal/auth/{zone_slug}` — Renders responsive splash page.
- `POST /portal/login` — Evaluates voucher, click-through, or user credentials.
- `GET  /portal/success` — Renders post-authentication confirmation screen.
- `GET  /portal/status` — Lightweight session telemetry widget (time remaining, bytes used).
- `POST /portal/logout` — Guest self-service disconnect.

---

## 12. Verification, Quality Assurance & Test Strategy

| Test Suite | Target Component | Verification Scope |
| :--- | :--- | :--- |
| **`CaptiveCompilerTest`** | `App\Libraries\CaptiveCompiler` | Compiles valid `nftables` sets, redirection hooks, and walled garden rulesets. |
| **`CaptiveVoucherTest`** | `App\Libraries\VoucherEngine` | Tests voucher generation randomness, collision resistance, duration parsing, and quota checks. |
| **`CaptiveControllerTest`** | `App\Controllers\Captive` | CRUD operations for zones, voucher generation, branding saves, and session teardown. |
| **`MicrondCaptiveTest`** | `system/micrond/micrond.py` | Validates IPC methods `captive.apply`, `captive.authorize`, `captive.revoke`. |
| **`StrictDecodingGuard`** | Python Harnesses | Ensures no lenient decoders or raw shell primitives are introduced. |

---

*Specification Version:* 1.0.0  
*Component Name:* Captive Portal & Hotspot Voucher Subsystem  
*Author:* Micron OS Advanced Engineering Team  

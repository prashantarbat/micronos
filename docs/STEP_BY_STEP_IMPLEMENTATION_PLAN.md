# Micron Firewall OS (MicronOS)
## Step-by-Step Implementation & Engineering Execution Plan
### Actionable Development Blueprint: From Local CodeIgniter 4 Scaffolding to Bootable Appliance ISO

---

## Table of Contents
1. [Execution Overview & Development Philosophy](#1-execution-overview--development-philosophy)
2. [Phase 0: Developer Workspace & Toolchain Setup (Windows / Laragon)](#2-phase-0-developer-workspace--toolchain-setup-windows--laragon)
3. [Phase 1: CodeIgniter 4 Framework Scaffolding & Dual-DB Initialization](#3-phase-1-codeigniter-4-framework-scaffolding--dual-db-initialization)
4. [Phase 2: Database Migrations & Relational Seeders (SQLite & MySQL)](#4-phase-2-database-migrations--relational-seeders-sqlite--mysql)
5. [Phase 3: The IPC Daemon (`micrond`) & Communication Bridge](#5-phase-3-the-ipc-daemon-micrond--communication-bridge)
6. [Phase 4: Web UI Management Interface & Responsive Design System](#6-phase-4-web-ui-management-interface--responsive-design-system)
7. [Phase 5: Network Engine & nftables Compiler Implementation](#7-phase-5-network-engine--nftables-compiler-implementation)
8. [Phase 6: Enterprise Identity (LDAP / Active Directory) & 2FA Setup](#8-phase-6-enterprise-identity-ldap--active-directory--2fa-setup)
9. [Phase 7: Road Warrior VPNs (OpenVPN Dynamic Challenge & WireGuard 2FA)](#9-phase-7-road-warrior-vpns-openvpn-dynamic-challenge--wireguard-2fa)
10. [Phase 8: Next-Gen Web Filtering & Squid TPROXY Integration](#10-phase-8-next-gen-web-filtering--squid-tproxy-integration)
11. [Phase 9: Encrypted Backup & Restore Engine (with NIC Remapper)](#11-phase-9-encrypted-backup--restore-engine-with-nic-remapper)
12. [Phase 10: Linux Appliance Verification in Virtual Lab (QEMU / Proxmox)](#12-phase-10-linux-appliance-verification-in-virtual-lab-qemu--proxmox)
13. [Phase 11: ISO Compilation & Physical Hardware Deployment](#13-phase-11-iso-compilation--physical-hardware-deployment)
14. [Phase 12: WAN Access, PPPoE & Subscriber Management](#14-phase-12-wan-access-pppoe--subscriber-management)
15. [Quality Assurance Checklist & Go-Live Criteria](#15-quality-assurance-checklist--go-live-criteria)

---

## 1. Execution Overview & Development Philosophy

Building **Micron Firewall OS** does not require building an operating system from scratch on day one. We follow a **modular, test-driven bottom-up execution strategy**:

```
┌────────────────────────────────────────────────────────────────────────┐
│                     DEVELOPMENT PROGRESSION PHASES                     │
├────────────────────────────────────────────────────────────────────────┤
│ STEP 1: Control Plane in Laragon (PHP 8.3 / CodeIgniter 4 / SQLite)     │
│         Scaffold web app, migrations, dual-DB abstraction, UI dashboard│
├────────────────────────────────────────────────────────────────────────┤
│ STEP 2: IPC & Simulation Layer                                         │
│         Build JSON-RPC mock client to simulate micrond on Windows      │
├────────────────────────────────────────────────────────────────────────┤
│ STEP 3: Linux Integration in Virtual Machine (Debian 13 minimal)       │
│         Run micrond natively, bind Unix Domain Socket, test nftables   │
├────────────────────────────────────────────────────────────────────────┤
│ STEP 4: Advanced Services (Squid TPROXY, WireGuard, OpenVPN 2FA, FRR)   │
│         Implement service compilers and live service reloads           │
├────────────────────────────────────────────────────────────────────────┤
│ STEP 5: ISO Packaging & Hardware Installer                             │
│         Run Debian live-build to generate bootable hybrid ISO          │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Phase 0: Developer Workspace & Toolchain Setup (Windows / Laragon)

Your current environment is **Windows with Laragon** at `d:\laragon\www\micronos`. We will build the entire management plane locally first.

### 2.1 Verify Local Dependencies
Open PowerShell or Laragon Terminal:
```powershell
# 1. Verify PHP 8.2 or 8.3
php -v

# 2. Verify Composer is available
composer -V

# 3. Verify SQLite extension is enabled in PHP
php -m | Select-String -Pattern "sqlite", "pdo_sqlite", "pdo_mysql", "curl", "mbstring", "openssl", "ldap"
```

If `php_ldap` or `php_sqlite3` are disabled, open `C:\laragon\bin\php\php-8.x\php.ini` and uncomment:
```ini
extension=ldap
extension=sqlite3
extension=pdo_sqlite
extension=pdo_mysql
extension=openssl
extension=curl
extension=fileinfo
```

---

## 3. Phase 1: CodeIgniter 4 Framework Scaffolding & Dual-DB Initialization

Initialize CodeIgniter 4 directly into `d:\laragon\www\micronos`:

### 3.1 Install CodeIgniter 4 AppStarter
Run in PowerShell:
```powershell
cd d:\laragon\www\micronos

# Install CI4 without overwriting existing docs
composer create-project codeigniter4/appstarter temp_ci --no-install
Copy-Item -Path "temp_ci\*" -Destination ".\" -Recurse -Force
Remove-Item -Path "temp_ci" -Recurse -Force

# Install Composer dependencies
composer install
```

### 3.2 Configure Environment File (`.env`)
Copy `env` to `.env`:
```powershell
Copy-Item env .env
```

Configure `.env` for development:
```ini
CI_ENVIRONMENT = development

# Base URL for Laragon virtual host
app.baseURL = 'http://micronos.test/'

# Primary Database Group: SQLite 3 for Appliance Mode
database.default.hostname = localhost
database.default.database = WRITEPATH . 'micron_config.db'
database.default.DBDriver = SQLite3
database.default.DBPrefix = ''

# Secondary Database Group: MySQL 8.4 for Enterprise / Central NOC Mode
database.enterprise.hostname = 127.0.0.1
database.enterprise.database = micronos_central
database.enterprise.username = root
database.enterprise.password = 
database.enterprise.DBDriver = MySQLi
database.enterprise.DBPrefix = ''
database.enterprise.port     = 3306
```

### 3.3 Test Local Web Server
Ensure Laragon starts Apache/Nginx. Open `http://micronos.test` in your browser. The default CodeIgniter 4 welcome screen will appear.

---

## 4. Phase 2: Database Migrations & Relational Seeders (SQLite & MySQL)

We will implement database migrations so that the exact same database schema runs seamlessly on both SQLite (appliance edge) and MySQL (enterprise cluster).

### 4.1 Generate Database Migrations
Run in terminal:
```powershell
php spark make:migration CreateInterfacesTable
php spark make:migration CreateFirewallRulesTable
php spark make:migration CreateNatRulesTable
php spark make:migration CreateUsersAnd2faTable
php spark make:migration CreateLdapServersTable
php spark make:migration CreateOpenvpnServersTable
php spark make:migration CreateWireguardTunnelsTable
php spark make:migration CreateWebfilterSettingsTable
php spark make:migration CreateSystemBackupsTable
php spark make:migration CreateAuditLogsTable
php spark make:migration CreateConfigTransactionsTable
```

### 4.2 Populate Interfaces Migration Example
Edit `app/Database/Migrations/*_CreateInterfacesTable.php`:
```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInterfacesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'physical_device' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'ip_assignment_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16, // 'static', 'dhcp', 'pppoe'
            ],
            'ipv4_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'ipv4_netmask' => [
                'type'       => 'INT',
                'null'       => true,
            ],
            'ipv4_gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'mtu' => [
                'type'       => 'INT',
                'default'    => 1500,
            ],
            'vlan_tag' => [
                'type'       => 'INT',
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('interfaces');
    }

    public function down()
    {
        $this->forge->dropTable('interfaces');
    }
}
```

### 4.3 Run Migrations & Seed Default Appliance State
```powershell
php spark migrate
```
Create a seeder for the default break-glass admin (`admin` / `micron` with mandatory password change on first login) and initial WAN/LAN interfaces:
```powershell
php spark make:seeder InitialApplianceSeeder
php spark db:seed InitialApplianceSeeder
```

---

## 4.5 Phase 2.5: Role-Based Access Control (RBAC) Implementation

### Status: COMPLETE

MicronOS implements a full Role-Based Access Control (RBAC) system with dynamic custom role support.

### 4.5.1 New Migrations (Runs After Phase 2)

| Migration | Table | Purpose |
| :-------- | :---- | :------ |
| `000015` | `roles` | Stores built-in and custom roles (`is_system` flag protects built-ins) |
| `000016` | `permissions` | Granular permission keys in dot-notation across all appliance modules (109 active permissions) |
| `000017` | `role_permissions` | Pivot: role-permission with `granted` flag (deny-override model) |
| `000018` | `user_roles` | Pivot: user-role with `assigned_by` for audit trail |
| `000019` | `ipsec_tunnels` | strongSwan IKEv1/IKEv2 Site-to-Site & Mobile VPN configuration |

### 4.5.2 Default Superadmin Account

| Field    | Value                               |
| :------- | :---------------------------------- |
| Username | `Ministrator` *(case-insensitive)*   |
| Password | `M1Cr0N` or `M1Cr0N80` (Argon2id)   |
| Role     | `SuperAdmin` — all 109 permissions  |

> [!NOTE]
> Case-insensitive login matches `LOWER(username) = strtolower($username)`. Dual password validation allows seamless administrator authentication using either `M1Cr0N` or `M1Cr0N80`.

### 4.5.3 Run RBAC Migrations & Seeder

```powershell
# Run all migrations (includes RBAC & IPsec tables)
php spark migrate

# Seed initial appliance state + built-in roles & all 109 permissions
php spark db:seed InitialApplianceSeeder
php spark db:seed RolesPermissionsSeeder
```

### 4.5.4 Built-In System Roles

| Role | Permissions | Use Case |
| :--- | :---------: | :------- |
| `SuperAdmin` | **All 109** | Break-glass full access |
| `NetworkAdmin` | ~52 | Network engineers (Interfaces, Routing, WireGuard, IPsec, OpenVPN) |
| `SecurityAdmin` | ~43 | Security operations / SOC (Firewall, WebFilter, IDS/IPS, IPsec) |
| `Operator` | ~25 | NOC monitoring / read-only |
| `VpnUser` | 2 | Remote workers, self-service only |

### 4.5.5 Custom Role Examples

```
BranchManager  → interfaces.view + dhcp.view + diag.ping
HelpDeskAgent  → users.view + vpn.openvpn.view + diag.logs.view
NetworkAuditor → all *.view permissions (no edit/apply/delete)
```

### 4.5.6 Files Created

| File | Purpose |
| :--- | :------ |
| `app/Filters/AuthFilter.php` | Session auth gate — globally applied (excludes `auth/*`) |
| `app/Filters/PermissionFilter.php` | Route-level permission check with session cache |
| `app/Models/RoleModel.php` | Role CRUD, permission sync, effective key resolution |
| `app/Models/PermissionModel.php` | Permission catalog + session-cached `userCan()` |
| `app/Config/Filters.php` | Registers `auth` + `permission` filter aliases |

### 4.5.7 Using Permissions in Routes

```php
// app/Config/Routes.php
$routes->group('firewall', ['filter' => 'auth'], function($r) {
    $r->get('rules',        'Firewall::index',  ['filter' => 'permission:firewall.rules.view']);
    $r->post('rules/store', 'Firewall::store',  ['filter' => 'permission:firewall.rules.edit']);
    $r->delete('rules/(:segment)', 'Firewall::delete', ['filter' => 'permission:firewall.rules.delete']);
});
```

### 4.5.8 Checking Permissions in Controllers and Views

```php
// In a controller method:
$permModel = new \App\Models\PermissionModel();
if ($permModel->userCan(session('user_id'), 'firewall.rules.edit')) {
    // show edit button / allow action
}

// In a CI4 view file:
<?php if (service('session')->get('user_permissions')
    && in_array('firewall.rules.edit', session('user_permissions'))): ?>
    <button class="btn-edit">Edit Rule</button>
<?php endif; ?>
```

---

## 5. Phase 3: The IPC Daemon (`micrond`) & Communication Bridge

Because Windows cannot execute native Linux `nftables` or bind Unix Domain Sockets directly, we build an **IPC Client Library with Automatic Mock Simulation** for local Windows development.

### 5.1 Create IPC Client Library (`app/Libraries/IpcClient.php`)
```php
<?php

namespace App\Libraries;

class IpcClient
{
    protected string $socketPath = '/run/micron/micrond.sock';
    protected bool $isWindows;

    public function __construct()
    {
        $this->isWindows = (DIRECTORY_SEPARATOR === '\\');
    }

    public function call(string $method, array $params = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => microtime(true),
        ];

        // Windows Mock Mode (Logs IPC payload to file for inspection)
        if ($this->isWindows) {
            log_message('info', '[MOCK IPC] ' . json_encode($payload));
            return [
                'status'  => 'success',
                'mock'    => true,
                'message' => 'Simulated execution on Windows host.',
                'applied' => true
            ];
        }

        // Linux Production Mode (Unix Domain Socket)
        $socket = @fsockopen('unix://' . $this->socketPath);
        if (!$socket) {
            throw new \RuntimeException("Cannot connect to micrond daemon at {$this->socketPath}");
        }

        fwrite($socket, json_encode($payload) . "\n");
        $response = fgets($socket);
        fclose($socket);

        return json_decode($response, true)['result'] ?? [];
    }
}
```

---

## 6. Phase 4: Web UI Management Interface & Responsive Design System

Build the modern, sleek, high-contrast dark dashboard inspired by enterprise networking consoles. Detailed engineering blueprints are codified in the [UI/UX Comprehensive Modernization Plan](UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.html) (`docs/UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.md`).

### 6.1 View Layout Blueprint (`app/Views/layouts/main.php`)
- **Left Sidebar:** Sticky navigation covering Dashboard, Interfaces, Firewall Rules, NAT, Routing, Web Proxy, VPN (WireGuard / OpenVPN), Identity / 2FA, System Backup, and Diagnostics.
- **Top Bar:** Hostname, appliance uptime, CPU/RAM usage meters, WAN status badges, Commit Watchdog pending notification, and Admin profile dropdown.
- **Main Viewport:** High-density responsive tables, live SVG packet counters, and drag-and-drop rule reordering.

### 6.2 Implement Dashboard Controller (`app/Controllers/Dashboard.php`)
Queries interface statuses, active WireGuard handshakes, and system loads, rendering the live telemetry screen.

### 6.3 Modular Card & Widget Engine (pfSense / FortiGate Style)
- **Customizable Dashboard Cards**: Modular card grid where administrators can dynamically add, remove, and rearrange cards across a 1-, 2-, or 3-column responsive layout.
- **Widget Catalogue & Drawer**: Categorized card store (`+ Add Widget`) providing 14 specialized cards (System Telemetry, Live Traffic SVG Graphs, Live Firewall Filter Log, Gateway SLA Probes, Core Services Watchdog, VPN Tunnels, Kea DHCP Leases, Unbound DNS Resolver, Suricata Threat Intel, and Audit Trail).
- **Close & Reorder**: Each card includes drag handles for HTML5 drag-and-drop rearrangement, a close/remove (`✕`) button, and contextual settings (custom polling intervals: 1s, 2s, 5s, 10s, manual; graph styles).
- **Per-User Persistence**: Layout geometry and active card selections are saved asynchronously in the `dashboard_user_layouts` database table and synced with `localStorage`.

### 6.4 Interactive Live Physical Interface Faceplate (FortiGate Style)
- **Photorealistic Chassis Visualization**: Front/rear panel hardware faceplate rendering physical RJ45 modular jacks and SFP+ optical fiber cages.
- **Live Carrier & Activity LEDs**:
  - Link/Speed LED: Cyan (10G/2.5G), Green (1 Gbps), Amber (100 Mbps), Dark (unplugged/down).
  - Activity LED: Real-time pulse and blink animation scaled dynamically to packet throughput rates (pps).
- **Interactive Hover HUD**: Glassmorphic heads-up display on port hover revealing IP address, subnet, gateway, MAC, duplex, real-time Rx/Tx bandwidth, and error counters within 50ms.
- **Click-to-Inspect Drawer**: Clicking any physical port opens an inline diagnostic inspector offering administrative Up/Down toggles, a 60-second rolling throughput sparkline, and inline packet capture (tcpdump).
- **Adaptive Topology**: Dynamically detects host virtual machine (Hyper-V / KVM) or physical hardware NIC configuration to render the matching port matrix.

---

## 7. Phase 5: Network Engine & nftables Compiler Implementation

The core duty of Micron OS is translating database records into deterministic `/etc/nftables.conf` files.

### 7.1 Implement Compiler Library (`app/Libraries/ConfigCompiler.php`)
```php
<?php

namespace App\Libraries;

use App\Models\InterfaceModel;
use App\Models\RuleModel;
use App\Models\NatModel;

class ConfigCompiler
{
    public function compileNftables(): string
    {
        $interfaceModel = new InterfaceModel();
        $ruleModel      = new RuleModel();
        $natModel       = new NatModel();

        $interfaces = $interfaceModel->where('enabled', 1)->findAll();
        $rules      = $ruleModel->where('enabled', 1)->orderBy('sequence', 'ASC')->findAll();
        $natRules   = $natModel->where('enabled', 1)->findAll();

        $out = "# Generated by Micron Firewall OS\n";
        $out .= "flush ruleset\n\n";

        // Define Fast-Path Flowtable
        $devList = implode(', ', array_map(fn($i) => $i['physical_device'], $interfaces));
        $out .= "table inet filter {\n";
        $out .= "    flowtable ft_fastpath {\n";
        $out .= "        hook ingress priority 0\n";
        $out .= "        devices = { {$devList} }\n";
        $out .= "    }\n\n";

        // Filter Chains (Input, Forward, Output)
        $out .= "    chain input {\n";
        $out .= "        type filter hook input priority 0; policy drop;\n";
        $out .= "        iif \"lo\" accept\n";
        $out .= "        ct state established,related accept\n";
        $out .= "        ct state invalid drop\n";
        $out .= "        tcp dport { 80, 443 } accept\n"; // Web GUI
        $out .= "        icmp type echo-request accept\n";
        $out .= "    }\n\n";

        $out .= "    chain forward {\n";
        $out .= "        type filter hook forward priority 0; policy drop;\n";
        $out .= "        ip protocol { tcp, udp } flow offload @ft_fastpath\n";
        $out .= "        ct state established,related accept\n";

        // Render dynamic user rules
        foreach ($rules as $r) {
            $proto = $r['protocol'] !== 'any' ? "{$r['protocol']} " : "";
            $src   = $r['source_value'] !== 'any' ? "ip saddr {$r['source_value']} " : "";
            $dst   = $r['dest_value'] !== 'any' ? "ip daddr {$r['dest_value']} " : "";
            $dport = !empty($r['dest_port']) ? "dport {$r['dest_port']} " : "";
            $action = $r['action'] === 'pass' ? 'accept' : 'drop';

            $out .= "        {$src}{$dst}{$proto}{$dport}{$action}\n";
        }

        $out .= "    }\n";
        $out .= "}\n";

        return $out;
    }
}
```

---

## 8. Phase 6: Enterprise Identity (LDAP / Active Directory & RADIUS) & External Group RBAC Mapping

### 8.1 Active Directory & LDAP Enterprise Authentication Service (`app/Libraries/LdapAuthService.php`)
- **Direct Directory Connectivity**: Connects to domain controllers via `ldaps://` (port 636) or StartTLS (`ldap://` port 389) with configurable socket timeout.
- **Directory Schema Presets**: Supports Active Directory (`sAMAccountName`, `userPrincipalName`, `memberOf`) and OpenLDAP / RFC 4519 (`uid`, `member`, `posixAccount`).
- **Resilience & Local Fallback**: Implements multi-server failover. If domain controllers are unreachable, `fallback_local = 1` preserves access for local break-glass administrators.
- **Interactive Directory Test Tool**: Exposes `POST /system/ldap/test` and `POST /system/ldap/search` for real-time bind verification and user attribute inspection from the GUI.

### 8.2 Enterprise RADIUS Protocol Client & Accounting Service (`app/Libraries/RadiusAuthService.php`)
- **RFC 2865 AAA Engine**: Builds raw binary `Access-Request` datagrams over UDP port 1812 containing User-Name, User-Password (PAP, CHAP, or MS-CHAPv2), NAS-IP-Address, and NAS-Identifier.
- **Response Validation**: Validates 16-byte MD5 response authenticators against the configured shared secret to protect against replay attacks.
- **Authorization Attribute Parsing**: Extracts RFC standard attributes (`Filter-Id`, `Class`) and Vendor-Specific Attributes (VSA) to determine remote group memberships.
- **RFC 2866 Accounting**: Supports session accounting over UDP port 1813 with Start, Stop, and Interim-Update packet dispatches.
- **Packet Diagnostic Probe**: Exposes `POST /system/radius/test` to verify shared secret accuracy and response code evaluation from the UI.

### 8.3 External Group-to-Role Mapping Engine (`app/Models/AuthGroupMappingModel.php` & `/roles/group-mappings`)
- **Database Schema**: Implements `auth_group_mappings` table linking external AD/LDAP and RADIUS group strings to internal MicronOS roles (`roles.id`).
- **Priority-Weighted Conflict Resolution**: Resolves overlapping group memberships deterministically by evaluating rules in ascending priority order (`priority ASC`), assigning the highest-privileged matched role.
- **Centralized Administration**: Dedicated UI in **Roles & Permissions > External Group Mappings** (`/roles/group-mappings`) with inline CRUD modals and server affinity toggles.

### 8.4 Users & Identities Federation & Directory Importer (`/users`)
- **Multi-Source Inventory**: Tracks user origins (`auth_source`: `local`, `ldap`, `radius`) with live UI distribution counters and quick filter pills.
- **Cached Directory Attributes**: Stores user Distinguished Name (`ldap_dn`) and resolved group membership (`external_group`) in the `users` table for audit logging.
- **Relaxed Password Hashing**: External directory users bypass local password hashing requirements, delegating authentication directly to upstream identity providers.
- **Directory User Import Wizard**: Provides `POST /users/import-ldap` to query remote directories, preview discovered accounts, and enroll selected users with pre-assigned roles.

### 8.5 Dynamic Delegated Authentication & Just-In-Time (JIT) Provisioning (`app/Controllers/Auth.php`)
- **Automated Delegation Cascade**: Evaluates local credentials for local users, or delegates to `LdapAuthService` / `RadiusAuthService` for directory accounts.
- **Just-In-Time (JIT) Auto-Provisioning**: Discovers and auto-creates user records upon first successful authentication, binding the identity to the role resolved by the group mapping engine.
- **Session Permissions Hydration**: Resolves the union of all 109 RBAC permissions into the session array (`user_permissions`), delivering zero-DB-query authorization checks throughout the session lifecycle.
- **Comprehensive Audit Trail**: Emits structured login events into `audit_logs` capturing client IP, username, authentication source, and timestamp.

### 8.6 Implement RFC 6238 TOTP Engine (`app/Libraries/TotpEngine.php`)
- Generates 160-bit Base32 secret keys.
- Renders QR code for Google Authenticator / Microsoft Authenticator enrollment.
- Validates 6-digit rolling codes with clock drift allowance (window $\pm 1$ step).
- Stores secrets encrypted with AES-256-GCM.

---

## 9. Phase 7: Road Warrior VPNs (OpenVPN Dynamic Challenge & WireGuard 2FA)

### 9.1 OpenVPN Road Warrior Configuration
1. Scaffold `VpnOpenvpn.php` controller.
2. Build client bundle generator:
   - Exports `.ovpn` file containing embedded `<ca>`, `<cert>`, `<key>`, and `<tls-crypt>` tags.
   - Embeds `auth-user-pass` and `static-challenge "Enter 2FA Code" 1` for dynamic two-factor authentication.

### 9.2 WireGuard Ephemeral Captive Lease Engine
1. Scaffold `VpnWireguard.php` controller for managing server public/private keys.
2. Build `VpnUserPortal.php`:
   - Remote worker connects WireGuard client.
   - Visits `https://vpn.corp.micronos.internal`.
   - Authenticates via AD + 2FA.
   - System adds IP to `active_vpn_users` set in `nftables` with an 8-hour timeout.

### 9.3 strongSwan IPsec / IKEv2 Engine (Site-to-Site & Mobile)
1. **strongSwan swanctl Integration:**
   - Generates `/etc/swanctl/conf.d/micronos-ipsec.conf` dynamically from SQLite `ipsec_tunnels` table.
   - Supports Phase 1 IKE proposals: `aes256gcm16-prfsha384-ecp384`, `aes256-sha256-modp2048`, `chacha20poly1305-prfsha512-curve25519`.
   - Supports Phase 2 Child SA (ESP) proposals: `aes256gcm16-ecp384`, `aes256-sha256-modp2048`, `chacha20poly1305-curve25519`.
2. **Tunnel Operational Modes:**
   - **Site-to-Site Policy & Route-based VTI (`xfrm0` / `vti0`):** Encrypts traffic between headquarters and branch offices with dynamic routing over BGP/OSPF via FRR.
   - **Mobile IKEv2 Road Warrior:** Native Apple iOS/macOS and Windows 10/11 native client support with EAP-MSCHAPv2 / RADIUS authentication.
3. **Daemon Control & Telemetry:**
   - Queries `swanctl --list-sas` and `swanctl --list-conns` via `micrond` IPC bridge to render active cryptographic SA states in the UI.

---

## 10. Phase 8: Next-Gen Web Filtering & Squid TPROXY Integration

1. **Squid Configuration Writer:** Generates `/etc/squid/squid.conf` with explicit (`3128`) and TPROXY ports (`3129` HTTP, `3130` HTTPS).
2. **TLS SNI Peek & Splice:** Configures `ssl_bump peek step1; ssl_bump splice all` so non-corporate BYOD endpoints are filtered without root certificate deployment.
3. **C-ICAP / ClamAV Antivirus Scanner:** Enables real-time streaming file payload scans with recursive archive unpacking.
4. **Blocklist Ingestion Service:** Pulls Shallalist / UT1 category tarballs, compiles them into SQLite domain lookup tables.
5. **Turnkey WPAD & PAC Auto-Discovery Service (`app/Controllers/Webfilter.php`):**
   - Serves dynamic `/wpad.dat` and `/proxy.pac` with `application/x-ns-proxy-autoconfig` MIME-type.
   - Generates JavaScript proxy rules with RFC 1918 private subnet bypass and primary/secondary proxy failover.
   - Integrates with Kea DHCP (Option 252) and Unbound DNS (`wpad.<search-domain>`).

---

## 11. Phase 9: Encrypted Backup & Restore Engine (with NIC Remapper)

**Built as:** `App\Libraries\BackupEngine` (plus `App\Controllers\Backup` for the upload/download side). Two deliberate departures from this plan are recorded in architecture guide §17, because the plan's design was never implemented and §17 used to describe it as though it had been.

1. **Backup Engine (`app/Libraries/BackupEngine.php`):**
   - Implements **AES-256-GCM** encryption, with **PBKDF2-SHA256** (100 000 rounds, 16-byte salt) key derivation. Argon2id remains planned: it needs `ext-sodium` in the ISO package manifest.
   - Packages the configuration as **one JSON document** — a `tables` map of rows plus a header and `hardware.interfaces` — written as `.mbk` plain, or wrapped in the `MICRON_ENC_V1` JSON envelope as `.mbk.enc`. There is no tar container, no SQL dump and no `secrets/` directory; key material is a `secrets.master_key` field inside the encrypted envelope.
2. **Restore (`BackupEngine::restore()` and `App\Controllers\Backup`; there is no `RestoreManager`):**
   - Decrypts through the envelope and validates the GCM authentication tag before anything is written.
   - Supports an operator-supplied **old → new NIC map**, applied to `interfaces.physical_device`. Automatic mismatch detection exists (`BackupEngine::inspectHardwareDifferences()`) but is not wired to the UI yet.
   - Restores inside a **single database transaction**, so a failure keeps the previous configuration. The **60-second commit watchdog around a restore is still planned** — a restore commits its database state and then asks the daemon to recompile (`system.commit`).

---

## 12. Phase 10: Linux Appliance Verification in Virtual Lab (QEMU / Proxmox)

To test the application in a real Linux kernel environment before compiling the final ISO:

### 12.1 Deploy Debian 13 Minimal Test VM
1. Create a minimal Debian 13 VM in VirtualBox, VMware, or Proxmox VE.
2. Add 2 Network Adapters:
   - Adapter 1: Bridged / NAT (Simulating WAN)
   - Adapter 2: Host-Only / Internal Switch (Simulating LAN `192.168.1.0/24`)
3. Clone your CodeIgniter 4 repository into `/usr/local/micron` (the path the appliance itself uses — see the ISO guide §4.1).
4. Install Apache 2.4, PHP 8.4 FPM, SQLite3, and `nftables`.
5. Run the application live: verify that clicking "Apply" in the Web UI triggers real `nftables` firewall rules!

---

## 13. Phase 11: ISO Compilation & Physical Hardware Deployment

Follow the instructions in the [ISO & Installer Guide](ISO_CREATION_AND_INSTALLER_GUIDE.html) (`docs/ISO_CREATION_AND_INSTALLER_GUIDE.md` in the repository):
1. Install `live-build` on the build machine.
2. Stage the appliance overlay: `packaging/live-build/stage-overlay.sh` (run automatically by `auto/config` before `lb config`) installs the Terminal CLI and the **whole web application** from the repository into `config/includes.chroot/usr/local/micron`, replacing that tree rather than merging into it, so no hand-copied application can drift from the sources.
3. Run `sudo lb build` to output `micronos-2.0.0-amd64.hybrid.iso`.
4. Flash to a USB flash drive using Rufus or `dd`.
5. Boot physical hardware (Intel/AMD multi-NIC mini PC or 1U server), complete the Ncurses installer, and log into `https://192.168.1.1`.

---

## 14. Phase 12: WAN Access, PPPoE & Subscriber Management

### Status: COMPLETE

This phase adds the WAN-edge access layer: PPPoE client uplinks, a PPPoE subscriber access server (BRAS), and the authentication/policy engines behind it (RADIUS auth, RADIUS accounting, EAP-TLS), plus the Internet Service Database that keeps service-oriented address objects current.

### 14.1 Migrations

| Migration | Adds |
| :--- | :--- |
| `000055_AddPppoeClientAndServer` | `interfaces` PPPoE client columns; `pppoe_servers`, `pppoe_server_users`. |
| `000056_SeedPppoeRedialCronJob` | `cron_pppoe_redial` scheduled task. |
| `000057_AddRadiusAuthToPppoeServers` | RADIUS auth columns (server, port, secret, timeout, retries, NAS-ID, second server). |
| `000058_AddRadiusAccountingToPppoeServers` | `radius_accounting`, `radius_acct_port`, `radius_acct_server`. |
| `000059_AddEapTlsToPppoeServers` | `eap_tls` and the four certificate/key columns plus CRL. |
| `000060_AddPppoeBackoffAndSessionHistory` | `pppoe_backoff_states`, `pppoe_session_history`. |
| `000048`–`000053` | Internet Service Database tables and address-object `resolved_prefixes`. |

### 14.2 Compiler (`app/Libraries/PppoeCompiler.php`)

- `compileClientPeer()` / `compileClientSecrets()` — WAN PPPoE peer files and `chap-secrets`.
- `compileServerOptions()` / `compileServerSecrets()` — BRAS options and subscriber secrets.
- `compileRadiusServers()` / `compileRadiusClientConf()` / `radiusServerOptions()` — RADIUS plugin chain, `authserver`/`acctserver` lines, and the failover list.
- `compileEapTlsServer()` / `eapTlsServerOptions()` / `eapTlsFiles()` / `validateEapTls()` — EAP-TLS secrets file, `require-eap`, PEM validation, and per-file paths.
- `checkClientLinkHealth()` — health-aware redial decisions.

> **Key correctness rules:** emit **no** `plugin eap-tls.so` line (EAP-TLS is compiled into pppd); load `radius.so` **before** `radattr.so`; only emit an `acctserver` line when accounting is enabled.

### 14.3 Daemon (`system/micrond/micrond.py`)

- `rpc_pppoe_apply` writes peer files, secrets (`0600`), RADIUS client config, EAP-TLS certificates (`0644`), keys (`0600`), and the `eaptls-server` secrets file, then starts concentrators.
- `rpc_pppoe_redial` performs health-aware repair with a `force` override.
- `rpc_pppoe_radius_test` / `rpc_pppoe_radius_status` back the RADIUS test tool and config view.
- `_radius_attributes()` reads `/var/run/radattr.<iface>` for per-session reply attributes.

### 14.4 Controllers, Commands & Views

- `app/Controllers/Pppoe.php` — servers/users CRUD, `apply`, `testRadius`, `redial`, subscriber history.
- `app/Commands/PppoeRedial.php` — `micron:pppoe-redial [--interface] [--down] [--force] [--ipc]`.
- `app/Views/pppoe/index.php` + `_sessions.php` — server/subscriber modals, RADIUS/accounting/EAP-TLS fields, live sessions with attribute chips.
- `app/Controllers/InternetServices.php` — service sync and address-object creation.
- `app/Commands/SyncServices.php` / `ResolveObjects.php` — `micron:sync-services`, `micron:resolve-objects`.

### 14.5 Verification

- Unit tests: `PppoeCompilerTest`, `PppoeRadiusTest`, `RadiusAttributesTest`, `PppoeRedialMockTest`, `CronJobLogTest`, `PppoeEapTlsTest`.
- RADIUS is exercised with a real `radclient` against a UDP responder (accept / reject / unreachable).
- Live HTTP check of the `/pppoe` page confirms card states, modal field names, and live-session attribute rendering.

---

## 15. Quality Assurance Checklist & Go-Live Criteria

Before shipping Micron Firewall OS to production branch offices:

- [ ] **Zero-Root Verification:** Verify `php-fpm` runs under `www-data` and cannot run root commands without `micrond`.
- [ ] **Commit Watchdog Rollback:** Intentionally add a rule blocking port 443; verify that the system auto-recovers after 60 seconds without power cycling.
- [ ] **Power Failure Resilience:** Pull the power plug during high web activity; verify that SQLite WAL journal mode recovers instantly on reboot without database corruption.
- [ ] **Stateful HA Failover:** Initiate an active VoIP/SIP call or iperf3 TCP transfer; pull the WAN cable on Firewall A and verify Firewall B continues the stream via `conntrackd` without session reset.
- [ ] **2FA Enforcement:** Verify that remote access VPN rejects connections if TOTP is incorrect, and logs an audit security event.
- [ ] **Hardware NIC Remap:** Restore an Intel 4-port backup onto a Realtek appliance and confirm the remapping wizard maps all rules seamlessly.
- [ ] **PPPoE WAN Redial:** Down a PPPoE uplink, run `micron:pppoe-redial`, and confirm only the failed link is rebuilt and healthy links are reported as skipped.
- [ ] **RADIUS Auth & Accounting:** Exercise the RADIUS test tool; confirm Start/Stop accounting records arrive and that Interim-Update appears only when `Acct-Interim-Interval` is returned.
- [ ] **EAP-TLS Coexistence:** On a server with PAP/CHAP enabled, confirm an EAP-TLS client authenticates while an existing CHAP subscriber still connects.
- [ ] **Internet Service Resolution:** Run `micron:sync-services` and confirm a service-backed address object resolves to prefixes and that a policy referencing it compiles.

---

*Document Version:* 1.0.0 (Step-by-Step Implementation Guide)  
*Project:* Micron Firewall OS (MicronOS)  
*Next Step:* Begin Phase 1 (CodeIgniter 4 Scaffolding & Database Migrations)  

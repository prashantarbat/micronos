# Micron OS — UI/UX Comprehensive Modernization Plan

## 1. Executive Summary & Design Vision

This engineering specification establishes the architectural blueprint for the next-generation web user interface and user experience of **Micron OS**. The objective is to elevate the appliance management interface into an enterprise-tier Network Operations Center (NOC) and Security Operations Center (SOC) console, achieving visual excellence and operational capability inspired by leading appliances such as **FortiGate (FortiOS)** and **pfSense Plus**.

The core pillars of this modernization plan include:
1. **Dynamic Modular Card & Widget Engine**: A fully customizable dashboard where administrators can add, remove, resize, configure, and rearrange functional cards across a fluid responsive grid, mirroring the flexibility of pfSense and FortiGate.
2. **Interactive Live Physical Interface Faceplate**: A photorealistic, real-time chassis front-panel visualizer inspired by FortiGate enterprise firewalls, rendering physical RJ45 and SFP+ ports with live link carrier LEDs, dynamic packet-activity blinking, detailed hover telemetry heads-up displays (HUD), and instant click-to-inspect port drawers.
3. **Enterprise Design System & High-Information Density**: Curated dark cyber aesthetics, fluid micro-animations, glassmorphic surfaces, low-latency live telemetry streams (<2s polling with ETag caching and WebSocket/SSE capability), and strict zero-dependency vanilla frontend architecture.

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                     MICRON OS MANAGEMENT CONSOLE                                 │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│ [Top Bar]  Hostname: micron-edge01  │  OS: Micron OS v0.1.25  │  Uptime: 14d 18h  │  Admin: ministrator │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                                  │
│  ┌────────────────────────────────────────────────────────────────────────────────────────────┐  │
│  │ █ INTERACTIVE PHYSICAL CHASSIS FACEPLATE (FortiGate Style)                                  │  │
│  │  [PWR:●] [STS:●]  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐   ┌───────┐ ┌───────┐                      │  │
│  │                   │WAN 1│ │WAN 2│ │LAN 1│ │LAN 2│   │ SFP+1 │ │ SFP+2 │                      │  │
│  │   Link LEDs:      │ 🟢  │ │ 🟢  │ │ 🟢  │ │ ⚪  │   │  🔵   │ │  ⚪   │                      │  │
│  │   Activity LEDs:  │ ⚡blink│⚡blink│⚡blink│     │   │ ⚡pulse│         │                      │  │
│  │                   └─────┘ └─────┘ └─────┘ └─────┘   └───────┘ └───────┘                      │  │
│  │   Hover HUD: IP, MAC, Duplex, Rate (Mbps), Drops  │ Click: Port Drawer & Packet Capture     │  │
│  └────────────────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                                  │
│  [+ Add Widget]  [Layout: 3-Columns ▾]  [Auto-Refresh: 2s ▾]  [Reset Layout]  [Save Profile ▾]   │
│                                                                                                  │
│  ┌────────────────────────┐  ┌────────────────────────┐  ┌───────────────────────────────────┐  │
│  │ Card: System Health    │  │ Card: Real-Time Traffic│  │ Card: Firewall Live Filter Log    │  │
│  │  CPU: 18% [■■        ] │  │  Dual Area SVG Through-│  │  14:32:01 WAN1 DROP 198.51.100.2  │  │
│  │  RAM: 42% [■■■■      ] │  │  put Chart (Rx/Tx)     │  │  14:32:02 LAN1 PASS 10.0.10.45    │  │
│  │  States: 4,120 / 300K  │  │  Peak: 98.4 Mbps       │  │  14:32:03 WAN2 DROP 203.0.113.89  │  │
│  │  [⚙] [⛶] [⟳] [✕]       │  │  [⚙] [⛶] [⟳] [✕]       │  │  [⚙] [⛶] [⟳] [✕]                  │  │
│  └────────────────────────┘  └────────────────────────┘  └───────────────────────────────────┘  │
│  ┌────────────────────────┐  ┌────────────────────────┐  ┌───────────────────────────────────┐  │
│  │ Card: WAN Gateway SLA  │  │ Card: Active Services  │  │ Card: WireGuard & IPsec Tunnels   │  │
│  │  wan1: 14ms (0% loss)  │  │  micrond:  ● RUNNING   │  │  wg0 (HQ Branch):  ● Connected    │  │
│  │  wan2: 28ms (0.2% loss)│  │  nftables: ● ACTIVE    │  │  ipsec0 (DC Site): ● Established  │  │
│  │  [⚙] [⛶] [⟳] [✕]       │  │  [⚙] [⛶] [⟳] [✕]       │  │  [⚙] [⛶] [⟳] [✕]                  │  │
│  └────────────────────────┘  └────────────────────────┘  └───────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Dynamic Modular Card & Widget Engine (pfSense / FortiGate Style)

### 2.1 Concept & Functional Requirements

In enterprise network engineering, static dashboards fail to meet the diverse operational requirements of different administrator roles. A security engineer requires real-time IDS/IPS alerts and firewall drops, while a network engineer focuses on interface link errors, BGP neighbor sessions, and SD-WAN path jitter.

**Micron OS** implements a modular card engine adhering to the following design rules:
1. **Independent Widget Encapsulation**: Every dashboard card operates as a self-contained visual component with standard header controls (Drag Handle, Widget Title, Status Badge, Settings Gear, Refresh Button, and Close/Remove `✕` Button).
2. **Add / Remove Widget Store**: A prominent `+ Add Widget` action opens a categorized widget drawer/modal. Administrators can toggle cards on or off in real time with immediate DOM insertion or removal.
3. **Fluid Multi-Column Drag-and-Drop Layout**: Administrators can drag widgets by their header handles to rearrange their visual order across 1, 2, 3, or 4 grid columns. The layout auto-balances using a responsive CSS Grid / Flexbox masonry engine.
4. **Per-Card Contextual Settings**: Individual widgets feature custom parameters (e.g. refresh rate: 1s, 2s, 5s, 10s, manual; graph display mode; target interface; number of rows to render).
5. **Per-User Layout Persistence**: Layout arrangements (widget IDs, column spans, visible flags, sort orders, and custom settings) are persisted in the database linked to the authenticated user ID, with fallback synchronization in browser `localStorage`.
6. **Multiple Dashboard Tabs / Profiles**: Support for switching between pre-configured operational views (e.g., *Main Overview*, *Network NOC*, *Security & Threat SOC*, *WAN & SD-WAN*).

### 2.2 Standard Widget Catalog

The modular engine provides an extensive library of out-of-the-box cards:

| Widget Identifier | Card Title | Category | Default Span | Description & Key Metrics |
| :--- | :--- | :--- | :---: | :--- |
| `widget_chassis` | **Physical Chassis & Ports** | Hardware | Full Width | Interactive FortiGate-style hardware faceplate with live LEDs, hover telemetry HUD, and port inspector drawer. |
| `widget_sysinfo` | **System Information** | System | 1 Column | Hostname, Micron OS release channel, kernel version, BIOS/UEFI, CPU model, system uptime, and commit hash. |
| `widget_gauges` | **Resource Telemetry** | System | 1 Column | Circular / linear gauges for CPU load, RAM usage, Swap, Conntrack state table utilization, and NVMe root disk storage. |
| `widget_traffic` | **Real-Time Traffic Graph** | Network | 2 Columns | Live dual-area SVG chart rendering Rx/Tx throughput in Mbps, packet rate (pps), peak bandwidth, and interface switcher. |
| `widget_interfaces` | **Interface Status Matrix** | Network | 2 Columns | Tabular view of all network adapters with link status (UP/DOWN), media speed, IP address, MAC, MTU, and cumulative byte counters. |
| `widget_gateways` | **WAN & SD-WAN Health** | Network | 1 Column | Uplink status, gateway IP, real-time RTT latency (ms), packet loss percentage, jitter (ms), and active SLA compliance. |
| `widget_filter_log` | **Firewall Live Filter Log** | Security | 2 Columns | Real-time scrolling packet stream of dropped and accepted flows with protocol, source/destination IPs, ports, and rule ID. |
| `widget_services` | **Core Services Watchdog** | System | 1 Column | Operational status of system daemons (`micrond`, `nftables`, `unbound`, `kea-dhcp4`, `suricata`, `squid`, `strongswan`) with restart buttons. |
| `widget_vpn` | **VPN Tunnels & Clients** | Network | 1 Column | Active WireGuard peers, OpenVPN road warriors, and IPsec IKEv2 site-to-site SA statuses with handshake timers and data transfer. |
| `widget_dhcp_leases` | **Active DHCP Leases** | Network | 1 Column | Dynamic IP allocations from Kea DHCP: client hostname, MAC address, allocated IP, lease expiration, and static reservation toggle. |
| `widget_dns_resolver` | **Unbound DNS Telemetry** | Services | 1 Column | Queries per second, cache hit ratio, recursion latency, and blocked malware/ad domains counter from subscribed feeds. |
| `widget_threat_intel` | **Threat Feeds & IDS Alerts** | Security | 2 Columns | Suricata alerts, blocked C2 IPs, AbuseIPDB/Spamhaus drop counters, and top targeted destination ports. |
| `widget_audit_log` | **Recent Audit Trail** | Security | 1 Column | Administrative logins, configuration commits, privilege changes, and CLI sessions with operator username and client IP. |
| `widget_revisions` | **Git Config Checkpoints** | System | 1 Column | Latest configuration commit, diff statistics (+/- lines), rollback trigger, and manual checkpoint snapshot button. |

---

## 3. Interactive Live Physical Interface Faceplate (FortiGate Style)

### 3.1 Hardware Visualization Architecture

A defining hallmark of enterprise security appliances (such as FortiGate, Palo Alto Networks, and Netgate) is the **interactive hardware faceplate** on the main dashboard. Instead of presenting network interfaces solely as rows in a generic database table, **Micron OS** renders a photorealistic, live-state representation of the appliance's physical chassis:

```
┌────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  MICRON OS ENTERPRISE SECURITY APPLIANCE — REAR/FRONT CHASSIS PORT TOPOLOGY                           │
├────────────────────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                                        │
│   ┌──────┐  ┌──────┐    ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐    ┌──────────┐ ┌──────────┐      │
│   │ PWR  │  │ STATUS│    │  WAN 1 │ │  WAN 2 │ │  LAN 1 │ │  LAN 2 │    │  SFP+ 1  │ │  SFP+ 2  │      │
│   │  🟢  │  │  🟢  │    │  eth0  │ │  eth1  │ │  eth2  │ │  eth3  │    │   sfp0   │ │   sfp1   │      │
│   │      │  │      │    ├────────┤ ├────────┤ ├────────┤ ├────────┤    ├──────────┤ ├──────────┤      │
│   │ LINK │  │ ALARM │    │ 🟢 LNK │ │ 🟢 LNK │ │ 🟢 LNK │ │ ⚪ LNK │    │  🔵 LNK  │ │  ⚪ LNK  │      │
│   │  🟢  │  │  ⚪  │    │ ⚡ ACT │ │ ⚡ ACT │ │ ⚡ ACT │ │ ⚪ ACT │    │  ⚡ ACT  │ │  ⚪ ACT  │      │
│   └──────┘  └──────┘    └────────┘ └────────┘ └────────┘ └────────┘    └──────────┘ └──────────┘      │
│                                                                                                        │
│  [Zone: WAN]             [Zone: WAN] [Zone: WAN] [Zone: LAN] [Zone: LAN]    [Zone: TRUNK] [Zone: DMZ]  │
│                                                                                                        │
└────────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Visual & Hardware Components

1. **Chassis Metallic Housing**:
   - Styled with a high-contrast brushed metallic texture, chamfered border edges, subtle cyber-cyan glowing trim, and stenciled port markings.
   - Status LED array: `PWR` (Power - solid green), `STATUS` (System health - green/amber), `ALARM` (Hardware warning/error - red), and `HA` (High Availability cluster - blue).
2. **RJ45 Jack Architecture**:
   - Styled RJ45 modular jack with authentic internal contact pins, bottom notch, and dual embedded surface-mount LEDs.
   - **Left LED (Link / Speed)**:
     - **Solid Cyan / Blue**: 10G / 2.5G Base-T link active.
     - **Solid Green**: 1000 Mbps (Gigabit) link active.
     - **Solid Amber**: 100 Mbps (Fast Ethernet) link active.
     - **Off / Dark**: No cable plugged in or carrier lost (`carrier = 0`).
   - **Right LED (Activity / Throughput Pulse)**:
     - Rapid flickering / blinking when packets are actively being transmitted or received. Blink frequency scales dynamically with packets per second (pps).
   - **Admin Down State**:
     - Ports administratively disabled in configuration display an overlaid subtle red slash badge or dimmed opacity.
3. **SFP / SFP+ Optical Cages**:
   - Visual optical transceiver cages with metallic latch bail handles for 10G/25G fiber uplinks, distinguishing copper ports from optical uplinks.
4. **Logical & Virtual Adapters Rail**:
   - Located directly beneath the physical faceplate, a secondary horizontal rail displays active virtual interfaces:
     - VLAN interfaces (e.g. `vlan10@eth2`, `vlan20@eth2`) with parent interface tags.
     - Link Aggregation groups (e.g. `bond0` [LACP: `eth2` + `eth3`]).
     - Encrypted VPN tunnels (e.g. `wg0`, `tun0`, `xfrm0`).

### 3.3 Real-Time Interactivity & Hover Telemetry (HUD)

Hovering over any port on the physical faceplate instantly invokes an animated, glassmorphic **Heads-Up Display (HUD)** tooltip pinned to the port:

```
┌─────────────────────────────────────────────────────────────┐
│ 🖧 PORT TELEMETRY: WAN 1 (eth0)                             │
├─────────────────────────────────────────────────────────────┤
│ • Link Status:     CONNECTED (Carrier Active)               │
│ • Negotiated Speed: 1000 Mbps Full Duplex (Auto-Negotiated) │
│ • Hardware Chipset: Intel Ethernet Controller I225-V        │
│ • MAC Address:      52:54:00:8a:bc:1e                       │
│ • IPv4 Address:     198.51.100.24 / 24 (Gateway: 198.51.100.1)│
│ • IPv6 Address:     2001:db8:85a3::1 / 64                   │
│ • MTU / Queue:      1500 / Multi-Queue (4 Rx/Tx Queues)     │
│ • Current Rx Rate:  42.8 Mbps (5,210 pps)                   │
│ • Current Tx Rate:  18.4 Mbps (3,120 pps)                   │
│ • Total Transferred:Rx: 48.2 GB  │  Tx: 16.9 GB             │
│ • Dropped / Errors: 0 dropped  │  0 CRC errors              │
│ • Assigned Zone:    WAN (Firewall Policy: 14 Rules Active)  │
└─────────────────────────────────────────────────────────────┘
```

### 3.4 Click-to-Inspect Port Drawer

Clicking a port opens an immediate sliding side drawer or modal with actionable diagnostics without reloading the page:
- **Port Quick Actions**:
  - `Toggle Link State`: Instantly bring interface administratively UP or DOWN via `micrond` IPC (`ip link set dev <iface> up/down`).
  - `Interface Settings`: Direct shortcut to `/interfaces/edit/{id}`.
  - `Firewall Rules`: Direct filtered view to `/firewall/rules?iface={name}`.
  - `Live Packet Capture`: One-click inline packet sniffer probe (`tcpdump -i <dev> -c 50 -nn`) streaming raw packet headers into an embedded terminal window.
  - `Cable Diagnostic Test`: Runs ethtool diagnostic self-test to measure link loopback and cable pair status.
- **Port Rolling Throughput Sparkline**: A dedicated 60-second live graph displaying micro-spikes and throughput curves specifically for the selected port.

### 3.5 Adaptive Hardware Topology Detection

The faceplate is not hardcoded to a static layout; it dynamically queries the kernel via `micrond` and renders the exact hardware profile of the host machine:
- **Virtual Machines (Hyper-V / KVM / Proxmox)**: Auto-renders a 2-port or 4-port virtual appliance faceplate with virtual NIC labels (`vEth0`, `vEth1`).
- **Compact Hardware Mini-PCs (4-Port Intel i225/i226)**: Renders a 4-port desktop appliance chassis with sequential port labeling (`WAN1`, `WAN2`, `LAN1`, `LAN2`).
- **Enterprise 1U/2U Rackmounts**: Renders an 8-port or 16-port chassis with 2 SFP+ optical ports and hardware LEDs.

---

## 4. System Architecture & Technical Specifications

### 4.1 Architectural Data Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             BROWSER FRONTEND (UI)                           │
│                                                                             │
│  ┌───────────────────────┐  ┌─────────────────────┐  ┌───────────────────┐  │
│  │ Chassis Faceplate     │  │ Modular Card Grid   │  │ Widget Catalog    │  │
│  │ (SVG + LED Blinker)   │  │ (Drag/Drop Handler) │  │ Drawer Modal      │  │
│  └───────────┬───────────┘  └──────────┬──────────┘  └─────────┬─────────┘  │
│              │                         │                       │            │
│              └─────────────────────────┼───────────────────────┘            │
│                                        │ Fetch / JSON-RPC Over HTTP         │
│                                        ▼                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                          MICRON OS CONTROL PLANE (PHP 8.4)                  │
│                                                                             │
│  ┌─────────────────────────┐              ┌──────────────────────────────┐  │
│  │ App\Controllers\Dashboard│ ───────────► │ App\Models\DashboardLayout   │  │
│  │  - telemetry()          │              │  - SQLite / MySQL Sync       │  │
│  │  - saveLayout()         │              │  - User Preferences JSON     │  │
│  │  - portAction()         │              └──────────────────────────────┘  │
│  └───────────┬─────────────┘                                                │
│              │ Unix Domain Socket (`/run/micron/micrond.sock`)              │
├──────────────┼──────────────────────────────────────────────────────────────┤
│              ▼                                                              │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │ THE IPC DAEMON (`micrond` - Python 3 / Native Linux)                  │  │
│  │  - rpc_interface_telemetry(): queries /sys/class/net, ethtool, stats  │  │
│  │  - rpc_system_gauges(): /proc/stat, /proc/meminfo, conntrack count    │  │
│  │  - rpc_interface_toggle(): ip link set dev <name> up/down            │  │
│  │  - rpc_live_filter_log(): nftables log buffer ring                    │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 4.2 Database Schema (`dashboard_user_layouts`)

To support multi-user environments with Role-Based Access Control (RBAC), each operator retains their own personalized dashboard layout.

```sql
-- Migration: CreateDashboardLayoutsTable
CREATE TABLE dashboard_user_layouts (
    id VARCHAR(36) PRIMARY KEY,
    user_id INT NOT NULL,
    profile_name VARCHAR(64) DEFAULT 'default',
    column_count INT DEFAULT 3,
    refresh_interval_ms INT DEFAULT 2000,
    layout_data TEXT NOT NULL, -- JSON structure of active cards, positions & card configs
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_dashboard_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_dashboard_user_profile ON dashboard_user_layouts(user_id, profile_name);
```

#### JSON Payload Structure for `layout_data`:
```json
{
  "profile": "Network_NOC",
  "columns": 3,
  "refresh_interval_ms": 2000,
  "widgets": [
    {
      "id": "widget_chassis",
      "column": 1,
      "span": 3,
      "order": 1,
      "collapsed": false,
      "settings": { "show_virtual_rail": true, "led_blink_rate": "realtime" }
    },
    {
      "id": "widget_gauges",
      "column": 1,
      "span": 1,
      "order": 2,
      "collapsed": false,
      "settings": { "temp_unit": "celsius" }
    },
    {
      "id": "widget_traffic",
      "column": 2,
      "span": 2,
      "order": 2,
      "collapsed": false,
      "settings": { "selected_iface": "eth0", "time_window": "5m" }
    },
    {
      "id": "widget_filter_log",
      "column": 1,
      "span": 2,
      "order": 3,
      "collapsed": false,
      "settings": { "row_limit": 10, "filter_action": "drop_only" }
    },
    {
      "id": "widget_gateways",
      "column": 3,
      "span": 1,
      "order": 3,
      "collapsed": false,
      "settings": { "ping_interval": "1s" }
    }
  ]
}
```

---

## 5. Frontend Implementation Architecture

### 5.1 Zero-Dependency Modular Card Engine (`dashboard-cards.js`)

The card grid utilizes native modern browser capabilities:
1. **Drag-and-Drop Handler**: Implemented using HTML5 Drag and Drop API with CSS Grid placeholders, guaranteeing zero bloat from heavy third-party JavaScript libraries.
2. **Smooth Micro-Animations**: CSS transitions for card entrance, removal, and drag-settling (`cubic-bezier(0.16, 1, 0.3, 1)`).
3. **Card Management Controls**:
   - `addCard(widgetId)`: Creates the card DOM element from an HTML `<template>` and registers its updater callback.
   - `removeCard(cardElement)`: Animates opacity and transform scale to zero, removes from DOM, and emits layout change event.
   - `saveLayout()`: Debounced POST to `/dashboard/layout/save` with serialized grid positions.
   - `resetLayout()`: Restores system default template with user confirmation.

### 5.2 Real-Time Chassis Faceplate Renderer (`chassis-faceplate.js`)

1. **SVG & CSS LED Engine**:
   - Vector-accurate RJ45 and SFP+ port SVG icons with dedicated LED elements (`#led_link_<id>`, `#led_act_<id>`).
   - LED blinking controlled via CSS hardware-accelerated animations toggled by packet rate thresholds (`pps > 10` $\rightarrow$ slow pulse; `pps > 500` $\rightarrow$ rapid flicker).
2. **HUD Tooltip Engine**:
   - Fixed-positioned floating element updating on `mouseenter` / `mousemove`, positioned dynamically with edge collision detection to prevent viewport overflow.
3. **Polling & Telemetry Bridge**:
   - Synchronized telemetry loop requesting `/dashboard/telemetry` every 2 seconds.
   - Uses `ETag` and `If-None-Match` HTTP headers. If network and resource counters remain unchanged, the server returns `304 Not Modified`, preserving appliance CPU cycles.

---

## 6. Implementation Roadmap & Phases

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                 EXECUTION TIMELINE                                     │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 1: Data Model & API Foundation                                                   │
│   • Database migration for dashboard_user_layouts table                                │
│   • Dashboard controller endpoints: /telemetry, /layout/save, /layout/reset, /port-action│
│   • micrond IPC methods for hardware port carrier & detailed ethtool stats             │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 2: Interactive Physical Chassis Faceplate Component                             │
│   • Vector RJ45/SFP+ chassis template with realistic metallic/LED styling              │
│   • Dynamic port auto-discovery and hardware mapping (eth0..ethN)                      │
│   • Real-time carrier status and activity blinking LED animation                       │
│   • Interactive hover telemetry HUD & click-to-inspect port drawer                     │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 3: Modular Card Grid & Drag/Drop Engine                                          │
│   • Modernized container grid with 1/2/3 column layout selectors                       │
│   • HTML5 drag-and-drop card reordering with smooth placeholder animations             │
│   • "+ Add Widget" catalogue modal and close/remove '✕' card actions                   │
│   • Asynchronous layout save to database and localStorage persistence                 │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 4: Full Widget Catalogue Implementation                                          │
│   • Convert existing static widgets into encapsulated, togglable cards                 │
│   • Implement individual card configuration modals (refresh rate, filters)             │
│   • Implement multi-tab dashboard profiles (Overview, NOC, SOC)                        │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ PHASE 5: Lab Verification & Cross-Platform Testing                                     │
│   • Unit and controller tests for layout persistence and RBAC permissions              │
│   • Hyper-V VM live verification with simulated carrier up/down and traffic load       │
│   • Physical hardware multi-NIC verification (Intel i225 / Realtek / SFP+)             │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Verification & Acceptance Criteria

Before declaring the UI/UX modernization complete, the following criteria must be satisfied:

1. **Card Customization Parity with pfSense / FortiGate**:
   - [ ] Clicking `+ Add Widget` displays all 14 catalogue cards with live status.
   - [ ] Adding an unselected card inserts it into the active grid immediately without a full page reload.
   - [ ] Clicking `✕` on any card removes it from the screen, recalculates the layout grid, and saves the new layout to the database.
   - [ ] Dragging a card by its handle cleanly moves it across columns and rows, maintaining its position upon page refresh.
   - [ ] Clicking "Reset Layout" reverts the dashboard to the factory default configuration.
2. **Interactive FortiGate-Class Physical Faceplate**:
   - [ ] Physical ports reflect live kernel link status: pulling a network cable or disabling an adapter in Hyper-V/Linux immediately transitions the corresponding LED from solid Green/Cyan to dark.
   - [ ] Active network traffic induces realistic Activity LED flickering proportional to packet rates.
   - [ ] Hovering over any port renders the glassmorphic HUD tooltip with accurate IP, MAC, Duplex, Rate (Mbps), and dropped packet counts within 50ms.
   - [ ] Clicking a port opens the Port Inspector drawer, allowing immediate administrative toggling (UP/DOWN) and displaying the 60-second throughput sparkline.
   - [ ] Faceplate dynamically adapts to available hardware (2-port VM vs 4-port appliance vs 8-port rackmount).
3. **Performance & Standards Compliance**:
   - [ ] Zero third-party heavyweight dependencies (100% vanilla JavaScript and CSS).
   - [ ] Telemetry refresh payload is under 15 KB and server response time is under 25ms.
   - [ ] Fully responsive on mobile (single column stack), laptop (2 columns), and desktop (3+ columns).
   - [ ] Strict project naming standard adhered to across all views and documents: **"Micron OS"** (with space), never "MicronOS".

---

*Document Version:* 1.0.0 (Engineering Specification)  
*Project:* Micron OS  
*Classification:* Management Plane & Web UI/UX Architecture

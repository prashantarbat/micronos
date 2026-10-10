# Micron Firewall OS (MicronOS) — Link Aggregation (IEEE 802.3ad LACP) & Wireless WLAN Guide

## 1. Executive Summary

Micron OS provides native enterprise-grade support for:
1. **Link Aggregation Groups (LAG) & IEEE 802.3ad LACP (`bond0`)**: Parallel bonding of multiple physical Gigabit / 10-Gigabit Ethernet interfaces for multiplied bandwidth throughput and automatic zero-packet-loss hardware failover.
2. **Wireless Local Area Networking (`wlan0`)**: Integrated Wi-Fi radio management supporting both high-performance Access Point (AP) broadcasting via `hostapd` with WPA2-Personal and next-generation WPA3-SAE (Simultaneous Authentication of Equals) encryption, as well as wireless Station (Client) uplink mode via `wpa_supplicant`.

Both subsystems are fully configurable through the Micron OS Web Management Plane (`/interfaces`), stored in the persistent database, dynamically managed through the `micrond` JSON-RPC daemon, and integrated with the appliance's packet filtering engine (`nftables`).

---

## 2. Link Aggregation & Bonding Architecture (`bond0`)

### 2.1 Bonding Modes Supported
Micron OS implements the Linux kernel bonding driver with full hardware and software offloading:

| Mode | Identifier | Description | Recommended Deployment |
| :--- | :--- | :--- | :--- |
| **IEEE 802.3ad Dynamic LACP** | `802.3ad` | Dynamic link aggregation using IEEE 802.3ad protocol. Multiplies total throughput and provides sub-second failover. | Managed Core / Distribution Switches supporting LACP trunks |
| **Active-Backup** | `active-backup` | Only one slave is active. Another becomes active if and only if the primary fails. | Redundant switch uplinks where switches do not share MLAG/vPC |
| **Balance-XOR** | `balance-xor` | Transmits based on the selected transmit hash policy (Layer 2, 2+3, or 3+4). | Static link aggregation trunks without LACP signaling |
| **Balance Round-Robin** | `balance-rr` | Transmits packets sequentially from the first available slave to the last. | Direct back-to-back links between identical servers |
| **Broadcast** | `broadcast` | Transmits everything on all slave interfaces. | Special-purpose ultra-high fault tolerance |

### 2.2 Transmit Hash Policies & Parameters
- **LACP Heartbeat Rate (`lacp_rate`)**:
  - `fast` (1 second): Fast periodic heartbeats for rapid failover detection.
  - `slow` (30 seconds): Standard periodic heartbeats for reduced management overhead.
- **Transmit Hash Policy (`xmit_hash_policy`)**:
  - `layer2+3` (Recommended): Uses a hash of MAC addresses and IP addresses to distribute flows across member links.
  - `layer3+4`: Uses IP protocol numbers and TCP/UDP ports for granular distribution across high-concurrency flows.
  - `layer2`: Uses source and destination MAC addresses only.
- **MII Link Monitoring (`miimon`)**: Configured by default to 100 milliseconds, polling carrier states via MII.

### 2.3 802.1Q VLAN Multiplexing on Bonds
Any configured bond interface (e.g., `bond0`) automatically becomes available in the Micron OS interface picker as a parent device for 802.1Q VLANs. For example, creating a VLAN with tag `100` on parent `bond0` provisions virtual sub-interface `bond0.100`, allowing aggregated multi-gigabit trunking directly to downstream departmental VLANs.

---

## 3. Wireless LAN Architecture (`wlan0`)

### 3.1 Operating Modes
Micron OS configures wireless network cards in two operational roles:

1. **Access Point (AP Mode)**:
   - Daemon: `hostapd`
   - Configuration: `/etc/hostapd/hostapd-<interface>.conf`
   - Capabilities: Broadcasts SSIDs, handles IEEE 802.11 auth/association, enforces client isolation, and forwards station traffic to the Micron OS bridge or routing stack.
2. **Station / Client Mode**:
   - Daemon: `wpa_supplicant`
   - Configuration: `/etc/wpa_supplicant/wpa_supplicant-<interface>.conf`
   - Capabilities: Connects to upstream external Wi-Fi networks as a WAN or failover uplink.

### 3.2 Security Standards Supported
- **WPA3-Personal (`SAE`)**: Simultaneous Authentication of Equals with Protected Management Frames (PMF `ieee80211w=2`) for resistance against offline dictionary attacks.
- **WPA2 / WPA3 Transition Mode**: Mixed mode allowing legacy WPA2 devices and modern WPA3 devices to connect to the same SSID simultaneously.
- **WPA2-Personal (`AES-CCMP`)**: Standard AES encryption for universal device compatibility.
- **Open Network**: No security for public captive portal zones.

### 3.3 Advanced RF Options
- **Hardware Modes**: `802.11ax` (Wi-Fi 6), `802.11ac` (Wi-Fi 5 / 5 GHz), and `802.11g/n` (2.4 GHz).
- **Client Isolation**: Blocks direct wireless client-to-client peer communication, enforcing zero-trust segmentation on guest and corporate networks.
- **Transmit Power**: Configurable from 1 to 30 dBm (100 mW).

---

## 4. Database Schema

### 4.1 Table: `interface_bonds`
```sql
CREATE TABLE interface_bonds (
    id VARCHAR(32) PRIMARY KEY,               -- e.g. 'bond0'
    name VARCHAR(128) NOT NULL,               -- e.g. 'Core Switch LACP Trunk'
    mode VARCHAR(32) DEFAULT '802.3ad',       -- 802.3ad, active-backup, balance-xor
    slaves TEXT NOT NULL,                     -- JSON array of member NICs: ["eth1", "eth2"]
    lacp_rate VARCHAR(16) DEFAULT 'fast',     -- fast, slow
    xmit_hash_policy VARCHAR(32) DEFAULT 'layer2+3',
    miimon INT DEFAULT 100,
    updelay INT DEFAULT 0,
    downdelay INT DEFAULT 0,
    mtu INT DEFAULT 1500,
    created_at DATETIME,
    updated_at DATETIME
);
```

### 4.2 Table: `wireless_interfaces`
```sql
CREATE TABLE wireless_interfaces (
    id VARCHAR(32) PRIMARY KEY,               -- e.g. 'wlan0'
    interface VARCHAR(32) NOT NULL,
    name VARCHAR(128) NOT NULL,
    mode VARCHAR(16) DEFAULT 'ap',            -- 'ap' or 'client'
    ssid VARCHAR(128) NOT NULL,
    passphrase VARCHAR(128),
    security VARCHAR(32) DEFAULT 'wpa2',      -- wpa2, wpa3, wpa2_wpa3, open
    hw_mode VARCHAR(8) DEFAULT 'g',           -- g, a, ax
    channel INT DEFAULT 6,
    country_code VARCHAR(4) DEFAULT 'US',
    hidden_ssid TINYINT(1) DEFAULT 0,
    client_isolation TINYINT(1) DEFAULT 0,
    tx_power INT DEFAULT 20,
    enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME,
    updated_at DATETIME
);
```

---

## 5. IPC Daemon Integration (`micrond`)

| JSON-RPC Method | Request Parameters | Description |
| :--- | :--- | :--- |
| `bond.list` | `{}` | Lists all active kernel bonding interfaces, parsing `/proc/net/bonding/*`. |
| `bond.apply` | `{id, mode, slaves, lacp_rate, xmit_hash_policy, miimon, mtu}` | Loads kernel bonding driver (`modprobe bonding`), configures bond device, binds slave ports, and sets MTU and UP state. |
| `bond.delete` | `{id}` | Releases slave member interfaces and removes the bonding device. |
| `wireless.status` | `{interface}` | Queries live RF state via `iw dev <iface> info` and connected stations via `hostapd_cli all_sta`. |
| `wireless.apply` | `{interface, mode, ssid, passphrase, security, hw_mode, channel, ...}` | Atomically writes configuration files (`/etc/hostapd/hostapd-<iface>.conf` or `/etc/wpa_supplicant/...`) and restarts host daemons. |

---

## 6. Verification & Automated Testing

The implementation is verified by comprehensive unit and integration test suites:
- **PHPUnit Feature Tests**: `tests/app/Controllers/BondAndWirelessTest.php` (5 tests, 36 assertions verifying database persistence, CSRF security, permission filtering, and registration in the primary interface routing table).
- **Python IPC Daemon Harness**: `tests/test_micrond_bonds_wireless.py` (verifying exact command line invocations for `modprobe bonding`, `ip link add bond0 type bond mode 802.3ad`, member enslavement, `hostapd` configuration compilation, and station telemetry queries).

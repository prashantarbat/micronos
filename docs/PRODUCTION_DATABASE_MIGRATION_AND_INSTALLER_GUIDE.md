# Micron OS: Production Database Migrations, Seeders & Universal Installer Guide

> **Document Type:** Production Architecture, Release Engineering & DevSecOps Reference  
> **Target OS:** Debian 13 (Trixie) Linux  
> **Appliance Framework:** CodeIgniter 4 (PHP 8.3 / 8.4)  
> **Branding Specification:** **Micron OS** / **MICRON OS** (Strictly spaced; never unspaced)  
> **Distribution License:** Apache License 2.0  

---

## 1. Executive Summary & Security Directives

### 1.1 The Blank Database Security Mandate
In production network security appliances, shipping default mock data, testing subnets, or confidential development routes introduces severe operational and security liabilities. 

When deploying a firewall via live ISO media or automated bare-metal PXE kickstart:
1. **Zero Leaked Policy Artifacts:** The database must never contain development routing records, hardcoded internal bank prefix-lists (such as former development test subnets), or pre-configured third-party gateways.
2. **Deterministic Schema Construction:** Database structures are established exclusively through version-controlled CodeIgniter 4 migrations (`app/Database/Migrations/`).
3. **Pristine Base Seeding:** Only the essential appliance operational schema is seeded at installation time (`InitialApplianceSeeder` and `RolesPermissionsSeeder`).
4. **Appliance Mode Isolation:** Features querying live system state (such as FRR routing peers, interface metrics, and kernel neighbors) default to empty states `[]` until real daemons and IPC services report live telemetry.

---

## 2. Root Cause Elimination: The Routing Auto-Seeding Fix

### 2.1 Analysis of the Security Threat
During early UI development of the **Dynamic Routing & Multi-WAN** (`/routing`) module, `app/Controllers/Routing.php` included runtime auto-seeding blocks that executed whenever a user visited `http://<appliance-ip>/routing?tab=ospf&sub=policy`.

If the tables were empty, `Routing.php` automatically inserted:
- Prefix-lists containing confidential development banking names and test IPs (`10.11.83.0/24`, `10.29.3.51/32`, `10.0.0.0/8`).
- Route-maps referencing those prefix-lists.
- Static routes pointing to fictitious branch subnets.
- Fictitious Multi-WAN gateways (`WAN1_Fiber_Primary`, `WAN2_Broadband_Backup`).
- Hardcoded mock OSPF neighbor rows (`10.10.10.10`, `9.9.9.9`).

### 2.2 Permanent Architectural Remediation
1. **Complete Removal of Controller Auto-Seeding:** All `insertBatch()` and `$db->table(...)->insert()` calls were permanently purged from `app/Controllers/Routing.php`. Controllers in **Micron OS** are strictly read/write handlers for user-initiated transactions; they never self-seed configuration data.
2. **Conditional Dev Mocking:** Telemetry attributes (gateway latency, packet loss, live OSPF neighbors) are strictly isolated behind `$this->ipc->isMock()`. On live hardware or virtual machines running the **Micron OS** daemon (`micrond`), telemetry displays live operational state or clean empty placeholders.
3. **Sanitized UI Templates:** All placeholder values in modal forms (`app/Views/routing/ospf_panel.php`, `app/Views/webfilter/index.php`) have been replaced with neutral, standard RFC 5737 / RFC 1918 examples (e.g., `PL_INTERNAL_NETWORKS`, `192.168.1.0/24`).
4. **Unconditional Purge Migration (Migration 122):** A dedicated migration (`2026-10-16-000122_PurgeAllSampleAndRoutingData.php`) runs unconditionally across all environments to delete any historical sample records from existing databases.

---

## 3. Database Migration Architecture

The **Micron OS** persistence model is maintained across 122 sequential migration scripts located in `app/Database/Migrations/`.

```
app/Database/Migrations/
├── 2026-10-02-000001_CreateInterfacesTable.php
├── 2026-10-02-000002_CreateFirewallRulesTable.php
├── ...
├── 2026-10-04-000078_CreateFrrPolicyTables.php           (Prefix-lists & Route-maps)
├── 2026-10-04-000079_CreateOspfFilterTables.php          (Distribute-lists & Area filters)
├── ...
├── 2026-10-14-000119_RemoveSampleDataFromAppliance.php   (Sample data cleanup on appliance)
├── 2026-10-14-000120_NetworkDefaultsAndTransactions.php  (Atomic network changes)
├── 2026-10-15-000001_UpdateWireguardAggregateService.php (WireGuard multi-peer aggregation)
├── 2026-10-15-000002_AddInterfaceAdministrativeAccess.php(HTTP/HTTPS/SSH/Ping toggles)
└── 2026-10-16-000122_PurgeAllSampleAndRoutingData.php    (Universal Routing Purge)
```

### 3.1 Migration Execution Standard
In **Micron OS**, migrations are executed via the CodeIgniter 4 CLI tool `spark`:

```bash
cd /usr/local/micron
export MICRON_APPLIANCE=1
php spark migrate --all
```

---

## 4. Seeder Inventory & Factory Defaults

### 4.1 Production Seeders Run by Installer

| Seeder Name | Purpose | Production Status |
| :--- | :--- | :--- |
| `InitialApplianceSeeder` | Creates the root administrative user (`Ministrator`), sets initial WAN (DHCP) and LAN (`10.10.10.10/24`), sets 3 base egress pass rules, and configures baseline Squid web filter categories. | **Active / Required** |
| `RolesPermissionsSeeder` | Populates the comprehensive RBAC matrix: roles (`SuperAdmin`, `Admin`, `Operator`, `Auditor`) and granular permission nodes. | **Active / Required** |

### 4.2 Non-Production Seeders (Excluded from Installer)

| Seeder Name | Purpose | Exclusion Reason |
| :--- | :--- | :--- |
| `FirewallObjectsSeeder` | Pre-populates mock addresses, address-groups, and service ports. | **Excluded:** Production appliances must start with a clean network object inventory tailored to the customer's topology. |

---

## 5. Universal Installer Execution Lifecycle

During bare-metal or virtual installation via `installer/install.sh`, disk partitioning provisions an active/standby redundant A/B slot layout (`MICRON_ROOT_A` and `MICRON_ROOT_B`).

```
                    Bare-Metal / VM Boot (ISO Media)
                                  │
                                  ▼
               Dual-Root Partitioning (ROOT_A & ROOT_B)
                                  │
                                  ▼
                Filesystem Extraction & Rsync Sync
                                  │
                                  ▼
                Chroot Database Configuration Target
  ┌───────────────────────────────────────────────────────────────┐
  │  chroot "$TARGET_MNT" bash -c:                                │
  │    1. export MICRON_APPLIANCE=1                               │
  │    2. php spark migrate --all                                 │
  │    3. php spark db:seed InitialApplianceSeeder                │
  │    4. php spark db:seed RolesPermissionsSeeder                │
  │    5. php spark user:reset-admin "${ADMIN_PW}"                │
  │    6. php spark micron:db-resilience --snapshot               │
  └───────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
               Replicate SQLite DB to Standby Slot B
                                  │
                                  ▼
                Systemd Shutdown Timeout Hardening
                                  │
                                  ▼
                   Reboot Into Clean Active Slot A
```

---

## 6. Systemd Shutdown & Reboot Optimization

### 6.1 Issue Identification: The 90s `networking.service` Stall
On hypervisors (such as Microsoft Hyper-V) and certain physical NICs, issuing `reboot` or `shutdown -h now` caused Debian's `networking.service` (`ifdown -a`) to hang for up to 90 seconds while awaiting DHCP lease releases on unconfigured or disconnected virtual interfaces.

### 6.2 Hardened Drop-In Configuration
Both the live build configuration (`packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot`) and the universal installer (`installer/install.sh`) now provision systemd drop-ins into `/etc/systemd/`:

#### 1. Per-Service Cap: `/etc/systemd/system/networking.service.d/timeout.conf`
```ini
[Service]
TimeoutStopSec=5s
```

#### 2. Global Daemon Cap: `/etc/systemd/system.conf.d/timeout.conf`
```ini
[Manager]
DefaultTimeoutStopSec=10s
```

This guarantees that an appliance reboots or halts in under 10 seconds under all power loss and maintenance conditions.

---

## 7. Verification & Integrity Checklist

To verify the cleanliness and operational integrity of **Micron OS** before ISO release, execute the automated test suites:

```bash
# 1. Verify routing pages and policy tests with pristine database
php vendor/bin/phpunit --no-coverage tests/app/Controllers/RoutingPagesTest.php
php vendor/bin/phpunit --no-coverage tests/app/Controllers/OspfPolicyTest.php

# 2. Verify web filter compilation and sanitization
php vendor/bin/phpunit --no-coverage tests/app/Controllers/WebFilterComprehensiveTest.php

# 3. Verify universal installer and boot deconfliction assertions (43 assertions)
bash tests/installer/web_and_boot_deconflict_test.sh

# 4. Verify live database table contents are clean
php spark db:table frr_prefix_lists
php spark db:table frr_route_maps
php spark db:table wan_gateways
```

Expected Output:
```
Data of Table "frr_prefix_lists": (0 rows)
Data of Table "frr_route_maps":   (0 rows)
Data of Table "wan_gateways":      (0 rows)
PASS: 43 assertions, 0 failures
```

---
*Document maintained by the Micron OS Core Engineering Team.*

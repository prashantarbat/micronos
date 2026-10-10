# Micron Firewall OS (MicronOS)
## Hyper-V & Windows 11 Pro AI-Assisted Development & Emulation Guide
### Complete Reference for Local Appliance Virtualization, Live Code Hot-Syncing, and AI-Coordinated GUI/OS Modifications

---

## Table of Contents
1. [Architectural Overview & Development Paradigm](#1-architectural-overview--development-paradigm)
2. [Host Environment Prerequisites on Windows 11 Pro](#2-host-environment-prerequisites-on-windows-11-pro)
3. [Hyper-V Virtual Switch & Dual-NIC Network Topology](#3-hyper-v-virtual-switch--dual-nic-network-topology)
4. [Generation 2 Virtual Machine Provisioning & Firmware Settings](#4-generation-2-virtual-machine-provisioning--firmware-settings)
5. [Installing MicronOS via the Shipped Installer (`installer/install.sh`)](#5-installing-micronos-via-the-shipped-installer-installerinstallsh)
6. [Three-Tier Fast-Feedback Development Workflow with AI](#6-three-tier-fast-feedback-development-workflow-with-ai)
7. [Live GUI Hot-Syncing (Apache2, PHP 8.3 FPM, Views & Controllers)](#7-live-gui-hot-syncing-apache2-php-83-fpm-views--controllers)
8. [Live OS & Data Plane Hot-Syncing (`micrond`, nftables, Services)](#8-live-os--data-plane-hot-syncing-micrond-nftables-services)
9. [Hyper-V Native Coordination (PowerShell Direct & VMBus `Copy-VMFile`)](#9-hyper-v-native-coordination-powershell-direct--vmbus-copy-vmfile)
10. [Automated End-to-End GUI Testing with Antigravity Browser Agent](#10-automated-end-to-end-gui-testing-with-antigravity-browser-agent)
11. [Automated Developer Helper Scripts (`tools/sync-vm.ps1`)](#11-automated-developer-helper-scripts-toolssync-vmps1)
12. [Final Release Cycle: Appliance Overlay Staging & Golden ISO Compilation](#12-final-release-cycle-appliance-overlay-staging--golden-iso-compilation)
13. [Dual Documentation Registry Agreement (`build_docs.php` & `Docs.php`)](#13-dual-documentation-registry-agreement-build_docsphp--docsphp)
14. [Troubleshooting & Windows 11 Specific Considerations](#14-troubleshooting--windows-11-specific-considerations)

---

## 1. Architectural Overview & Development Paradigm

When developing an enterprise firewall and network appliance operating system like **MicronOS**, compiling a full 2 GB hybrid installation ISO (`micronos-2.0.0-amd64.hybrid.iso`) and reinstalling it inside a virtual machine on every single CSS change, controller tweak, or daemon bugfix is completely impractical. A single build-and-install cycle can take 5 to 15 minutes.

Instead, the engineering workflow adopts a **split-plane host/guest development paradigm**:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                             WINDOWS 11 PRO (HOST MACHINE)                        │
│                                                                                  │
│   [Antigravity AI Agent & IDE]                                                   │
│   ├── Code authoring, refactoring, and AST knowledge graph navigation             │
│   ├── Automated unit test execution (CodeIgniter Spark / PHPUnit)               │
│   ├── Automated browser validation & recording (browser_subagent)                │
│   └── SSH / SCP Hot-Sync orchestration to the VM                                │
│                                                                                  │
│   [Source Repository (Source of Truth)]                                          │
│   d:\laragon\www\micronos                                                        │
│   ├── app/                (CodeIgniter 4 Controllers, Models, Views)             │
│   ├── public/             (Apache Web Root, static assets, css, js)              │
│   ├── system/micrond/     (Python JSON-RPC Root Control Daemon)                  │
│   └── installer/          (A/B partition scripts, GRUB slots, healthchecks)      │
└──────────────────────────────┬───────────────────────────────────────────────────┘
                               │
            ┌──────────────────┴──────────────────┐
            │ Communication & Coordination Links: │
            │ • SSH / SCP (192.168.1.1:22)        │
            │ • HTTPS Web GUI (192.168.1.1:443)   │
            │ • Hyper-V PowerShell Direct (VMBus) │
            └──────────────────┬──────────────────┘
                               ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                       HYPER-V VIRTUAL MACHINE (GUEST APPLIANCE)                  │
│                                                                                  │
│   [MicronOS Linux Appliance (Debian 13 Trixie x86_64)]                           │
│   ├── Web Management Plane:    /usr/local/micron (Apache2 + PHP 8.4 FPM)         │
│   ├── System Control Daemon:   /usr/local/bin/micrond (systemd unit: micrond)    │
│   ├── Linux Data Plane:        nftables, kea-dhcp4, squid, unbound, frr, suricata│
│   ├── Network Interface WAN:   eth0 connected to "MicronOS-WAN" (External/NAT)   │
│   └── Network Interface LAN:   eth1 connected to "MicronOS-LAN" (192.168.1.1/24) │
└──────────────────────────────────────────────────────────────────────────────────┘
```

This model provides:
1. **Near-Zero Iteration Latency:** Edits to views, controllers, or daemon methods update on the live running appliance in under 2 seconds.
2. **Deterministic Reproducibility:** The repository remains the single source of truth. Once changes are verified, the overlay staging tool (`packaging/live-build/stage-overlay.sh`) packages them identically into the production ISO.
3. **No External Hardware Required:** Developers run the full software-defined networking stack on their Windows 11 Pro laptop or desktop without needing physical hypervisors or dedicated Proxmox VE servers.

---

## 2. Host Environment Prerequisites on Windows 11 Pro

Windows 11 Pro includes Microsoft's enterprise Type-1 hypervisor (**Hyper-V**) pre-packaged with the operating system.

### 2.1 Hardware Virtualization Verification
Before enabling Hyper-V, ensure hardware virtualization (Intel VT-x or AMD-V) and SLAT (Second Level Address Translation) are enabled in your motherboard firmware:

1. Open **Task Manager** (`Ctrl + Shift + Esc`).
2. Navigate to **Performance** $\rightarrow$ **CPU**.
3. Verify that **Virtualization:** displays as **Enabled**.

### 2.2 Enabling Hyper-V via Elevated PowerShell
Open PowerShell as an Administrator and execute:

```powershell
# Enable the Hyper-V hypervisor, management tools, and PowerShell module
Enable-WindowsOptionalFeature -Online -FeatureName Microsoft-Hyper-V -All -NoRestart

# Enable Windows Hypervisor Platform (ensures compatibility with WSL2 and other virtualization layers)
Enable-WindowsOptionalFeature -Online -FeatureName HypervisorPlatform -All -NoRestart
```

> [!NOTE]
> If prompted, restart your computer to finalize the installation of the Hyper-V kernel drivers.

---

## 3. Hyper-V Virtual Switch & Dual-NIC Network Topology

A network security appliance requires at least two network segments:
1. **WAN (Untrusted / Uplink):** Connects to the upstream network (your physical home or office LAN/Router) to provide internet access to the appliance via DHCP.
2. **LAN (Trusted / Management):** Connects the appliance's management interface (`192.168.1.1`) to an isolated internal network shared with your Windows 11 host.

```
                  ┌──────────────────────────────────────────────┐
                  │          Physical Network / Internet         │
                  └──────────────────────┬───────────────────────┘
                                         │
                         [Physical Wi-Fi or Ethernet NIC]
                                         │
                    ┌────────────────────▼────────────────────┐
                    │ Hyper-V External Switch: "MicronOS-WAN" │
                    └────────────────────┬────────────────────┘
                                         │ (Bridged / Uplink)
                                         ▼
                             ┌───────────────────────┐
                             │  MicronOS WAN (eth0)  │
                             │  IP: DHCP (Upstream)  │
                             ├───────────────────────┤
                             │       MicronOS        │
                             │   Firewall Core Engine│
                             ├───────────────────────┤
                             │  MicronOS LAN (eth1)  │
                             │  IP: 192.168.1.1/24   │
                             └───────────┬───────────┘
                                         │ (Isolated LAN)
                    ┌────────────────────▼────────────────────┐
                    │ Hyper-V Internal Switch: "MicronOS-LAN" │
                    └────────────────────┬────────────────────┘
                                         │
                        [Virtual Ethernet Adapter on Windows 11]
                           IP: 192.168.1.100/24 (No Gateway)
```

### 3.1 Creating the Virtual Switches via PowerShell
Run the following script in Administrator PowerShell to provision both switches cleanly:

```powershell
# 1. Detect your active physical network adapter (Wi-Fi or Ethernet)
$physicalNic = Get-NetAdapter | Where-Object { $_.Status -eq "Up" -and $_.Virtual -ne $true } | Select-Object -First 1

if (-not $physicalNic) {
    Write-Error "No active physical network adapter detected! Please connect to a network."
    exit 1
}

Write-Host "Binding WAN to physical adapter: $($physicalNic.Name) ($($physicalNic.InterfaceDescription))" -ForegroundColor Cyan

# 2. Create the External WAN Virtual Switch
if (-not (Get-VMSwitch -Name "MicronOS-WAN" -ErrorAction SilentlyContinue)) {
    New-VMSwitch -Name "MicronOS-WAN" -NetAdapterName $physicalNic.Name -AllowManagementOS $true
    Write-Host "Created External Switch: MicronOS-WAN" -ForegroundColor Green
} else {
    Write-Host "Switch MicronOS-WAN already exists." -ForegroundColor Yellow
}

# 3. Create the Internal LAN Virtual Switch
if (-not (Get-VMSwitch -Name "MicronOS-LAN" -ErrorAction SilentlyContinue)) {
    New-VMSwitch -Name "MicronOS-LAN" -SwitchType Internal
    Write-Host "Created Internal Switch: MicronOS-LAN" -ForegroundColor Green
} else {
    Write-Host "Switch MicronOS-LAN already exists." -ForegroundColor Yellow
}
```

### 3.2 Configuring the Windows 11 Host LAN IP
When Hyper-V creates an Internal Switch, Windows creates a corresponding virtual network adapter named `vEthernet (MicronOS-LAN)`. 

Assign this host interface a static IP on the same subnet (`192.168.1.100/24`) **without a Default Gateway**, ensuring your host's internet routing is not hijacked:

```powershell
# Configure host adapter on the management network
$hostLanNic = Get-NetAdapter -Name "vEthernet (MicronOS-LAN)"
New-NetIPAddress -InterfaceIndex $hostLanNic.InterfaceIndex -IPAddress "192.168.1.100" -PrefixLength 24
Write-Host "Windows Host is now configured at 192.168.1.100 on MicronOS-LAN." -ForegroundColor Green
```

---

## 4. Generation 2 Virtual Machine Provisioning & Firmware Settings

MicronOS boots exclusively via modern UEFI (`x86_64-efi`) and GRUB2. In Hyper-V, you must use a **Generation 2** virtual machine.

### 4.1 Sizing Calculations & Disk Preflight
The real installer (`installer/install.sh`) performs an automated preflight check on disk capacity:
- Partition 1: `ESP` (512 MiB)
- Partition 2: `Swap` (4,096 MiB / 4 GiB)
- Partition 3: `Root Slot A` (10,240 MiB / 10 GiB)
- Partition 4: `Root Slot B` (10,240 MiB / 10 GiB)
- Partition 5: `Data Partition` (minimum 2,048 MiB)
- **Minimum Disk Required:** $512 + 4096 + 2 \times 10240 + 2048 = 27,136\text{ MiB}$ (~26.5 GiB).

A **32 GB** VHDX virtual disk comfortably satisfies this requirement.

### 4.2 Automated VM Creation Script
Run this script to provision the virtual machine with optimal sizing for firewall workloads:

```powershell
$vmName   = "MicronOS-Dev"
$vmPath   = "C:\Hyper-V\Virtual Machines"
$vhdPath  = "C:\Hyper-V\Virtual Hard Disks\MicronOS-Dev.vhdx"
$isoPath  = "D:\laragon\www\micronos\micronos-2.0.0-amd64.hybrid.iso" # Update path if stored elsewhere

# Ensure destination folders exist
New-Item -ItemType Directory -Force -Path "C:\Hyper-V\Virtual Machines", "C:\Hyper-V\Virtual Hard Disks" | Out-Null

# 1. Create Generation 2 VM
New-VM -Name $vmName `
       -Generation 2 `
       -MemoryStartupBytes 4GB `
       -SwitchName "MicronOS-WAN" `
       -NewVHDPath $vhdPath `
       -NewVHDSizeBytes 32GB `
       -Path $vmPath

# 2. Optimize CPU (4 vCPUs recommended for multi-core packet filtering & Suricata)
Set-VMProcessor -VMName $vmName -Count 4

# 3. Disable Dynamic Memory (Crucial for router/firewall appliances to prevent memory oscillation)
Set-VMMemory -VMName $vmName -DynamicMemoryEnabled $false

# 4. Add the second Network Adapter (LAN)
Add-VMNetworkAdapter -VMName $vmName -SwitchName "MicronOS-LAN" -Name "LAN"

# 5. Configure UEFI Firmware & Disable Secure Boot
# MicronOS uses a custom Linux kernel and GRUB binary. Secure Boot must be disabled
# or set to the Microsoft UEFI Certificate Authority template.
Set-VMFirmware -VMName $vmName -EnableSecureBoot $false

# 6. Add DVD Drive and Mount the Hybrid ISO
Add-VMDvdDrive -VMName $vmName -Path $isoPath

# 7. Set Boot Order to boot DVD first
$dvd = Get-VMDvdDrive -VMName $vmName
Set-VMFirmware -VMName $vmName -FirstBootDevice $dvd

Write-Host "Virtual Machine $vmName provisioned successfully!" -ForegroundColor Green
```

---

## 5. Installing MicronOS via the Shipped Installer (`installer/install.sh`)

1. Launch **Hyper-V Manager** (`virtmgmt.msc`).
2. Right-click `MicronOS-Dev` $\rightarrow$ **Connect...**, then click **Start**.
3. The VM will boot from the mounted ISO into the live installer environment.
4. The authoritative installer is **`installer/install.sh`** (driven by **`installer/tui.py`**):
   - Select the target disk (typically `/dev/sda`).
   - The installer creates the **five-partition GPT layout**:
     - `sda1` (512M) $\rightarrow$ `EFI System Partition` (`/boot/efi`)
     - `sda2` (4G) $\rightarrow$ `MicronOS Swap` (Swap)
     - `sda3` (10G) $\rightarrow$ `MicronOS Root Slot A` (`/` - Active Slot)
     - `sda4` (10G) $\rightarrow$ `MicronOS Root Slot B` (Standby upgrade slot, `noauto`)
     - `sda5` (Remainder) $\rightarrow$ `MicronOS Persistent Data` (`/var/lib/micron`)
   - Formats partitions with explicit labels (`MICRON_ROOT_A`, `MICRON_ROOT_B`, `MICRON_DATA`).
   - Syncs the root image, writes `/etc/fstab`, initializes GRUB slot selection (`grub.d/09_micron_slots`), and installs `micron-healthcheck.service`.
   - **Interactive Network Prompts:** Prompts for:
     - WAN interface: `eth0`
     - LAN interface: `eth1`
     - LAN IP: `192.168.1.1`
     - Writes `/etc/micron/network_init.json`.
5. Upon reboot, the appliance runs **Apache2** and **PHP 8.4 FPM**.
6. **Verified Operational Credentials (Unified Web & CLI Stack)**:
   - **Web GUI URL:** `http://<Appliance-IP>/` (dynamically bound to incoming IP; no localhost redirect)
   - **Web GUI Username:** `Ministrator` *(or `ministrator` — case-insensitive)*
   - **Web GUI Password:** `M1Cr0N` (Full SuperAdmin, all 109 permissions)
   - **SSH Console:** `ministrator` / `M1Cr0N` on port 22 (full `sudo` via `/etc/sudoers.d/99-ministrator`, `NOPASSWD: ALL`)
   - **Direct Console:** `/dev/tty1` (Hyper-V Screen) with automated autologin as `ministrator` (`sudo -i` for root)
   - **Native Root:** `root` / `M1Cr0N` (unified password matching Web and CLI)

---

## 6. Three-Tier Fast-Feedback Development Workflow with AI

When working on modifications with your AI coding partner (Antigravity), use the three-tier workflow to maximize speed and eliminate wasted compile time:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        THREE-TIER FEEDBACK LOOP                        │
├────────────────────────────────────────────────────────────────────────┤
│ TIER 1: Host Local Validation (Milliseconds)                          │
│ • Unit tests & Mocked IPC tests on Windows (php spark test)           │
│ • Syntax checking, AST dependency analysis (graphify query)           │
│ • Validates PHP templates, database schemas, controller logic         │
├────────────────────────────────────────────────────────────────────────┤
│ TIER 2: Live Appliance Hot-Sync (1 to 2 Seconds)                      │
│ • Code push via SSH/SCP into /usr/local/micron                        │
│ • Hot-restart micrond service (systemctl restart micrond)             │
│ • Hot-reload web server (systemctl reload apache2 php8.4-fpm)         │
│ • Immediate browser verification at http://<Appliance-IP>/            │
├────────────────────────────────────────────────────────────────────────┤
│ TIER 3: Golden ISO Release & Acceptance (Periodic / CI)                │
│ • Overlay tree verification via stage-overlay.sh                      │
│ • Full hybrid ISO compilation in Docker / WSL2                        │
│ • Clean-slate boot verification in fresh VM                           │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Live GUI Hot-Syncing (Apache2, PHP 8.4 FPM, Views & Controllers)

The appliance runs CodeIgniter 4 out of `/usr/local/micron` using **Apache2 with `mod_proxy_fcgi`**:
- Apache VHost: `/etc/apache2/sites-available/micronos.conf` (DocumentRoot: `/usr/local/micron/public`)
- PHP FastCGI Socket: `/run/php/php8.4-fpm.sock`
- Web Application Code: `/usr/local/micron/app/`
- Offline Assets & CSS: `/usr/local/micron/public/assets/`, `/usr/local/micron/public/css/`

PHP is an interpreted language executed via PHP-FPM. Therefore, **modifications to PHP controllers, models, and views take effect the instant the file is replaced on the disk**. No reboot is required.

### 7.1 Dynamic Host & BaseURL Handling
In `app/Config/App.php`, `$this->baseURL` and `$this->allowedHostnames` dynamically resolve to `$_SERVER['HTTP_HOST']` during runtime. Combined with root exemption in `app/Config/Filters.php`, accessing the appliance at `http://<IP>/` directly renders the login portal with `HTTP 200 OK` rather than redirecting to `http://localhost:8080/auth/login`.

### 7.2 Syncing View & Controller Changes from Windows 11
From your Windows workspace (`d:\laragon\www\micronos`), use OpenSSH (built into Windows 11):

```powershell
# Push modified controllers, models, and views directly to the appliance
scp -r app ministrator@<Appliance-IP>:/usr/local/micron/

# Push public assets (stylesheets, JavaScript, icons)
scp -r public/css public/assets ministrator@<Appliance-IP>:/usr/local/micron/public/
```

### 7.3 Clearing Appliance Cache & Reloading Web Services
```powershell
ssh ministrator@<Appliance-IP> "sudo rm -rf /usr/local/micron/writable/cache/* && sudo systemctl reload php8.4-fpm && sudo systemctl reload apache2"
```

---

## 8. Live OS & Data Plane Hot-Syncing (`micrond`, nftables, Services)

The system management daemon is located at `/usr/local/bin/micrond` (or `/usr/local/micron/system/micrond/micrond.py`). It runs as root under systemd and executes all IPC commands from the PHP control plane over `/run/micron/micrond.sock`.

### 8.1 Syncing the Python Daemon
When modifying daemon RPC handlers, system metrics, or hardware abstractions in `system/micrond/micrond.py`:

```powershell
# 1. Push updated daemon script to the appliance
scp system/micrond/micrond.py root@192.168.1.1:/usr/local/bin/micrond

# 2. Restart the daemon service
ssh root@192.168.1.1 "systemctl restart micrond"

# 3. Check daemon status and healthcheck unit
ssh root@192.168.1.1 "systemctl status micrond --no-pager"
```

### 8.2 Live Data Plane & Health Probes
```powershell
# Run the appliance healthcheck probe
ssh root@192.168.1.1 "/usr/local/micron/installer/micron-healthcheck"

# View live nftables ruleset generated by NftablesCompiler.php
ssh root@192.168.1.1 "nft list ruleset"

# Stream live micrond RPC logs
ssh root@192.168.1.1 "journalctl -u micrond -f --no-tail"
```

---

## 9. Hyper-V Native Coordination (PowerShell Direct & VMBus `Copy-VMFile`)

If you are modifying low-level network rules in nftables or changing IP addresses, you might temporarily sever your SSH network connection.

By including `hyperv-daemons` in `packaging/live-build/config/package-lists/micronos.list.chroot`, the appliance runs the Linux Hyper-V KVP and VSS daemons. This allows Windows 11 Pro to communicate with the guest kernel through the Hyper-V VMBus channel without requiring any network connectivity.

### 9.1 Executing Commands via PowerShell Direct
```powershell
# Execute commands as root inside the VM over VMBus
Invoke-Command -VMName "MicronOS-Dev" -Credential (Get-Credential) -ScriptBlock {
    systemctl restart apache2
    ip addr show
}
```

### 9.2 Copying Files over VMBus (Network-Independent)
```powershell
# Copy files into the VM even if the network adapter is down
Copy-VMFile -VMName "MicronOS-Dev" `
            -SourcePath "d:\laragon\www\micronos\system\micrond\micrond.py" `
            -DestinationPath "/usr/local/bin/micrond" `
            -CreateIntermediateDirectories `
            -FileSource Host
```

---

## 10. Automated End-to-End GUI Testing with Antigravity Browser Agent

Antigravity features a built-in autonomous browser agent (`browser_subagent`) that can interact directly with web interfaces running on your local network.

Because your Windows 11 host is connected to `MicronOS-LAN` at `192.168.1.100`, Antigravity can test the live appliance directly:

```
┌───────────────────────────────────────────────────────────────────────┐
│                    ANTIGRAVITY BROWSER AGENT LOOP                     │
├───────────────────────────────────────────────────────────────────────┤
│ 1. Navigate:  https://192.168.1.1 (Accept self-signed certificate)   │
│ 2. Login:     Input 'Ministrator' / 'M1Cr0N' and submit form          │
│ 3. Navigate:  Visit target controller (e.g. /firewall or /webfilter)  │
│ 4. Action:    Add firewall rule, click toggles, submit form           │
│ 5. Audit:     Verify DOM response, check for errors, capture screens  │
│ 6. Artifact:  Save WebP video recording to IDE artifacts directory   │
└───────────────────────────────────────────────────────────────────────┘
```

You can prompt Antigravity:
> *"Test the newly updated Firewall Policy view in the Hyper-V VM at https://192.168.1.1. Log in with Ministrator / M1Cr0N, create a test rule blocking TCP port 8080, save it, verify that the table renders correctly, and take a screenshot."*

Antigravity will launch the browser subagent, execute the actions in real time, record a session video, and confirm whether the backend successfully updated.

---

## 11. Automated Developer Helper Scripts (`tools/sync-vm.ps1`)

To make hot-syncing a single keystroke, use `tools/sync-vm.ps1` in the repository root:

```powershell
# Sync everything (micrond, views, controllers, public assets)
.\tools\sync-vm.ps1

# Sync only the Python daemon
.\tools\sync-vm.ps1 -DaemonOnly

# Sync only the Web GUI
.\tools\sync-vm.ps1 -GuiOnly
```

---

## 12. Final Release Cycle: Appliance Overlay Staging & Golden ISO Compilation

Once all features have been tested and verified in the live VM, prepare the production release:

### 12.1 Staging the Repository Overlay
Run the automated overlay staging script to ensure generated artifacts, CLI completions, and offline vendored assets match the repository:

```bash
# Executed in a Linux or WSL2 environment
packaging/live-build/stage-overlay.sh
```

This verifies the strict allowlist (`app`, `bin`, `installer`, `public`, `system`, `vendor`, `spark`, etc.) and ensures no local `.env` or development databases leak into the production image.

### 12.2 Building the Final ISO
Run Debian `live-build` inside WSL2 or Docker:

```bash
docker run --rm --privileged \
    -v $(pwd):/build \
    -w /build \
    debian:trixie-slim \
    bash -c "apt update && apt install -y live-build debootstrap xorriso squashfs-tools && auto/config && lb build"
```

The resulting `micronos-2.0.0-amd64.hybrid.iso` is ready for physical appliance deployment or datacenter distribution.

---

## 13. Dual Documentation Registry Agreement (`build_docs.php` & `Docs.php`)

The repository maintains documentation accessibility through two coordinated surfaces:
1. **The Standalone Static Portal (`docs/build_docs.php` & `docs/index.html`):** Compiles standalone HTML pages with Prism syntax highlighting, search, and 9 color themes.
2. **The In-App Controller (`app/Controllers/Docs.php`):** Renders documentation directly inside the running appliance management plane under `/docs/<slug>`.

Both registries map `HYPERV_WIN11_DEVELOPMENT_AND_AI_COORDINATION_GUIDE`:
- In `docs/build_docs.php`: Key `'HYPERV_WIN11_DEVELOPMENT_AND_AI_COORDINATION_GUIDE'`
- In `app/Controllers/Docs.php`: Slug `'hyperv'`

---

## 14. Troubleshooting & Windows 11 Specific Considerations

### 14.1 VM Hangs at "Starting Virtual Machine..."
- **Cause:** Virtualization-Based Security (VBS) or Core Isolation in Windows 11 conflicting with third-party hypervisors (e.g. VMware or VirtualBox running alongside Hyper-V).
- **Remedy:** Ensure third-party hypervisors are closed. Hyper-V runs natively under VBS with full hardware acceleration.

### 14.2 Cannot Access Management GUI at `https://192.168.1.1`
1. Check the host adapter IP:
   ```powershell
   Get-NetIPAddress -InterfaceAlias "*MicronOS-LAN*"
   ```
   Ensure it has `192.168.1.100` with netmask `255.255.255.0` (`/24`).
2. Verify Apache2 and PHP-FPM status:
   ```powershell
   ssh ministrator@<Appliance-IP> "systemctl status apache2 php8.4-fpm"
   ```
3. Run the healthcheck probe:
   ```powershell
   ssh root@192.168.1.1 "/usr/local/micron/installer/micron-healthcheck"
   ```

### 14.3 Appliance Loses Internet Access (WAN Down)
- **Cause:** Physical host Wi-Fi disconnected or reconnected to a new access point, changing the MAC/binding on the External Switch.
- **Remedy:** Open Hyper-V Virtual Switch Manager, verify that `MicronOS-WAN` is bound to your currently active physical network card, and run `ip link set eth0 down && ip link set eth0 up` inside the appliance.

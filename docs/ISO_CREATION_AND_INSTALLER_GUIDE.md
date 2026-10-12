# Micron Firewall OS (MicronOS)
## Appliance ISO Creation & Automated Installer Engineering Guide
### Complete Reference for Building Bootable Hybrid (BIOS/UEFI) ISO Images

---

## Table of Contents
1. [Overview & Boot Architecture](#1-overview--boot-architecture)
2. [Build Host Prerequisites & Environment Setup](#2-build-host-prerequisites--environment-setup)
3. [ISO Builder Workspace Directory Layout](#3-iso-builder-workspace-directory-layout)
4. [Debian `live-build` Configuration Specification](#4-debian-live-build-configuration-specification)
5. [Complete Package Selection Manifest](#5-complete-package-selection-manifest)
6. [Appliance Root Filesystem Overlay Tree](#6-appliance-root-filesystem-overlay-tree)
7. [Interactive Appliance Installer (Ncurses TUI)](#7-interactive-appliance-installer-ncurses-tui)
8. [First-Boot Hardware Detection & Auto-Setup Engine](#8-first-boot-hardware-detection--auto-setup-engine)
9. [A/B Dual Partitioning Scheme & Target Disk Provisioning](#9-ab-dual-partitioning-scheme--target-disk-provisioning)
10. [Compiling & Generating the Hybrid ISO](#10-compiling--generating-the-hybrid-iso)
11. [Testing & Emulation (QEMU, Proxmox VE, VirtualBox)](#11-testing--emulation-qemu-proxmox-ve-virtualbox)
12. [Physical Media Flashing (dd, Rufus, BalenaEtcher)](#12-physical-media-flashing-dd-rufus-balenaetcher)
13. [Headless Unattended Installation — Kickstart Engine](#13-headless-unattended-installation--kickstart-engine)
14. [Automated CI/CD ISO Build Pipeline (Docker & GitHub Actions)](#14-automated-cicd-iso-build-pipeline-docker--github-actions)
15. [Manual ISO Generation — Complete Step-by-Step Walkthrough](#15-manual-iso-generation--complete-step-by-step-walkthrough)

---

## 1. Overview & Boot Architecture

Micron Firewall OS is distributed as a **hybrid ISO image** (`micronos-2.0.0-amd64.hybrid.iso`). A hybrid ISO is structured so that it can be booted either as an optical disc (CD/DVD/Virtual ISO) or written byte-for-byte to a raw USB flash drive, external NVMe enclosure, or CFast card.

```
┌────────────────────────────────────────────────────────────────────────┐
│                   MICRONOS HYBRID ISO BOOT ARCHITECTURE                │
├───────────────────────────────────┬────────────────────────────────────┤
│ Legacy BIOS Boot Path             │ UEFI Boot Path                     │
│ ├── MBR Partition Table           │ ├── GPT / EFI System Partition     │
│ └── ISOLINUX Bootloader           │ └── GRUB2 (x86_64-efi) Bootloader  │
├───────────────────────────────────┴────────────────────────────────────┤
│ Linux Kernel 6.x Minimal + Initramfs (with OverlayFS & SquashFS)       │
├────────────────────────────────────────────────────────────────────────┤
│ Live Memory Execution Environment (RAM Disk)                          │
│ ├── Automatic Hardware Network Interface Enumeration (NICs)            │
│ ├── Storage Device Scanner (NVMe, SATA, SAS, eMMC)                     │
│ └── Interactive Dialog / Ncurses Appliance Installer                   │
├────────────────────────────────────────────────────────────────────────┤
│ Golden System Image (/live/filesystem.squashfs)                        │
│ ├── Debian 13 Minimal Hardened Base                                    │
│ ├── nftables, WireGuard, FRRouting, Squid 6+, Kea, Unbound, Suricata   │
│ ├── PPPoE Client + Subscriber Access Server (BRAS), RADIUS & EAP-TLS   │
│ ├── CodeIgniter 4 Management Plane (/usr/local/micron)                 │
│ └── micrond System Control Daemon                                      │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Build Host Prerequisites & Environment Setup

Building the ISO requires a 64-bit Debian 12 (Bookworm), Debian 13 (Trixie), or Ubuntu 24.04 LTS build machine with root privileges. You can also build inside a Docker container or Debian WSL2 instance.

### 2.1 Hardware Requirements
- **CPU:** 4+ Cores recommended.
- **RAM:** Minimum 8 GB (16 GB recommended for in-memory SquashFS compilation).
- **Disk Space:** 30 GB free storage (fast NVMe/SSD recommended).

### 2.2 Installing Required Build Toolchains
```bash
sudo apt update
sudo apt install -y \
    live-build \
    debootstrap \
    xorriso \
    isolinux \
    syslinux-efi \
    grub-pc-bin \
    grub-efi-amd64-bin \
    mtools \
    dosfstools \
    squashfs-tools \
    rsync \
    curl \
    git \
    dialog
```

---

## 3. ISO Builder Workspace Directory Layout

Create the build tree:

```bash
mkdir -p ~/micronos-builder
cd ~/micronos-builder
```

The resulting structure will mirror the following:

```
micronos-builder/
├── auto/
│   ├── clean
│   ├── config
│   └── build
├── config/
│   ├── archives/                 # Custom third-party apt repositories
│   ├── package-lists/            # Package manifests
│   │   └── micronos.list.chroot
│   ├── includes.chroot/          # Files to overlay directly into appliance rootfs
│   │   ├── etc/
│   │   │   ├── apache2/sites-available/micronos.conf
│   │   │   ├── php/8.4/fpm/pool.d/www.conf
│   │   │   ├── systemd/system/
│   │   │   │   ├── micrond.service
│   │   │   │   └── micron-firstboot.service
│   │   │   └── issue
│   │   ├── usr/
│   │   │   ├── local/bin/
│   │   │   │   ├── micron-installer.sh
│   │   │   │   ├── micron-firstboot.sh
│   │   │   │   └── microctl          # Console CLI (staged from bin/microctl)
│   │   │   ├── share/bash-completion/completions/
│   │   │   │   └── microctl          # Tab completion (staged from packaging/live-build/completion/)
│   │   │   ├── lib/micron/
│   │   │   └── local/micron/         # CodeIgniter 4 Application
│   │   │       ├── installer/        # A/B slot assets (Phase 0)
│   │   │       │   ├── install.sh
│   │   │       │   ├── micron-slot
│   │   │       │   ├── micron-healthcheck
│   │   │       │   ├── micron-healthcheck.service
│   │   │       │   ├── tui.py
│   │   │       │   └── grub.d/09_micron_slots
│   │   │       └── …
│   │   └── var/
│   │       └── lib/micron/
│   └── hooks/
│       └── live/
│           ├── 01-enable-services.chroot
│           ├── 02-setup-appliance-user.chroot
│           └── 03-harden-kernel.chroot
```

---

## 4. Debian `live-build` Configuration Specification

Create `auto/config` to define build flags:

```bash
cat << 'EOF' > auto/config
#!/bin/sh

lb config noauto \
    --mode debian \
    --distribution trixie \
    --architectures amd64 \
    --archive-areas "main contrib non-free non-free-firmware" \
    --image-name "micronos-0.1.25-amd64" \
    --iso-application "Micron Firewall OS" \
    --iso-publisher "Micron Network Systems" \
    --iso-volume "MICRONOS_0125" \
    --bootloader grub-efi \
    --binary-images iso-hybrid \
    --memtest none \
    --win32-loader false \
    --apt-recommends false \
    --firmware-binary true \
    --firmware-chroot true \
    "${@}"
EOF
chmod +x auto/config
```

### 4.1 Staging the Appliance Overlay

`packaging/live-build/stage-overlay.sh` stages the build inputs that are *generated* rather than checked into `config/includes.chroot/`, from their single source of truth — the repository itself:

```bash
# Writes config/includes.chroot/usr/local/bin/microctl                             (0755)
#        config/includes.chroot/usr/share/bash-completion/completions/microctl     (0644)
#        config/includes.chroot/usr/local/micron/…                                 (the whole web application)
packaging/live-build/stage-overlay.sh [--out <dir>]
```

The appliance runs the application from `/usr/local/micron`, so the repository is staged there **whole**: the front controller, the framework, the CLI's command classes, the IPC daemon, the interface's offline assets and the `vendor/` tree the autoloader needs. Copying that tree by hand is what this replaces — a tree copied a release ago, or copied minus a directory added since, boots an appliance that answers 200 and behaves like an older build, and nothing in the image says so.

What ships is an **allow-list** (`app`, `bin`, `installer`, `public`, `system`, `vendor`, `spark`, `preload.php`, `composer.json`, `composer.lock`, `env`, `LICENSE`, `README.md`), so development state is excluded by construction rather than by remembering: the repository's `.env`, its test suite, its build output and the `writable/` state — including the development configuration database — are never named, and a later addition to those directories cannot start shipping by accident. `writable/` is staged as empty directories only, since it is state and not code; the provisioning hook (`config/hooks/live/01-micronos-setup.hook.chroot`) chowns it to `www-data`.

The application tree in the overlay is **replaced** on every run rather than merged into, so a file left behind by an earlier build can never ride along inside a fresh image. The CLI is installed beside it, and anything else the overlay holds is left alone.

The Terminal CLI is likewise a build input, not a second copy of the tree, and the two asset trees are the ones the UI has to serve **itself**: the appliance has no route to the internet, so its typefaces and the documentation browser's markdown renderer and syntax highlighter are vendored under `public/assets/` instead of being fetched from a CDN.

`packaging/live-build/auto/config` runs this before `lb config`, so a normal build stages the overlay automatically and cannot ship an image without a console CLI or without the application itself.

Run it by hand when building from an unpacked copy, and re-run it after any change to the sources. Because `config/includes.chroot/` is generated, none of these files are kept in version control there. `tests/packaging/TerminalCliOverlayTest.php` runs the staging step and executes the CLI, `tests/packaging/WebAppOverlayTest.php` compares the staged application against the repository byte-for-byte and boots it out of the overlay, and `tests/packaging/OfflineAssetOverlayTest.php` does the same for the offline assets — so a regression is caught at build time instead of on an appliance.

Run the configuration generator:
```bash
lb config
```

---

## 5. Complete Package Selection Manifest

Create `config/package-lists/micronos.list.chroot`. This file defines every binary package baked into the installation ISO:

```plaintext
# 1. Base Hardware & Kernel Support
linux-image-amd64
firmware-linux
firmware-misc-nonfree
firmware-realtek
firmware-bnx2
firmware-bnx2x
firmware-intel-sound
intel-microcode
amd64-microcode
pciutils
usbutils
ethtool
lm-sensors

# 2. Linux Data Plane & Networking
nftables
iproute2
bridge-utils
vlan
wireguard-tools
strongswan
strongswan-pki
keepalived
conntrack
conntrackd
frr
frr-pythontools

# 3. Next-Gen Web Filter & Content Scanning
squid
c-icap
clamav
clamav-daemon
clamav-freshclam

# 4. Core Network Services
kea-dhcp4-server
kea-ctrl-agent
dhcp-helper             # DHCP/BOOTP relay agent (Services → DHCP Relay); ISC dhcrelay is EOL
unbound
chrony

# 5. Security & Traffic Shaping
suricata
tc
libbpf1
bpftool

# 6. Web Management Plane (Apache2 + PHP 8.4 / CodeIgniter 4)
apache2
libapache2-mod-fcgid
php8.4-fpm
php8.4-cli
php8.4-sqlite3
php8.4-mysql
php8.4-curl
php8.4-mbstring
php8.4-xml
php8.4-zip
php8.4-intl
php8.4-ldap

# 7. Python 3 Runtime for micrond IPC Daemon
python3
python3-pip
python3-setuptools
python3-pyroute2
python3-systemd
python3-psutil

# 8. Database Engines
sqlite3
libsqlite3-0

# 9. Installer & Disk Management Utilities
dialog
parted
gdisk
dosfstools
e2fsprogs
squashfs-tools
rsync
curl
jq
tar
gzip
sudo
openssh-server
ca-certificates
bash-completion        # shell completion framework; lazy-loads the staged microctl completion

# 10. WAN Access, PPPoE & RADIUS Subscriber Management
ppp                    # pppd, rp-pppoe.so, radius.so and radattr.so plugins
pppoe                  # pppoe-server / pppoe-dump userspace tools (Access Concentrator)
radcli                 # runtime for pppd's radius.so (freeradius-client successor)
freeradius-client      # optional: /etc/radiusclient tooling on older releases
```

> **Package notes for the access layer**
> - `ppp` already ships the `radius.so` and `radattr.so` plugins and **compiles EAP-TLS into `pppd`** — there is no `eap-tls.so` package or plugin to install.
> - `pppoe` provides the Access Concentrator (`pppoe-server`) used for the subscriber side.
> - The RADIUS client configuration directory is auto-detected by the compiler among `/etc/radiusclient`, `/etc/radiusclient-ng`, and `/etc/freeradius-client`; whichever exists on the image is used.
> - EAP-TLS requires **no additional package**, but it does require certificate material, which is provisioned through the appliance PKI (`pki_certificates`) rather than baked into the ISO.

---

## 6. Appliance Root Filesystem Overlay Tree

Any file placed under `config/includes.chroot/` is copied verbatim into the target appliance filesystem.

The Terminal CLI (`usr/local/bin/microctl`), its completion (`usr/share/bash-completion/completions/microctl`) and the **whole web application** (`usr/local/micron/` — the front controller under `public/`, the framework, the CLI's command classes, the IPC daemon under `system/micrond/`, the UI's offline assets and `vendor/`) are staged there by `packaging/live-build/stage-overlay.sh` rather than copied by hand — see §4.1. The staged application root matches the `DocumentRoot` in the provisioning hook and the `ExecStart` of the daemon's unit, so what is staged is what the appliance serves and runs.

### 6.1 Web Management Virtual Host Configuration (Apache 2.4 + PHP 8.4-FPM)

The core Web Management Plane uses **Apache 2.4 (`mpm_event`)** paired with **PHP 8.4-FPM** over a high-performance Unix domain socket (`/run/php/php8.4-fpm.sock`).

> [!NOTE]
> **Web Server Stack Roles:** Apache 2.4 + PHP 8.4-FPM powers the appliance management plane, CodeIgniter 4 application, and local administration GUI. Nginx is reserved specifically for the upcoming Layer 7 Clientless SSL-VPN & ZTNA Reverse Proxy Gateway module (see [`docs/SSLVPN_ZTNA_CLIENTLESS_GATEWAY_SPECIFICATION.md`](SSLVPN_ZTNA_CLIENTLESS_GATEWAY_SPECIFICATION.html)).

The Apache virtual host is installed at `config/includes.chroot/etc/apache2/sites-available/micronos.conf`:

```apache
<VirtualHost *:80>
    ServerName micronos.local
    DocumentRoot /usr/local/micron/public

    <Directory /usr/local/micron/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Forward PHP requests to PHP 8.4-FPM FastCGI Unix Domain Socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/micron_error.log
    CustomLog ${APACHE_LOG_DIR}/micron_access.log combined
</VirtualHost>
```

#### Application Rewrite & Header Controls (`public/.htaccess`)
Because `AllowOverride All` is enabled, all front-controller routing (`index.php`), asset caching headers (`immutable` for versioned offline fonts, JavaScript libraries, and stylesheets), and security controls are enforced directly by CodeIgniter 4's `public/.htaccess`:
- Directs all dynamic route requests through `/index.php/$1` [L].
- Protects configuration dotfiles (`.env`, `.git`, `.htaccess`) with `403 Forbidden`.
- ACME HTTP-01 challenge responses written to `writable/acme-challenges/` are routed seamlessly via CodeIgniter's public web root or directory handler.
- Assets are served with immutable long-term caching headers (`max-age=31536000, immutable`) matching versioned query strings (`?v=<filemtime>`).

### 6.1.1 Gateway Reverse Proxy & Static Asset Caching (Nginx)

For edge TLS termination, clientless gateway reverse proxying, and high-performance static asset offloading:

```nginx
server {
    listen 443 ssl http2;
    server_name micronos.local;
    root /usr/local/micron/public;

    location ^~ /assets/fonts/ {
        access_log off;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }
    location ^~ /assets/js/ {
        access_log off;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }
    location ^~ /assets/vendor/ {
        access_log off;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }
    location = /css/micronos.css {
        access_log off;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location / {
        proxy_pass http://127.0.0.1:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 6.2 IPC Daemon Service (`config/includes.chroot/etc/systemd/system/micrond.service`)
```ini
[Unit]
Description=MicronOS Root Control Daemon
After=network.target

[Service]
Type=simple
User=root
ExecStart=/usr/local/bin/micrond
Restart=always
RestartSec=3s
RuntimeDirectory=micron
RuntimeDirectoryMode=0770

[Install]
WantedBy=multi-user.target
```

### 6.3 First-Boot Appliance Setup Service (`config/includes.chroot/etc/systemd/system/micron-firstboot.service`)
```ini
[Unit]
Description=MicronOS First-Boot Appliance Initialization
After=local-fs.target
Before=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/micron-firstboot.sh
RemainAfterExit=true

[Install]
WantedBy=multi-user.target
```

---

## 7. Interactive Appliance Installer (Ncurses TUI)

Create `config/includes.chroot/usr/local/bin/micron-installer.sh`. This interactive script is invoked when booting the live media.

```bash
#!/bin/bash
# ==============================================================================
# Micron Firewall OS - Interactive Terminal Installer (Ncurses/Dialog)
# ==============================================================================
set -e

# Ensure clean screen
clear

# Welcome Dialog
dialog --backtitle "Micron Firewall OS Installer" \
       --title "Welcome to MicronOS 2.0" \
       --yesno "Welcome to the Micron Firewall OS installation wizard.\n\nThis utility will partition your target disk, install the hardened Debian Linux kernel data plane, provision a standby upgrade slot, and deploy the CodeIgniter 4 management plane.\n\nWARNING: The target disk will be completely wiped!\n\nDo you wish to proceed?" 14 70

if [ $? -ne 0 ]; then
    dialog --msgbox "Installation aborted. Exiting to live maintenance shell." 7 60
    exit 1
fi

# Detect Storage Disks
RAW_DISKS=$(lsblk -d -n -p -o NAME,SIZE,MODEL | grep -v "loop" | grep -v "airootfs" || true)

if [ -z "$RAW_DISKS" ]; then
    dialog --title "Error" --msgbox "No suitable hard drives or NVMe devices detected!" 8 50
    exit 1
fi

MENU_OPTIONS=()
while read -r name size model; do
    MENU_OPTIONS+=("$name" "$size $model")
done <<< "$RAW_DISKS"

TARGET_DISK=$(dialog --backtitle "Micron Firewall OS Installer" \
                     --title "Target Disk Selection" \
                     --menu "Select the hard drive or NVMe disk to install MicronOS:" 16 70 6 \
                     "${MENU_OPTIONS[@]}" 3>&1 1>&2 2>&3)

if [ -z "$TARGET_DISK" ]; then
    dialog --msgbox "No disk selected. Installation aborted." 7 50
    exit 1
fi

# Confirm Destruction
dialog --backtitle "CONFIRM DISK OVERWRITE" \
       --title "WARNING: DATA DESTRUCTION" \
       --defaultno \
       --yesno "Are you ABSOLUTELY SURE you want to erase and install on:\n\n$TARGET_DISK\n\nAll existing partitions and data will be permanently lost!" 10 65

if [ $? -ne 0 ]; then
    exit 1
fi

# Partitioning Disk (GPT + EFI + Slot A + Slot B + Data)
(
    echo "10"; echo "XXX"; echo "Wiping disk headers and creating GPT label..."; echo "XXX"
    sgdisk --zap-all "$TARGET_DISK" >/dev/null 2>&1 || true
    parted -s "$TARGET_DISK" mklabel gpt

    echo "25"; echo "XXX"; echo "Creating EFI System Partition (512MB)..."; echo "XXX"
    parted -s "$TARGET_DISK" mkpart ESP fat32 1MiB 513MiB
    parted -s "$TARGET_DISK" set 1 esp on

    echo "40"; echo "XXX"; echo "Creating Root Slot A (10GB SquashFS/Ext4)..."; echo "XXX"
    parted -s "$TARGET_DISK" mkpart ROOT_A ext4 513MiB 10753MiB

    echo "55"; echo "XXX"; echo "Creating Root Slot B (10GB Standby Slot)..."; echo "XXX"
    parted -s "$TARGET_DISK" mkpart ROOT_B ext4 10753MiB 20993MiB

    echo "70"; echo "XXX"; echo "Creating Data Layer (Persistent Configuration)..."; echo "XXX"
    parted -s "$TARGET_DISK" mkpart DATA ext4 20993MiB 100%

    echo "85"; echo "XXX"; echo "Formatting filesystems..."; echo "XXX"
    # Identify partition names (handles nvme0n1p1 vs sda1)
    if [[ "$TARGET_DISK" =~ [0-9]$ ]]; then
        P1="${TARGET_DISK}p1"; P2="${TARGET_DISK}p2"; P3="${TARGET_DISK}p3"; P4="${TARGET_DISK}p4"
    else
        P1="${TARGET_DISK}1"; P2="${TARGET_DISK}2"; P3="${TARGET_DISK}3"; P4="${TARGET_DISK}4"
    fi

    mkfs.vfat -F32 -n "ESP" "$P1" >/dev/null 2>&1
    mkfs.ext4 -F -L "MICRON_ROOT_A" "$P2" >/dev/null 2>&1
    mkfs.ext4 -F -L "MICRON_DATA" "$P4" >/dev/null 2>&1

    echo "100"; echo "XXX"; echo "Disk preparation completed."; echo "XXX"
) | dialog --backtitle "MicronOS Installer" --gauge "Partitioning Target Disk..." 8 70 0

# Mount Target System
mkdir -p /mnt/target
mount "$P2" /mnt/target
mkdir -p /mnt/target/boot/efi
mount "$P1" /mnt/target/boot/efi
mkdir -p /mnt/target/var/lib/micron
mount "$P4" /mnt/target/var/lib/micron

# Deploy Operating System
(
    echo "10"; echo "XXX"; echo "Deploying MicronOS System Image to disk..."; echo "XXX"
    rsync -aAX --info=progress2 \
          --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} \
          / /mnt/target/ >/dev/null 2>&1

    echo "70"; echo "XXX"; echo "Writing filesystem mount table (fstab)..."; echo "XXX"
    UUID_ESP=$(blkid -s UUID -o value "$P1")
    UUID_ROOT=$(blkid -s UUID -o value "$P2")
    UUID_DATA=$(blkid -s UUID -o value "$P4")

    cat << FSTAB > /mnt/target/etc/fstab
UUID=$UUID_ROOT  /                  ext4    defaults,noatime      0 1
UUID=$UUID_ESP   /boot/efi          vfat    umask=0077            0 2
UUID=$UUID_DATA  /var/lib/micron    ext4    defaults,noatime      0 2
tmpfs            /tmp               tmpfs   defaults,nosuid,nodev 0 0
FSTAB

    echo "85"; echo "XXX"; echo "Installing UEFI GRUB2 Bootloader..."; echo "XXX"
    mount --bind /dev /mnt/target/dev
    mount --bind /proc /mnt/target/proc
    mount --bind /sys /mnt/target/sys

    chroot /mnt/target grub-install --target=x86_64-efi --efi-directory=/boot/efi --bootloader-id=MicronOS --recheck >/dev/null 2>&1
    chroot /mnt/target update-grub >/dev/null 2>&1

    # Cleanup binds
    umount /mnt/target/dev /mnt/target/proc /mnt/target/sys

    echo "100"; echo "XXX"; echo "Installation finalized successfully."; echo "XXX"
) | dialog --backtitle "MicronOS Installer" --gauge "Deploying System Image..." 8 70 0

# Final Notice
dialog --backtitle "MicronOS Installation Complete" \
       --title "Success" \
       --msgbox "Micron Firewall OS has been successfully installed!\n\nDefault Management IP : https://192.168.1.1\nDefault Credentials   : admin / micron\n\nPlease remove the USB flash drive and press OK to reboot." 12 65

umount -R /mnt/target
reboot
```

---

## 8. First-Boot Hardware Detection & Auto-Setup Engine

When the newly installed appliance powers on for the first time, `micron-firstboot.sh` executes before networking starts:

```bash
#!/bin/bash
# ==============================================================================
# Micron Firewall OS - First Boot Hardware Provisioning Engine
# ==============================================================================
set -e

FLAG_FILE="/var/lib/micron/.firstboot_done"
[ -f "$FLAG_FILE" ] && exit 0

echo "[MicronOS] Initializing first-boot hardware provisioning..."

# 1. Enumerate Physical Network Interfaces
NICS=($(ls -1 /sys/class/net | grep -v "lo" | grep -v "virbr" || true))
NIC_COUNT=${#NICS[@]}

if [ "$NIC_COUNT" -eq 0 ]; then
    echo "[MicronOS ERROR] No network interfaces detected!"
    exit 1
fi

WAN_IF="${NICS[0]}"
LAN_IF="${NICS[1]}"

# If only 1 NIC exists, configure single-port router mode
if [ -z "$LAN_IF" ]; then
    LAN_IF="$WAN_IF"
    echo "[MicronOS] Single NIC detected. Using $LAN_IF for Management LAN."
fi

# 2. Configure Default Interfaces in SQLite Database
sqlite3 /var/lib/micron/config.db << SQL
CREATE TABLE IF NOT EXISTS interfaces (
    id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    physical_device VARCHAR(32) NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    ip_assignment_type VARCHAR(16) NOT NULL,
    ipv4_address VARCHAR(45) NULL,
    ipv4_netmask INT NULL,
    ipv4_gateway VARCHAR(45) NULL,
    mtu INT DEFAULT 1500
);

INSERT OR REPLACE INTO interfaces VALUES ('wan', 'WAN', '$WAN_IF', 1, 'dhcp', NULL, NULL, NULL, 1500);
INSERT OR REPLACE INTO interfaces VALUES ('lan', 'LAN', '$LAN_IF', 1, 'static', '192.168.1.1', 24, NULL, 1500);
SQL

# 3. Configure IP on LAN interface
ip link set dev "$LAN_IF" up
ip addr add 192.168.1.1/24 dev "$LAN_IF" || true

# 4. Generate Machine Key and Self-Signed TLS Certificates
mkdir -p /etc/ssl/certs /etc/ssl/private /etc/micron
openssl rand -hex 32 > /etc/micron/machine-key
chmod 0600 /etc/micron/machine-key

openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /etc/ssl/private/micronos-selfsigned.key \
    -out /etc/ssl/certs/micronos-selfsigned.crt \
    -subj "/C=US/ST=State/L=City/O=Micron Network Systems/CN=firewall.local"

# 5. Generate Initial Permissive nftables Rules
cat << 'NFT' > /etc/nftables.conf
flush ruleset

table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;
        iif "lo" accept
        ct state established,related accept
        tcp dport { 80, 443, 22 } accept
        icmp type echo-request accept
    }
    chain forward {
        type filter hook forward priority 0; policy accept;
    }
    chain output {
        type filter hook output priority 0; policy accept;
    }
}
NFT
nft -f /etc/nftables.conf

# 6. Enable & Start Web Services
systemctl enable --now apache2 php8.4-fpm micrond

# 7. Print Console Banner
cat << 'BANNER' > /etc/issue

┌╦═╦═╦┐ ┌═╤╦╤═┐ ┌╦═══╦┐ ┌╦═══╦┐ ┌╦═══╦┐ ┌╦═╗ ╦┐      ┌╦═══╦┐ ┌╦═══╦┐
│║ ║ ║│   │║│   │║      │╠══╦╩┘ │║   ║│ │║ ║ ║│      │║   ║│ └╩═══╦┐
└╩   ╩┘ └═╧╩╧═┘ └╩═══╩┘ └╩  ╚═┘ └╩═══╩┘ └╩ ╚═╩┘      └╩═══╩┘ └╩═══╩┘
 Enterprise Linux Firewall & Network Appliance

 WAN Interface : $WAN_IF (DHCP Client)
 LAN Interface : $LAN_IF (192.168.1.1/24)

 Management Web GUI : http://<IP>/ (Port 80)

BANNER

touch "$FLAG_FILE"
echo "[MicronOS] First-boot provisioning completed successfully."
```

---

## 9. A/B Dual Partitioning Scheme & Target Disk Provisioning

MicronOS targets carrier-grade resilience through **A/B Dual Partitioning**. The layout below is the design; read the status note after it for what the installer currently does.

```
┌────────────────────────────────────────────────────────────────────────┐
│                   APPLIANCE STORAGE PARTITION LAYOUT                   │
├───────────┬──────────────┬────────────┬────────────────────────────────┤
│ Partition │ Label        │ Size       │ Mount Point & Purpose          │
├───────────┼──────────────┼────────────┼────────────────────────────────┤
│ /dev/sdX1 │ ESP          │ 512 MiB    │ /boot/efi (UEFI Bootloader)    │
│ /dev/sdX2 │ MICRON_ROOT_A│ 10.0 GiB   │ / (Active OS Slot - Read-Only) │
│ /dev/sdX3 │ MICRON_ROOT_B│ 10.0 GiB   │ (Inactive Upgrade Target Slot) │
│ /dev/sdX4 │ MICRON_DATA  │ Remainder  │ /var/lib/micron (Config & Logs)│
└───────────┴──────────────┴────────────┴────────────────────────────────┘
```

> **Installer status.** The authoritative installer is **`installer/install.sh`** in the repository, *not* the listing embedded below — read the script below as the original design sketch, which never matched it. The shipped installer originally created ESP + swap + a **single** root partition, with no data partition and no second slot at all; as of **Phase 0** it provisions the full layout shown above (`ESP` + swap + `ROOT_A` + `ROOT_B` + `DATA`), formats and labels every slot, mounts `DATA` at `/var/lib/micron`, lists slot B in `/etc/fstab` by UUID as `noauto`, installs a GRUB boot-slot selector (`grub.d/09_micron_slots`) that boots a slot only after a verified boot, and installs `micron-healthcheck.service`. Slot B is left **empty on purpose**: it is the target a platform update writes into, so a rollback only becomes possible once the updater itself lands (Phase 3 of the **[Update & Upgrade Mechanism Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html)**). The live medium must carry the `installer/` directory, since the installer installs its own helper assets (`micron-slot`, `micron-healthcheck`, the unit and the GRUB fragment) from there.

---

## 10. Compiling & Generating the Hybrid ISO

Execute the build sequence from the build workspace directory:

```bash
cd ~/micronos-builder

# 1. Clean previous build artifacts
sudo lb clean --purge

# 2. Re-run configuration if changed
lb config

# 3. Compile the ISO (Requires root permissions)
sudo lb build 2>&1 | tee build.log
```

When finished, the root directory will contain:
`micronos-2.0.0-amd64.hybrid.iso`

---

## 11. Testing & Emulation (QEMU, Proxmox VE, VirtualBox)

### 11.1 Quick Local Emulation via QEMU (Recommended)
You can test the generated ISO in 10 seconds without burning a physical drive:

```bash
# 1. Create a 30GB sparse virtual disk
qemu-img create -f qcow2 test-appliance.qcow2 30G

# 2. Boot ISO with 2 virtual NICs (WAN on NAT, LAN on Host Bridge)
qemu-system-x86_64 \
    -enable-kvm \
    -m 4G \
    -smp 4 \
    -cpu host \
    -bios /usr/share/ovmf/OVMF.fd \
    -cdrom micronos-2.0.0-amd64.hybrid.iso \
    -drive file=test-appliance.qcow2,if=virtio,format=qcow2 \
    -netdev user,id=wan,net=192.168.100.0/24 -device virtio-net-pci,netdev=wan \
    -netdev user,id=lan,net=192.168.1.0/24 -device virtio-net-pci,netdev=lan \
    -boot d
```

### 11.2 Proxmox VE Virtual Appliance Configuration
- **OS Type:** Linux 6.x Kernel (x86_64).
- **BIOS:** OVMF (UEFI).
- **Machine:** q35.
- **SCSI Controller:** VirtIO SCSI Single.
- **Hard Disk:** 32 GB (Discard enabled, SSD emulation).
- **Network Cards:** 2x VirtIO (assigned to `vmbr0` WAN and `vmbr1` LAN).

---

## 12. Physical Media Flashing (dd, Rufus, BalenaEtcher)

### 12.1 Linux / macOS CLI (`dd`)
```bash
# Identify target USB drive (e.g. /dev/sdb)
lsblk

# Write hybrid image byte-for-byte
sudo dd if=micronos-2.0.0-amd64.hybrid.iso of=/dev/sdX bs=4M status=progress oflag=sync
```

### 12.2 Windows Flashing
- **Rufus:** Select `micronos-2.0.0-amd64.hybrid.iso`. When prompted, choose **"Write in DD Image mode"**.
- **BalenaEtcher:** Select ISO file $\rightarrow$ Select Target USB $\rightarrow$ Flash.

---

## 13. Headless Unattended Installation — Kickstart Engine

MicronOS ships its **own Kickstart engine** inside `installer/install.sh`, replacing the older Debian preseed approach. It provides zero-touch, fully unattended installation with richer hardware detection, A/B slot provisioning, bridge support, and remote file delivery.

### 13.1 How the Kickstart Engine Works

When `install.sh` launches it checks for an answer file in this priority order:

1. **CLI flag** — `./install.sh --kickstart /path/to/micron-kickstart.cfg` or `-k URL`
2. **Kernel command-line** — `micron.kickstart=<path|URL>` or `micron.ks=<URL>` passed to the live kernel via GRUB
3. **Well-known search paths** (scanned in order):
   - `/run/live/medium/micron-kickstart.cfg` (on-boot-media, same USB)
   - `/run/live/medium/kickstart.cfg`
   - `/etc/micron/kickstart.cfg`
   - `/micron-kickstart.cfg`
4. **OEMDRV / MICRON\_KS volume** — any attached block device with label `OEMDRV` or `MICRON_KS` is mounted and scanned for `micron-kickstart.cfg`, `kickstart.cfg`, or `ks.cfg`
5. **Remote URL (PXE/HTTP/FTP)** — if the resolved file starts with `https://`, `http://`, or `ftp://`, it is fetched with `curl` (or `wget` as fallback) into `/tmp/` before sourcing

If none of the above resolves, the installer falls back to **interactive TUI mode** automatically — no flag is required to run interactively.

### 13.2 Kickstart Answer-File Reference

Create your kickstart file as a plain shell script fragment (it is `source`-d directly).
The canonical template is [`installer/micron-kickstart.cfg.example`](../installer/micron-kickstart.cfg.example):

```bash
# ==============================================================================
# Micron OS — Automated Kickstart Installation Configuration (ks.cfg)
# ==============================================================================

# 1. Keyboard Layout & Localization
# Supported: us, uk, de, fr, es, it, br, jp, pl, ru, se
KEYMAP="us"

# 2. System Timezone (Olson name)
TIMEZONE="Asia/Kolkata"

# 3. Target Storage Disk
# 'auto' or 'first' = auto-select first suitable drive (>= 27 GB)
# Or explicit device: /dev/sda, /dev/nvme0n1, /dev/vda
TARGET_DISK="auto"

# 4. Storage Partitioning Strategy
# PART_STRATEGY=1 : Guided A/B Dual Slot (ESP + Swap + Root A + Root B + Data)
# SLOT_SIZE       : Size of each Root Slot partition (Slot A & Slot B)
PART_STRATEGY="1"
SLOT_SIZE="10G"

# 5. Administrative Credentials
# Unified password for Web GUI ('ministrator') and OS ('root', 'ministrator')
ADMIN_PW="M1Cr0N"

# 6. Automatic Reboot Behavior
# 'yes' = auto-reboot after install (5 s countdown); 'no' = stay on completion screen
REBOOT="yes"

# 7. Optional Initial Network Configuration (single physical LAN port)
WAN_IF=""
LAN_IF=""
LAN_IP="10.10.10.10"
LAN_NETMASK="255.255.255.0"

# 8. Layer 2 Switch Bridge — Multi-Port Appliance Mode
# Set LAN_IS_BRIDGE="yes" to bundle slave ports into a single Layer 2 switch (br0).
# BRIDGE_SLAVES is a space-separated list of physical ports to enslave.
# LAN_IP is applied to the bridge interface (br0); STP is auto-enabled.
#
# Example — 4-port appliance (eth0=WAN, eth1/eth2/eth3 → br0 LAN):
#   WAN_IF="eth0"
#   LAN_IS_BRIDGE="yes"
#   BRIDGE_SLAVES="eth1 eth2 eth3"
#   LAN_IP="10.10.10.10"
LAN_IS_BRIDGE=""
BRIDGE_SLAVES=""
```

> [!NOTE]
> When `WAN_IF` is left empty the installer configures base networking as *unconfigured*, relying on the interactive CLI option **1) Assign Interfaces** after first boot.

### 13.3 Delivering a Kickstart File via USB (OEMDRV Volume)

This is the easiest zero-network method for batch provisioning:

1. Format a USB flash drive (or partition) with volume label **`OEMDRV`**:
   ```bash
   # Linux
   sudo mkfs.vfat -n OEMDRV /dev/sdX1

   # Windows (PowerShell)
   Format-Volume -DriveLetter E -FileSystem FAT32 -NewFileSystemLabel OEMDRV
   ```
2. Copy your answer file to the root of that volume as **`micron-kickstart.cfg`**.
3. Insert *both* the MicronOS ISO boot USB and the OEMDRV USB into the appliance.
4. Boot from the MicronOS USB — the installer auto-discovers and mounts `OEMDRV` and applies the kickstart silently.

### 13.4 Delivering a Kickstart File via Network (PXE / HTTP)

Pass the URL as a kernel parameter in GRUB (PXE or local GRUB edit):

```
micron.ks=http://192.168.1.50/micron-kickstart.cfg
```

Or invoke the installer directly from a maintenance shell:
```bash
/usr/local/micron/installer/install.sh \
    --kickstart http://192.168.1.50/ks/branch-office.cfg
```

### 13.5 Interactive Installer — Y/n Prompt Behaviour

All interactive confirmation prompts in `install.sh` follow the **`[Y/n]`** convention — pressing **Enter** accepts the default (Yes):

```
Are you sure you want to completely erase /dev/sda? [Y/n]: ↵
Proceed with partitioning and installation? [Y/n]: ↵
Reboot appliance now? [Y/n]: ↵
```

The installer never blocks waiting for explicit `yes` — blank input is treated as confirmation.

---

## 14. Automated CI/CD ISO Build Pipeline (Docker & GitHub Actions)

### 14.1 Docker Build Environment (`Dockerfile.builder`)
```dockerfile
FROM debian:trixie-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt update && apt install -y \
    live-build debootstrap xorriso isolinux syslinux-efi \
    grub-pc-bin grub-efi-amd64-bin mtools dosfstools squashfs-tools \
    rsync curl git dialog openssl && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /build
ENTRYPOINT ["lb", "build"]
```

### 14.2 GitHub Actions Automated Release Workflow (`.github/workflows/build-iso.yml`)
```yaml
name: Build MicronOS ISO

on:
  push:
    tags:
      - 'v*'
  workflow_dispatch:

jobs:
  build-iso:
    runs-on: ubuntu-24.04
    steps:
      - name: Checkout Code
        uses: actions/checkout@v4

      - name: Install Build Dependencies
        run: |
          sudo apt update
          sudo apt install -y live-build debootstrap xorriso isolinux \
            syslinux-efi grub-pc-bin grub-efi-amd64-bin mtools dosfstools \
            squashfs-tools

      - name: Configure and Compile ISO
        run: |
          chmod +x auto/config
          ./auto/config
          sudo lb build

      - name: Upload ISO Artifact
        uses: actions/upload-artifact@v4
        with:
          name: micronos-hybrid-iso
          path: "*.iso"
          retention-days: 14
```

---

## 15. Manual ISO Generation — Complete Step-by-Step Walkthrough

This section documents the exact workflow used to produce production MicronOS ISO releases on a **Windows 11 development host using WSL2 (Kali Linux)**. Use this procedure when the CI/CD pipeline is unavailable, for local development builds, or when you need to rebuild the ISO after source changes.

### 15.1 Prerequisites

| Requirement | Detail |
|---|---|
| Host OS | Windows 10/11 with WSL2 enabled |
| WSL Distro | Kali Linux (preferred) or Debian/Ubuntu |
| Disk Space | ~30 GB free inside WSL filesystem (native ext4 — **not** `/mnt/...`) |
| RAM | 8 GB minimum; 16 GB recommended |
| Network | Internet access from WSL for `debootstrap` |

> [!IMPORTANT]
> **Always build on the native WSL filesystem** (e.g. `/root/micronos-builder`), **not** on the Windows-mounted path (`/mnt/d/...`). The Windows NTFS mount uses `drvfs`, which does not support `chown`/`chmod` correctly. Building inside `/mnt/` produces a broken SquashFS image — the appliance will boot but `www-data` will be unable to write to `writable/`, PHP-FPM will fail, and the management plane will return 500 errors.

### 15.2 Install Build Toolchain Inside WSL

```bash
# Open WSL (Kali) and switch to root
wsl -d kali-linux
sudo -i    # or: su -

# Install all required build tools
apt update
apt install -y \
    live-build \
    debootstrap \
    xorriso \
    isolinux \
    syslinux-efi \
    grub-pc-bin \
    grub-efi-amd64-bin \
    mtools \
    dosfstools \
    squashfs-tools \
    rsync \
    curl \
    git \
    dialog \
    openssl
```

### 15.3 Prepare the Builder Workspace

Create the builder directory **inside the WSL native filesystem**:

```bash
mkdir -p /root/micronos-builder
cd /root/micronos-builder
```

Then populate it from the Windows-side repository using `rsync` over the `/mnt/` mount:

```bash
# Stage the live-build configuration from the repository
rsync -av /mnt/d/laragon/www/micronos/micronos/packaging/live-build/ /root/micronos-builder/
chmod +x auto/config auto/clean auto/build 2>/dev/null || true
```

### 15.4 Stage the Application Overlay

The `stage-overlay.sh` script copies the web application, CLI binary, and offline assets from the repository tree into `config/includes.chroot/` so they get baked into the ISO image. **Always run this before building or rebuilding** — it is idempotent and safe to re-run.

```bash
# Run from inside the builder workspace
bash packaging/live-build/stage-overlay.sh

# Or, if auto/config calls it automatically (default):
# It will run as part of the next step
```

> [!TIP]
> `stage-overlay.sh` accepts `--out <dir>` to target a different output directory. Without the flag it writes into `config/includes.chroot/` relative to the current directory.

### 15.5 Configure `live-build`

```bash
cd /root/micronos-builder

# Run the auto/config script (stages overlay + runs lb config)
sudo bash auto/config

# Equivalent manual steps:
# sudo ./packaging/live-build/stage-overlay.sh
# lb config
```

This generates the full `config/` tree with all live-build flags (`--distribution trixie`, `--architectures amd64`, `--binary-images iso-hybrid`, etc.).

### 15.6 Clean Previous Build Artifacts (If Rebuilding)

Before a fresh rebuild, always purge the previous chroot and binary caches:

```bash
sudo lb clean --purge
```

> [!WARNING]
> `lb clean --purge` removes the cached debootstrap chroot. The subsequent build will re-download and re-debootstrap Debian Trixie (~500 MB). Omit `--purge` and use just `sudo lb clean` to keep the chroot cache and only rebuild the overlay / binary stage.

### 15.7 Build the ISO

```bash
sudo lb build 2>&1 | tee /root/micronos-builder/build.log
```

A successful build takes **15–45 minutes** depending on network speed and CPU. The `build.log` captures the full output for post-mortem debugging.

When finished, the following file is created in the builder root:

```
/root/micronos-builder/micronos-2.0.0-amd64.hybrid.iso
```

Verify the image was produced and check its size:

```bash
ls -lh /root/micronos-builder/*.iso
# Expected: ~900 MB – 1.1 GB
```

### 15.8 Copy the ISO to Windows

Copy the generated ISO from the WSL native filesystem back to your Windows workspace:

```bash
# Copy to the Windows project directory
cp /root/micronos-builder/micronos-2.0.0-amd64.hybrid.iso \
   /mnt/d/laragon/www/micronos/micronos/micronos-2.0.0-amd64.hybrid.iso

# Or to your Desktop
cp /root/micronos-builder/micronos-2.0.0-amd64.hybrid.iso \
   "/mnt/c/Users/$USER/Desktop/micronos-2.0.0-amd64.hybrid.iso"
```

On the Windows side the file is then accessible from File Explorer, Rufus, Hyper-V, VirtualBox, or any other tool.

### 15.9 Quick Rebuild After Source Changes

When only the PHP application or CLI changed (no package additions), skip the full chroot rebuild to save time:

```bash
cd /root/micronos-builder

# 1. Re-stage the overlay (picks up all application changes)
bash /mnt/d/laragon/www/micronos/micronos/packaging/live-build/stage-overlay.sh

# 2. Clean only the binary layer (keeps the chroot)
sudo lb clean

# 3. Rebuild the binary ISO from the existing chroot
sudo lb build 2>&1 | tee build.log
```

This cuts rebuild time from ~30 minutes to **3–8 minutes**.

### 15.10 Complete Manual Build Script (`rebuild_iso.sh`)

Save this as `/root/rebuild_iso.sh` inside WSL for one-command rebuilds:

```bash
#!/bin/bash
# ==============================================================================
# MicronOS — Manual ISO Rebuild Script (WSL2 / Native Linux)
# Usage: sudo bash rebuild_iso.sh [--full]
#   --full  : purge chroot cache and rebuild from scratch (slow, ~30–45 min)
#   default : keep chroot, re-stage overlay and rebuild binary only (fast, ~5 min)
# ==============================================================================
set -e

REPO_WIN_PATH="/mnt/d/laragon/www/micronos/micronos"
BUILD_DIR="/root/micronos-builder"
OUT_ISO_NAME="micronos-2.0.0-amd64.hybrid.iso"

FULL_BUILD=0
[ "$1" = "--full" ] && FULL_BUILD=1

echo "=== MicronOS ISO Builder ==="
echo "Repository : $REPO_WIN_PATH"
echo "Build Dir  : $BUILD_DIR"
echo "Mode       : $([ $FULL_BUILD -eq 1 ] && echo 'FULL (purge + rebuild)' || echo 'FAST (overlay only)')"
echo ""

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"

# Stage the live-build config from the repository (idempotent)
rsync -av --exclude='.git' \
    "$REPO_WIN_PATH/packaging/live-build/" "$BUILD_DIR/"
chmod +x auto/config auto/clean auto/build 2>/dev/null || true

# Stage the application overlay
bash "$REPO_WIN_PATH/packaging/live-build/stage-overlay.sh"

# Configure live-build (runs lb config)
lb config

# Clean
if [ $FULL_BUILD -eq 1 ]; then
    echo "--- Purging build caches (full rebuild)..."
    lb clean --purge
else
    echo "--- Cleaning binary layer only (fast rebuild)..."
    lb clean
fi

# Build
echo "--- Starting lb build (output logged to build.log)..."
lb build 2>&1 | tee build.log

# Verify
if [ -f "$BUILD_DIR/$OUT_ISO_NAME" ]; then
    SIZE=$(du -sh "$BUILD_DIR/$OUT_ISO_NAME" | cut -f1)
    echo ""
    echo "=== Build Successful ==="
    echo "ISO : $BUILD_DIR/$OUT_ISO_NAME"
    echo "Size: $SIZE"
    # Copy to Windows workspace
    cp "$BUILD_DIR/$OUT_ISO_NAME" "$REPO_WIN_PATH/$OUT_ISO_NAME"
    echo "Copied to Windows: $REPO_WIN_PATH/$OUT_ISO_NAME"
else
    echo "ERROR: ISO not found after build. Check build.log for errors."
    exit 1
fi
```

**Usage:**
```bash
# Fast rebuild (keeps chroot, ~5 min)
sudo bash /root/rebuild_iso.sh

# Full rebuild from scratch (~30-45 min)
sudo bash /root/rebuild_iso.sh --full
```

### 15.11 Installer Error Handling & Recovery

The `install.sh` installer includes a comprehensive error recovery system:

| Condition | Behaviour |
|---|---|
| **Ctrl+C pressed** | Installer restarts from scratch automatically |
| **Unhandled error** (`set -e` trap) | Shows error dialog with line number and exit code; offers: Retry / Maintenance Shell / Reboot / Power Off |
| **No disks found** | Prints coloured error and exits gracefully |
| **Kickstart URL fetch fails** | Falls back to interactive mode with a warning |
| **Kickstart file not found** | Falls back to interactive mode silently |

The **Maintenance Shell** option (choice 2 in the error dialog) drops to a root `bash -i` interactive session. Type `exit` to return to the installer wizard.

### 15.12 Installer Feature Reference

| Feature | Detail |
|---|---|
| **NUM LOCK** | Forced ON at installer start, across all TTYs (`/dev/tty[1-6]`, `/dev/tty0`, `/dev/console`) and persisted into the installed system via a `numlock-enable.service` systemd unit |
| **Kickstart engine** | Sources a shell-fragment answer file; supports CLI path, kernel cmdline, OEMDRV USB, and remote HTTP/FTP URL |
| **Y/n prompts** | All confirmations default to Yes on bare Enter — installer never blocks for explicit "yes" |
| **Bridge LAN** | `LAN_IS_BRIDGE=yes` + `BRIDGE_SLAVES="eth1 eth2 eth3"` creates `br0` with STP and enslaves member ports; writes `/etc/network/interfaces` bridge stanza and inserts into `interface_bridges` SQLite table |
| **LAN IP** | Default LAN management address is `10.10.10.10/24` (configurable via `LAN_IP` in kickstart or interactively) |
| **A/B Slots** | Installs ESP + Swap + Root Slot A (active) + Root Slot B (standby) + Data partition; registers GRUB boot-slot selector (`grub.d/09_micron_slots`) |
| **UEFI + BIOS** | Installs GRUB for both `x86_64-efi` and MBR/BIOS fallback |
| **Headless reboot** | `REBOOT=yes` in kickstart triggers 5-second countdown reboot; `REBOOT=no` leaves appliance at completion screen |

---

*Document Version:* 2.0.0 (Updated — Kickstart Engine, Bridge LAN, Manual WSL Build Workflow)  
*Project:* Micron Firewall OS (MicronOS)  
*Component:* Appliance Image Builder & Installer  

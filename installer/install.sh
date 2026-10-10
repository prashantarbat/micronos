#!/bin/bash
# ==============================================================================
# Micron Firewall OS (MicronOS) — Bare-Metal Appliance Installer
# ==============================================================================
# Provisions an A/B appliance layout so platform updates can be written to the
# inactive slot and rolled back by the bootloader:
#
#   p1  ESP        512 MiB  /boot/efi
#   p2  swap         4 GiB
#   p3  ROOT_A      10 GiB  /            ← active, read-only root
#   p4  ROOT_B      10 GiB  (unformatted use until an update)  ← upgrade target
#   p5  DATA       remainder /var/lib/micron  ← persistent state
#
# Slot B is formatted and addressable but deliberately left EMPTY: it is the
# target an update writes to, and GRUB only adopts it once the new image proves
# itself through the health check. GRUB's slot selector is installed by
# grub.d/09_micron_slots; the environment block it reads is seeded here.
#
# See docs/MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.md (Phase 0).
# ==============================================================================
set -e

# Directory holding this script (and its helper assets), so the installer can be
# run from the live medium or from a checkout.
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)

# Root slot size; override for larger or smaller appliances (e.g. 8G / 8192M).
SLOT_SIZE="${MICRON_SLOT_SIZE:-10G}"
case "$SLOT_SIZE" in
    *[Gg]) SLOT_SIZE_MIB=$(( ${SLOT_SIZE%[Gg]} * 1024 )) ;;
    *[Mm]) SLOT_SIZE_MIB=${SLOT_SIZE%[Mm]} ;;
    *[Tt]) SLOT_SIZE_MIB=$(( ${SLOT_SIZE%[Tt]} * 1048576 )) ;;
    *)     SLOT_SIZE_MIB=$(( SLOT_SIZE / 1048576 )) ;;
esac

# Terminal Colors & Dialog Config
export NC='\033[0m'
export GREEN='\033[0;32m'
export CYAN='\033[0;36m'
export YELLOW='\033[1;33m'
export RED='\033[0;31m'

clear
echo -e "${CYAN}"
echo "╔═══════════════════════════════════════════════════════════════════╗"
echo "║             MICRON FIREWALL OS (MicronOS) INSTALLER               ║"
echo "║       Enterprise Linux Security Appliance Deployment Engine       ║"
echo "╚═══════════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Check for root privileges
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: This installer must be run as root.${NC}"
    exit 1
fi

# Detect Available Storage Disks
echo -e "${YELLOW}Scanning storage devices...${NC}"
DISKS=$(lsblk -d -n -o NAME,SIZE,MODEL | grep -v "loop" | grep -v "sr0" || true)

if [ -z "$DISKS" ]; then
    echo -e "${RED}No suitable storage devices found!${NC}"
    exit 1
fi

echo -e "\nAvailable Disks:"
echo "----------------------------------------------------"
lsblk -d -o NAME,SIZE,TYPE,MODEL,TRAN | grep -v "loop" | grep -v "sr0"
echo "----------------------------------------------------"

echo -n -e "\nEnter target disk device to install MicronOS (e.g. sda, nvme0n1): "
read -r TARGET_DEV_NAME

TARGET_DISK="/dev/${TARGET_DEV_NAME}"

if [ ! -b "$TARGET_DISK" ]; then
    echo -e "${RED}Error: Block device ${TARGET_DISK} does not exist!${NC}"
    exit 1
fi

echo -e "\n${RED}====================================================================${NC}"
echo -e "${RED}WARNING: ALL DATA ON ${TARGET_DISK} WILL BE PERMANENTLY DESTROYED!${NC}"
echo -e "${RED}====================================================================${NC}"
echo -n "Type 'YES' to confirm and proceed with full disk partition & format: "
read -r CONFIRM

if [ "$CONFIRM" != "YES" ]; then
    echo "Installation aborted by user."
    exit 0
fi

# Preflight: the A/B layout needs 512M + 4G + 2×slot. Refuse early rather than
# leave a half-partitioned disk behind.
DISK_BYTES=$(blockdev --getsize64 "$TARGET_DISK" 2>/dev/null || echo 0)
DISK_MIB=$((DISK_BYTES / 1048576))
REQUIRED_MIB=$((512 + 4096 + (2 * SLOT_SIZE_MIB) + 2048))
if [ "$DISK_MIB" -gt 0 ] && [ "$DISK_MIB" -lt "$REQUIRED_MIB" ]; then
    echo -e "${RED}Error: ${TARGET_DISK} is ${DISK_MIB} MiB; the A/B layout needs at\nleast ${REQUIRED_MIB} MiB (${SLOT_SIZE} per root slot plus a data partition).${NC}"
    echo -e "${YELLOW}Use a larger disk, or set MICRON_SLOT_SIZE to a smaller value.${NC}"
    exit 1
fi

echo -e "\n${GREEN}[1/7] Partitioning disk with GPT layout (${TARGET_DISK})...${NC}"
wipefs -a "$TARGET_DISK" || true
sgdisk -Z "$TARGET_DISK"
sgdisk -n 1:2048:+512M -t 1:ef00 -c 1:"EFI System Partition" "$TARGET_DISK"
sgdisk -n 2:0:+4G -t 2:8200 -c 2:"MicronOS Swap" "$TARGET_DISK"
sgdisk -n 3:0:+"${SLOT_SIZE}" -t 3:8300 -c 3:"MicronOS Root Slot A" "$TARGET_DISK"
sgdisk -n 4:0:+"${SLOT_SIZE}" -t 4:8300 -c 4:"MicronOS Root Slot B" "$TARGET_DISK"
sgdisk -n 5:0:0 -t 5:8300 -c 5:"MicronOS Persistent Data" "$TARGET_DISK"
sync
partprobe "$TARGET_DISK" || true
sleep 2

# Handle NVMe partition naming (e.g. nvme0n1p1 vs sda1)
if [[ "$TARGET_DISK" =~ nvme ]] || [[ "$TARGET_DISK" =~ mmcblk ]]; then
    P_EFI="${TARGET_DISK}p1"
    P_SWAP="${TARGET_DISK}p2"
    P_ROOT_A="${TARGET_DISK}p3"
    P_ROOT_B="${TARGET_DISK}p4"
    P_DATA="${TARGET_DISK}p5"
else
    P_EFI="${TARGET_DISK}1"
    P_SWAP="${TARGET_DISK}2"
    P_ROOT_A="${TARGET_DISK}3"
    P_ROOT_B="${TARGET_DISK}4"
    P_DATA="${TARGET_DISK}5"
fi

echo -e "${GREEN}[2/7] Formatting file systems (including the inactive slot B)...${NC}"
mkfs.vfat -F32 -n "EFI" "$P_EFI"
mkswap -L "SWAP" "$P_SWAP"
mkfs.ext4 -F -L "MICRON_ROOT_A" "$P_ROOT_A"
# Slot B is formatted here so it is a valid, labelled upgrade target from the
# first boot. It stays empty until an update writes an image into it.
mkfs.ext4 -F -L "MICRON_ROOT_B" "$P_ROOT_B"
mkfs.ext4 -F -L "MICRON_DATA" "$P_DATA"

echo -e "${GREEN}[3/7] Mounting target filesystems...${NC}"
TARGET_MNT="/mnt/micron_target"
mkdir -p "$TARGET_MNT"
mount "$P_ROOT_A" "$TARGET_MNT"
mkdir -p "$TARGET_MNT/boot/efi"
mount "$P_EFI" "$TARGET_MNT/boot/efi"
mkdir -p "$TARGET_MNT/var/lib/micron"
mount "$P_DATA" "$TARGET_MNT/var/lib/micron"
# Slot B is never mounted at install time; only its mountpoint is created.
mkdir -p "$TARGET_MNT/mnt/micron-slot-b"

echo -e "${GREEN}[4/7] Copying system files to target disk (Slot A & Slot B factory baseline)...${NC}"
rsync -aAXv --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} / "$TARGET_MNT/"

# Populate Slot B with factory baseline root filesystem so failover or manual boot never panics
echo -e "${GREEN}[4b/7] Populating Slot B with factory baseline root filesystem...${NC}"
TARGET_MNT_B="/mnt/micron_target_b"
mkdir -p "$TARGET_MNT_B"
mount "$P_ROOT_B" "$TARGET_MNT_B"
rsync -aAX --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} / "$TARGET_MNT_B/"
mkdir -p "$TARGET_MNT_B/boot/efi"
mkdir -p "$TARGET_MNT_B/var/lib/micron"
mkdir -p "$TARGET_MNT_B/mnt/micron-slot-b"

# Setup target bind mounts for chroot
mount --bind /dev "$TARGET_MNT/dev"
mount --bind /dev/pts "$TARGET_MNT/dev/pts"
mount --bind /proc "$TARGET_MNT/proc"
mount --bind /sys "$TARGET_MNT/sys"

echo -e "${GREEN}[5/7] Generating /etc/fstab...${NC}"
ROOT_A_UUID=$(blkid -s UUID -o value "$P_ROOT_A")
ROOT_B_UUID=$(blkid -s UUID -o value "$P_ROOT_B")
EFI_UUID=$(blkid -s UUID -o value "$P_EFI")
SWAP_UUID=$(blkid -s UUID -o value "$P_SWAP")
DATA_UUID=$(blkid -s UUID -o value "$P_DATA")

# Slot B is listed so the updater can address it by UUID, but `noauto` keeps it
# unmounted at boot: an image being written into it must never be half-mounted by
# the running system.
cat << EOF > "$TARGET_MNT/etc/fstab"
# MicronOS Filesystem Table
# <file system>              <mount point>        <type>  <options>                            <dump> <pass>
UUID=${ROOT_A_UUID}          /                    ext4    defaults,noatime,errors=remount-ro   0      1
UUID=${EFI_UUID}             /boot/efi            vfat    umask=0077                           0      1
UUID=${SWAP_UUID}            none                 swap    sw                                   0      0
UUID=${DATA_UUID}            /var/lib/micron      ext4    defaults,noatime,barrier=1,commit=5,errors=remount-ro,nofail 0 2
UUID=${ROOT_B_UUID}          /mnt/micron-slot-b   ext4    noauto,nofail                        0      0
EOF

# Generate Slot B /etc/fstab so booting Slot B mounts the Slot B partition as root
cat << EOF > "$TARGET_MNT_B/etc/fstab"
# MicronOS Filesystem Table (Slot B)
# <file system>              <mount point>        <type>  <options>                            <dump> <pass>
UUID=${ROOT_B_UUID}          /                    ext4    defaults,noatime,errors=remount-ro   0      1
UUID=${EFI_UUID}             /boot/efi            vfat    umask=0077                           0      1
UUID=${SWAP_UUID}            none                 swap    sw                                   0      0
UUID=${DATA_UUID}            /var/lib/micron      ext4    defaults,noatime,barrier=1,commit=5,errors=remount-ro,nofail 0 2
UUID=${ROOT_A_UUID}          /mnt/micron-slot-b   ext4    noauto,nofail                        0      0
EOF

# Install healthcheck unit in Slot B as well so if it boots it stays verified
mkdir -p "$TARGET_MNT_B/usr/local/micron/installer"
mkdir -p "$TARGET_MNT_B/etc/systemd/system/multi-user.target.wants"
mkdir -p "$TARGET_MNT_B/etc/systemd/system"
install -m 0755 "${SCRIPT_DIR}/micron-slot"        "$TARGET_MNT_B/usr/local/micron/installer/micron-slot"
install -m 0755 "${SCRIPT_DIR}/micron-healthcheck" "$TARGET_MNT_B/usr/local/micron/installer/micron-healthcheck"
install -m 0644 "${SCRIPT_DIR}/micron-healthcheck.service" \
        "$TARGET_MNT_B/etc/systemd/system/micron-healthcheck.service"
ln -sf /etc/systemd/system/micron-healthcheck.service \
       "$TARGET_MNT_B/etc/systemd/system/multi-user.target.wants/micron-healthcheck.service"

sync
umount "$TARGET_MNT_B"
rmdir "$TARGET_MNT_B" 2>/dev/null || true

echo -e "${GREEN}[6/7] Installing the bootloader and A/B slot selector...${NC}"

# Lifecycle assets must be present next to this script; the live medium has to
# carry them. Fail loudly rather than install a slot with no way to verify it.
for asset in micron-slot micron-healthcheck micron-healthcheck.service grub.d/09_micron_slots; do
    if [ ! -f "${SCRIPT_DIR}/${asset}" ]; then
        echo -e "${RED}Error: required installer asset '${asset}' is missing from ${SCRIPT_DIR}.${NC}"
        exit 1
    fi
done

# The slot selector becomes /etc/grub.d/09_micron_slots, so it sorts before
# 10_linux and its menuentry is the default one.
install -m 0755 "${SCRIPT_DIR}/grub.d/09_micron_slots" "$TARGET_MNT/etc/grub.d/09_micron_slots"

# Same kernel menuentry both slots: keep the distribution entries as a rescue
# path, but make the slot selector the default.
cat >> "$TARGET_MNT/etc/default/grub" << 'EOF'

# MicronOS — A/B boot slot selector (see /etc/grub.d/09_micron_slots)
GRUB_DEFAULT=0
GRUB_TIMEOUT=3
GRUB_TIMEOUT_STYLE=menu
GRUB_DISABLE_OS_PROBER=true
EOF

chroot "$TARGET_MNT" grub-install --target=x86_64-efi --efi-directory=/boot/efi --bootloader-id=MicronOS --recheck
chroot "$TARGET_MNT" update-grub

# Seed the slot environment the selector reads: slot A active and verified,
# and slot B verified as the golden factory root baseline.
MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" \
    bash "${SCRIPT_DIR}/micron-slot" init --force
MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" \
    bash "${SCRIPT_DIR}/micron-slot" mark-ok a
MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" \
    bash "${SCRIPT_DIR}/micron-slot" mark-ok b

# Install the slot helper, the health check and its unit into the target. The
# health-check service is enabled by writing the wants-symlink directly, which
# works offline and does not depend on systemd running in the chroot.
mkdir -p "$TARGET_MNT/usr/local/micron/installer"
mkdir -p "$TARGET_MNT/etc/systemd/system/multi-user.target.wants"
mkdir -p "$TARGET_MNT/etc/systemd/system"
install -m 0755 "${SCRIPT_DIR}/micron-slot"        "$TARGET_MNT/usr/local/micron/installer/micron-slot"
install -m 0755 "${SCRIPT_DIR}/micron-healthcheck" "$TARGET_MNT/usr/local/micron/installer/micron-healthcheck"
install -m 0644 "${SCRIPT_DIR}/micron-healthcheck.service" \
        "$TARGET_MNT/etc/systemd/system/micron-healthcheck.service"
ln -sf /etc/systemd/system/micron-healthcheck.service \
       "$TARGET_MNT/etc/systemd/system/multi-user.target.wants/micron-healthcheck.service"

# Configure systemd hardware watchdog defaults (30s ping, 60s reboot limit)
if [ -f "$TARGET_MNT/etc/systemd/system.conf" ]; then
    sed -i 's/^#*RuntimeWatchdogSec=.*/RuntimeWatchdogSec=30s/' "$TARGET_MNT/etc/systemd/system.conf" || true
    sed -i 's/^#*RebootWatchdogSec=.*/RebootWatchdogSec=60s/' "$TARGET_MNT/etc/systemd/system.conf" || true
fi

echo -e "${GREEN}[7/7] Initial Network Configuration Setup...${NC}"
echo "Available Network Interfaces:"
ip -br link show | grep -v "lo"

echo -n -e "\nEnter interface name for WAN (Internet Uplink) [e.g. eth0]: "
read -r WAN_IFACE
WAN_IFACE=${WAN_IFACE:-eth0}

echo -n -e "Enter interface name for LAN (Internal Network) [e.g. eth1]: "
read -r LAN_IFACE
LAN_IFACE=${LAN_IFACE:-eth1}

echo -n -e "Enter LAN IPv4 Address [192.168.1.1]: "
read -r LAN_IP
LAN_IP=${LAN_IP:-192.168.1.1}

# Configure default database records inside appliance
cat << EOF > "$TARGET_MNT/etc/micron/network_init.json"
{
  "wan_interface": "$WAN_IFACE",
  "lan_interface": "$LAN_IFACE",
  "lan_ip": "$LAN_IP"
}
EOF

# Cleanup mounts
umount "$TARGET_MNT/var/lib/micron" || true
umount "$TARGET_MNT/boot/efi"
umount "$TARGET_MNT/dev/pts"
umount "$TARGET_MNT/dev"
umount "$TARGET_MNT/proc"
umount "$TARGET_MNT/sys"
umount "$TARGET_MNT"

echo -e "\n${CYAN}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  MICRON FIREWALL OS (MicronOS) INSTALLATION COMPLETE!${NC}"
echo -e "${CYAN}═══════════════════════════════════════════════════════════════════${NC}"
echo -e "Web Management GUI: https://${LAN_IP}/"
echo -e "Default Username:   Ministrator"
echo -e "Default Password:   M1Cr0N"
echo -e "\nPlease remove the USB/CD installation media and reboot the machine."
echo -e "Reboot now? (y/n): "
read -r REBOOT_NOW
if [ "$REBOOT_NOW" = "y" ] || [ "$REBOOT_NOW" = "Y" ]; then
    reboot
fi

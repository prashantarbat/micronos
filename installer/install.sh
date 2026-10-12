#!/bin/bash
# ==============================================================================
# Micron Firewall OS (Micron OS) — Guided Appliance Bare-Metal Installer
# ==============================================================================
# Complete interactive installation wizard for bare-metal and virtual hardware:
# - Global keyboard layout (keymap) selection
# - Appliance timezone & regional clock selection (Default: India IST)
# - Interactive arrow-key disk selection menu with hardware details & safety checks
# - Storage disk discovery & A/B dual-slot partitioning strategy
# - Administrator & system credentials provisioning
# - Golden image deployment to Root Slot A & Standby Slot B
# - Dual-slot GRUB bootloader failover installation with UEFI removable fallback
# - NUM LOCK always ON across bootloader, initrd, TTY, and CLI
# ==============================================================================
set -e

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)

# Terminal Styling
BOLD='\033[1m'
NC='\033[0m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'

# ------------------------------------------------------------------------------
# Interrupt & Error Handlers
# ------------------------------------------------------------------------------
# Ctrl+C handler: restart the installation process from scratch
handle_interrupt() {
    tput cnorm 2>/dev/null || true
    echo -e "\n\n${YELLOW}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}  [!] Installation interrupted by user (Ctrl+C).${NC}"
    echo -e "${YELLOW}  Restarting Micron OS installation wizard from scratch...${NC}"
    echo -e "${YELLOW}══════════════════════════════════════════════════════════════════════${NC}\n"
    sleep 1.5
    exec "$0" "$@"
}
trap 'handle_interrupt' SIGINT

# Error handler: stay on the same screen, show the error, and provide recovery options
handle_error() {
    local exit_code=$?
    local line_no=$1
    tput cnorm 2>/dev/null || true
    echo -e "\n\n${RED}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}${BOLD}  INSTALLATION ERROR OCCURRED (Line: ${line_no}, Exit Code: ${exit_code})${NC}"
    echo -e "${RED}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}The installer encountered an issue and did not finish successfully.${NC}"
    echo -e "Review the log output above for the specific error cause.\n"
    echo -e "${BOLD}Select action:${NC}"
    echo "  1) Retry installation from scratch (Restart wizard)"
    echo "  2) Open Maintenance Shell (Bash) to inspect and fix manually"
    echo "  3) Reboot system"
    echo "  4) Power off system"
    echo ""
    while true; do
        read -r -p "Enter choice [1]: " ERR_ACT
        ERR_ACT=${ERR_ACT:-1}
        case "$ERR_ACT" in
            1)
                exec "$0" "$@"
                ;;
            2)
                echo -e "${CYAN}Dropping into maintenance shell. Type 'exit' to return to wizard.${NC}"
                /bin/bash -i
                exec "$0" "$@"
                ;;
            3)
                reboot
                exit 0
                ;;
            4)
                poweroff
                exit 0
                ;;
            *)
                echo "Invalid option. Please enter 1-4."
                ;;
        esac
    done
}
trap 'handle_error $LINENO' ERR

# Ensure NumLock is turned ON immediately at installer start
if command -v setleds >/dev/null 2>&1; then
    setleds -D +num 2>/dev/null || true
    for tty in /dev/tty[1-6] /dev/tty0 /dev/console; do
        if [ -e "$tty" ] && [ -r "$tty" ] && [ -w "$tty" ]; then
            { setleds -D +num < "$tty"; } 2>/dev/null || true
        fi
    done
fi

clear 2>/dev/null || true
echo -e "${CYAN}"
cat << 'BANNER'
┌╦═╦═╦┐ ┌═╤╦╤═┐ ┌╦═══╦┐ ┌╦═══╦┐ ┌╦═══╦┐ ┌╦═╗ ╦┐      ┌╦═══╦┐ ┌╦═══╦┐
│║ ║ ║│   │║│   │║      │╠══╦╩┘ │║   ║│ │║ ║ ║│      │║   ║│ └╩═══╦┐
└╩   ╩┘ └═╧╩╧═┘ └╩═══╩┘ └╩  ╚═┘ └╩═══╩┘ └╩ ╚═╩┘      └╩═══╩┘ └╩═══╩┘
 Enterprise Linux Firewall & Network Appliance — Installation Wizard
BANNER
echo -e "${NC}"

# Check for root privileges
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: This installer must be run as root.${NC}"
    exit 1
fi

# ------------------------------------------------------------------------------
# Kickstart / Automated Unattended Installation Engine
# ------------------------------------------------------------------------------
KICKSTART_MODE=0
KICKSTART_FILE=""

# Parse CLI arguments
while [ $# -gt 0 ]; do
    case "$1" in
        --kickstart|-k|--ks)
            KICKSTART_FILE="$2"
            shift 2
            ;;
        --kickstart=*|--ks=*)
            KICKSTART_FILE="${1#*=}"
            shift
            ;;
        --autoinstall|-y|--auto)
            KICKSTART_MODE=1
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo "  --kickstart, -k <file|URL>   Path or URL to Kickstart answer file"
            echo "  --autoinstall, -y, --auto    Run non-interactive automated installation"
            exit 0
            ;;
        *)
            shift
            ;;
    esac
done

# If not passed via CLI, look for kernel command line parameter (/proc/cmdline)
if [ -z "$KICKSTART_FILE" ] && [ -r /proc/cmdline ]; then
    CMDLINE_KS=$(grep -o -E 'micron\.(kickstart|ks)=[^ ]+' /proc/cmdline 2>/dev/null | cut -d= -f2- || true)
    if [ -n "$CMDLINE_KS" ]; then
        KICKSTART_FILE="$CMDLINE_KS"
    elif grep -q "micron.autoinstall" /proc/cmdline 2>/dev/null; then
        KICKSTART_MODE=1
    fi
fi

# If still not specified, scan well-known search paths for Kickstart answer files
if [ -z "$KICKSTART_FILE" ]; then
    for ks_candidate in \
        /run/live/medium/micron-kickstart.cfg \
        /run/live/medium/kickstart.cfg \
        /run/live/medium/ks.cfg \
        /etc/micron/kickstart.cfg \
        /micron-kickstart.cfg; do
        if [ -f "$ks_candidate" ]; then
            KICKSTART_FILE="$ks_candidate"
            break
        fi
    done
fi

# Also check attached disk partitions with volume label OEMDRV or MICRON_KS
if [ -z "$KICKSTART_FILE" ]; then
    for oem_dev in $(blkid -L "OEMDRV" 2>/dev/null || true) $(blkid -L "MICRON_KS" 2>/dev/null || true); do
        if [ -b "$oem_dev" ]; then
            mkdir -p /mnt/micron_oem_ks
            if mount -o ro "$oem_dev" /mnt/micron_oem_ks 2>/dev/null; then
                for f in micron-kickstart.cfg kickstart.cfg ks.cfg; do
                    if [ -f "/mnt/micron_oem_ks/$f" ]; then
                        cp "/mnt/micron_oem_ks/$f" /tmp/micron-kickstart.cfg
                        KICKSTART_FILE="/tmp/micron-kickstart.cfg"
                        break
                    fi
                done
                umount /mnt/micron_oem_ks 2>/dev/null || true
                [ -n "$KICKSTART_FILE" ] && break
            fi
        fi
    done
fi

# Fetch remote Kickstart file if URL is provided (PXE / HTTP / TFTP)
if [ -n "$KICKSTART_FILE" ] && { [[ "$KICKSTART_FILE" =~ ^https?:// ]] || [[ "$KICKSTART_FILE" =~ ^ftp:// ]]; }; then
    echo -e "${CYAN}Fetching remote Kickstart file from: ${KICKSTART_FILE}...${NC}"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$KICKSTART_FILE" -o /tmp/micron-kickstart.cfg 2>/dev/null || true
    elif command -v wget >/dev/null 2>&1; then
        wget -q "$KICKSTART_FILE" -O /tmp/micron-kickstart.cfg 2>/dev/null || true
    fi
    if [ -f /tmp/micron-kickstart.cfg ]; then
        KICKSTART_FILE="/tmp/micron-kickstart.cfg"
    else
        echo -e "${YELLOW}Warning: Failed to fetch Kickstart URL. Reverting to interactive mode.${NC}"
        KICKSTART_FILE=""
    fi
fi

# Load Kickstart parameters if file found
if [ -n "$KICKSTART_FILE" ] && [ -f "$KICKSTART_FILE" ]; then
    KICKSTART_MODE=1
    echo -e "${GREEN}[Kickstart] Loading unattended configuration: ${KICKSTART_FILE}${NC}"
    # shellcheck disable=SC1090
    . "$KICKSTART_FILE"
fi

if [ "$KICKSTART_MODE" -eq 1 ]; then
    echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${BOLD}${GREEN}  MICRON OS AUTOMATED KICKSTART INSTALLATION ACTIVE${NC}"
    echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}\n"
fi

# ------------------------------------------------------------------------------
# Step 1: Global Keyboard Layout Selection
# ------------------------------------------------------------------------------
echo -e "${BOLD}[Step 1/5] Global Keyboard Layout Selection${NC}"
if [ "$KICKSTART_MODE" -eq 1 ]; then
    KEYMAP=${KEYMAP:-us}
    loadkeys "$KEYMAP" 2>/dev/null || true
    echo -e "${GREEN}[Kickstart] Active keyboard layout set to: ${KEYMAP}${NC}\n"
else
    echo "Select international keymap:"
    echo "  1) US English / International QWERTY (us - Default)"
    echo "  2) UK English (uk)"
    echo "  3) German QWERTZ (de)"
    echo "  4) French AZERTY (fr)"
    echo "  5) Spanish (es)"
    echo "  6) Italian (it)"
    echo "  7) Portuguese / Brazilian (br / pt)"
    echo "  8) Japanese (jp)"
    echo "  9) Polish (pl)"
    echo " 10) Russian (ru)"
    echo " 11) Nordic (se / no / dk / fi)"
    echo " 12) Browse / Enter custom keymap code"
    read -r -p "Select keyboard layout [1]: " KBD_CHOICE
    case "$KBD_CHOICE" in
        2) KEYMAP="uk" ;;
        3) KEYMAP="de" ;;
        4) KEYMAP="fr" ;;
        5) KEYMAP="es" ;;
        6) KEYMAP="it" ;;
        7) KEYMAP="br" ;;
        8) KEYMAP="jp" ;;
        9) KEYMAP="pl" ;;
        10) KEYMAP="ru" ;;
        11) KEYMAP="se" ;;
        12) read -r -p "Enter custom keymap code (e.g. ca, nl, ch, dvorak): " KEYMAP ;;
        *) KEYMAP="us" ;;
    esac
    KEYMAP=${KEYMAP:-us}
    loadkeys "$KEYMAP" 2>/dev/null || true
    echo -e "${GREEN}[OK] Active keyboard layout set to: ${KEYMAP}${NC}\n"
fi

# ------------------------------------------------------------------------------
# Step 2: System Timezone Selection (Default to India IST)
# ------------------------------------------------------------------------------
echo -e "${BOLD}[Step 2/5] System Timezone Selection${NC}"
if [ "$KICKSTART_MODE" -eq 1 ]; then
    TIMEZONE=${TIMEZONE:-Asia/Kolkata}
    timedatectl set-timezone "$TIMEZONE" 2>/dev/null || true
    echo -e "${GREEN}[Kickstart] System timezone configured: ${TIMEZONE}${NC}\n"
else
    echo "Select timezone:"
    echo "  1) Universal Coordinated Time (UTC - Recommended for Firewalls & SIEM)"
    echo "  2) India (IST) — Asia/Kolkata (Default)"
    echo "  3) Manual selection (full tzdata / international list)"
    read -r -p "Select timezone [2]: " TZ_CHOICE
    TZ_CHOICE=${TZ_CHOICE:-2}

    case "$TZ_CHOICE" in
        1)
            TIMEZONE="UTC"
            ;;
        2)
            TIMEZONE="Asia/Kolkata"
            ;;
        3)
            echo -e "\n  International Regions:"
            echo "    1) Americas (North, Central & South America)"
            echo "    2) Europe (Western, Central & Eastern)"
            echo "    3) Asia & Oceania (Asia-Pacific, Japan, Australia)"
            echo "    4) Africa & Middle East"
            echo "    5) Enter custom Olson timezone name directly"
            read -r -p "    Select category [1]: " TZ_CAT
            TZ_CAT=${TZ_CAT:-1}
            case "$TZ_CAT" in
                1)
                    echo "    Americas Timezones:"
                    echo "      1) America/New_York (US Eastern)      5) America/Toronto (Canada)"
                    echo "      2) America/Chicago (US Central)       6) America/Sao_Paulo (Brazil)"
                    echo "      3) America/Denver (US Mountain)       7) America/Mexico_City (Mexico)"
                    echo "      4) America/Los_Angeles (US Pacific)   8) America/Buenos_Aires (Argentina)"
                    read -r -p "      Select [1]: " AM_TZ
                    case "$AM_TZ" in
                        2) TIMEZONE="America/Chicago" ;;
                        3) TIMEZONE="America/Denver" ;;
                        4) TIMEZONE="America/Los_Angeles" ;;
                        5) TIMEZONE="America/Toronto" ;;
                        6) TIMEZONE="America/Sao_Paulo" ;;
                        7) TIMEZONE="America/Mexico_City" ;;
                        8) TIMEZONE="America/Buenos_Aires" ;;
                        *) TIMEZONE="America/New_York" ;;
                    esac
                    ;;
                2)
                    echo "    Europe Timezones:"
                    echo "      1) Europe/London (GMT/BST)            5) Europe/Amsterdam (CET)"
                    echo "      2) Europe/Berlin (CET/CEST)           6) Europe/Warsaw (Poland)"
                    echo "      3) Europe/Paris (France)              7) Europe/Kyiv (Ukraine)"
                    echo "      4) Europe/Madrid (Spain)              8) Europe/Athens (EET)"
                    read -r -p "      Select [1]: " EU_TZ
                    case "$EU_TZ" in
                        2) TIMEZONE="Europe/Berlin" ;;
                        3) TIMEZONE="Europe/Paris" ;;
                        4) TIMEZONE="Europe/Madrid" ;;
                        5) TIMEZONE="Europe/Amsterdam" ;;
                        6) TIMEZONE="Europe/Warsaw" ;;
                        7) TIMEZONE="Europe/Kyiv" ;;
                        8) TIMEZONE="Europe/Athens" ;;
                        *) TIMEZONE="Europe/London" ;;
                    esac
                    ;;
                3)
                    echo "    Asia & Oceania Timezones:"
                    echo "      1) Asia/Kolkata (India Standard Time) 5) Asia/Shanghai (China CST)"
                    echo "      2) Asia/Tokyo (Japan JST)             6) Asia/Hong_Kong (HKT)"
                    echo "      3) Asia/Singapore (SGT)               7) Asia/Seoul (Korea KST)"
                    echo "      4) Australia/Sydney (AEST/AEDT)       8) Pacific/Auckland (New Zealand)"
                    read -r -p "      Select [1]: " AS_TZ
                    case "$AS_TZ" in
                        2) TIMEZONE="Asia/Tokyo" ;;
                        3) TIMEZONE="Asia/Singapore" ;;
                        4) TIMEZONE="Australia/Sydney" ;;
                        5) TIMEZONE="Asia/Shanghai" ;;
                        6) TIMEZONE="Asia/Hong_Kong" ;;
                        7) TIMEZONE="Asia/Seoul" ;;
                        8) TIMEZONE="Pacific/Auckland" ;;
                        *) TIMEZONE="Asia/Kolkata" ;;
                    esac
                    ;;
                4)
                    echo "    Africa & Middle East Timezones:"
                    echo "      1) Asia/Dubai (Gulf GST)              4) Africa/Lagos (West Africa WAT)"
                    echo "      2) Africa/Johannesburg (SAST)         5) Asia/Riyadh (Saudi Arabia AST)"
                    echo "      3) Africa/Cairo (Egypt EET)           6) Africa/Nairobi (East Africa EAT)"
                    read -r -p "      Select [1]: " ME_TZ
                    case "$ME_TZ" in
                        2) TIMEZONE="Africa/Johannesburg" ;;
                        3) TIMEZONE="Africa/Cairo" ;;
                        4) TIMEZONE="Africa/Lagos" ;;
                        5) TIMEZONE="Asia/Riyadh" ;;
                        6) TIMEZONE="Africa/Nairobi" ;;
                        *) TIMEZONE="Asia/Dubai" ;;
                    esac
                    ;;
                5)
                    read -r -p "    Enter Olson timezone (e.g. Pacific/Honolulu, Europe/Zurich): " TIMEZONE
                    ;;
                *)
                    TIMEZONE="Asia/Kolkata"
                    ;;
            esac
            ;;
        *)
            TIMEZONE="Asia/Kolkata"
            ;;
    esac

    TIMEZONE=${TIMEZONE:-Asia/Kolkata}
    timedatectl set-timezone "$TIMEZONE" 2>/dev/null || true
    echo -e "${GREEN}[OK] System timezone configured: ${TIMEZONE}${NC}\n"
fi

# ------------------------------------------------------------------------------
# Step 3: Storage Partitioning Strategy & Target Disk Selection
# ------------------------------------------------------------------------------
# ------------------------------------------------------------------------------
# Step 3: Storage Partitioning Strategy & Target Disk Selection
# ------------------------------------------------------------------------------
echo -e "${BOLD}[Step 3/5] Storage & Partitioning Strategy${NC}"
if [ "$KICKSTART_MODE" -eq 1 ]; then
    PART_STRATEGY=${PART_STRATEGY:-1}
    SLOT_SIZE=${SLOT_SIZE:-10G}
    echo -e "${GREEN}[Kickstart] Partitioning Strategy: Dual Slot A/B (Slot size: ${SLOT_SIZE})${NC}"
else
    echo "Partitioning Profiles:"
    echo "  1) Guided A/B Dual Slot Appliance (Recommended)"
    echo "     • 512 MB EFI System Partition (ESP)"
    echo "     • 4 GB Swap space"
    echo "     • 10 GB Root Slot A (Active OS)"
    echo "     • 10 GB Root Slot B (Standby Failover / OTA Upgrade Slot)"
    echo "     • Remainder allocated to /var/lib/micron (Persistent Data & DB)"
    echo "  2) Custom Slot Sizing (Specify custom Root Slot A/B partition size)"
    read -r -p "Select partitioning strategy [1]: " PART_STRATEGY
    PART_STRATEGY=${PART_STRATEGY:-1}

    SLOT_SIZE="10G"
    if [ "$PART_STRATEGY" = "2" ]; then
        read -r -p "Enter partition size for each Root Slot (e.g. 8G, 12G, 16G, 20G) [10G]: " CUSTOM_SLOT_SIZE
        SLOT_SIZE=${CUSTOM_SLOT_SIZE:-10G}
    fi
fi

case "$SLOT_SIZE" in
    *[Gg]) SLOT_SIZE_MIB=$(( ${SLOT_SIZE%[Gg]} * 1024 )) ;;
    *[Mm]) SLOT_SIZE_MIB=${SLOT_SIZE%[Mm]} ;;
    *[Tt]) SLOT_SIZE_MIB=$(( ${SLOT_SIZE%[Tt]} * 1048576 )) ;;
    *)     SLOT_SIZE_MIB=$(( SLOT_SIZE / 1048576 )) ;;
esac
REQUIRED_MIB=$((512 + 4096 + (2 * SLOT_SIZE_MIB) + 2048))

# ------------------------------------------------------------------------------
# Automated Kickstart Disk Selection Engine
# ------------------------------------------------------------------------------
select_target_disk_kickstart() {
    local live_dev=""
    live_dev=$(findmnt -n -o SOURCE /run/live/medium 2>/dev/null || true)
    if [ -n "$live_dev" ]; then
        live_dev=$(lsblk -no PKNAME "$live_dev" 2>/dev/null || echo "$live_dev")
        live_dev=$(basename "$live_dev")
    fi

    if [ -n "$TARGET_DISK" ] && [ "$TARGET_DISK" != "auto" ] && [ "$TARGET_DISK" != "first" ]; then
        [[ "$TARGET_DISK" != /dev/* ]] && TARGET_DISK="/dev/$TARGET_DISK"
        if [ -b "$TARGET_DISK" ]; then
            TARGET_DEV_NAME=$(basename "$TARGET_DISK")
            echo -e "${GREEN}[Kickstart] Target disk specified: ${TARGET_DISK}${NC}"
            return 0
        else
            echo -e "${RED}[Kickstart Error] Target disk ${TARGET_DISK} does not exist!${NC}"
            exit 1
        fi
    fi

    local candidate=""
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        local name size type model tran rota rm ro
        read -r name size type model tran rota rm ro <<< "$line"

        [[ "$name" =~ ^(loop|ram|sr|zram) ]] && continue
        [ "$ro" = "1" ] && continue
        [ -n "$live_dev" ] && [ "$name" = "$live_dev" ] && continue

        local b_bytes
        b_bytes=$(blockdev --getsize64 "/dev/$name" 2>/dev/null || echo 0)
        local sel_mib=$((b_bytes / 1048576))
        if [ "$sel_mib" -ge "$REQUIRED_MIB" ]; then
            candidate="/dev/$name"
            TARGET_DEV_NAME="$name"
            break
        fi
    done < <(lsblk -d -n -o NAME,SIZE,TYPE,MODEL,TRAN,ROTA,RM,RO 2>/dev/null || true)

    if [ -n "$candidate" ]; then
        TARGET_DISK="$candidate"
        echo -e "${GREEN}[Kickstart] Automatically selected target drive: ${TARGET_DISK}${NC}"
    else
        echo -e "${RED}[Kickstart Error] No suitable storage drive with at least ${REQUIRED_MIB} MiB found!${NC}"
        exit 1
    fi
}

# ------------------------------------------------------------------------------
# Interactive Disk Selection Menu
# ------------------------------------------------------------------------------
select_target_disk_interactive() {
    local live_dev=""
    live_dev=$(findmnt -n -o SOURCE /run/live/medium 2>/dev/null || true)
    if [ -n "$live_dev" ]; then
        live_dev=$(lsblk -no PKNAME "$live_dev" 2>/dev/null || echo "$live_dev")
        live_dev=$(basename "$live_dev")
    fi

    local dev_names=()
    local dev_sizes=()
    local dev_models=()
    local dev_types=()
    local dev_bytes=()
    local dev_data_flags=()

    local internal_disks=()
    local usb_disks=()

    while IFS= read -r line; do
        [ -z "$line" ] && continue
        local name size type model tran rota rm ro
        read -r name size type model tran rota rm ro <<< "$line"
        
        # Exclude loop, ram, optical, and read-only media
        [[ "$name" =~ ^(loop|ram|sr|zram) ]] && continue
        [ "$ro" = "1" ] && continue
        [ -n "$live_dev" ] && [ "$name" = "$live_dev" ] && continue

        # Model cleanup
        [ -z "$model" ] || [ "$model" = "-" ] && model="Storage Drive"

        # Device classification
        local disk_type="SATA HDD"
        if [[ "$name" =~ ^nvme ]]; then
            disk_type="NVMe SSD"
        elif [ "$tran" = "usb" ] || [ "$rm" = "1" ]; then
            disk_type="USB"
        elif [[ "$model" =~ (Virtual|QEMU|VBOX|VMware) ]] || [[ "$name" =~ ^vd ]]; then
            disk_type="Virtual Disk"
        elif [ "$rota" = "0" ]; then
            disk_type="SATA SSD"
        fi

        local b_bytes
        b_bytes=$(blockdev --getsize64 "/dev/$name" 2>/dev/null || echo 0)

        local has_data=0
        local part_cnt
        part_cnt=$(lsblk -n "/dev/$name" 2>/dev/null | wc -l || echo 1)
        if [ "$part_cnt" -gt 1 ] || blkid "/dev/$name"* >/dev/null 2>&1; then
            has_data=1
        fi

        local entry="${name}|${size}|${model}|${disk_type}|${b_bytes}|${has_data}"
        if [ "$disk_type" = "USB" ]; then
            usb_disks+=("$entry")
        else
            internal_disks+=("$entry")
        fi
    done < <(lsblk -d -n -o NAME,SIZE,TYPE,MODEL,TRAN,ROTA,RM,RO 2>/dev/null || true)

    local all_disks=("${internal_disks[@]}" "${usb_disks[@]}")
    for item in "${all_disks[@]}"; do
        IFS='|' read -r d_n d_s d_m d_t d_b d_h <<< "$item"
        dev_names+=("$d_n")
        dev_sizes+=("$d_s")
        dev_models+=("$d_m")
        dev_types+=("$d_t")
        dev_bytes+=("$d_b")
        dev_data_flags+=("$d_h")
    done

    local count=${#dev_names[@]}
    if [ "$count" -eq 0 ]; then
        echo -e "\n${RED}Error: No suitable writable hard disks or SSDs found!${NC}"
        echo "Please connect a storage drive and press Enter to retry."
        read -r -p "Press Enter to re-scan..."
        select_target_disk_interactive
        return
    fi

    local selected=0
    local key=""
    local rest=""

    # Interactive Arrow-Key / Number Selection Loop
    tput civis 2>/dev/null || printf "\033[?25l"

    while true; do
        clear 2>/dev/null || true
        echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
        echo -e "${BOLD}Select target disk for Micron OS installation:${NC}"
        echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}\n"

        for ((i=0; i<count; i++)); do
            local dn="${dev_names[i]}"
            local ds="${dev_sizes[i]}"
            local dm="${dev_models[i]}"
            local dt="${dev_types[i]}"
            local num=$((i + 1))

            if [ "$i" -eq "$selected" ]; then
                printf "  ${CYAN}${BOLD}> %d) %-7s %-8s %-25s (%s)${NC}\n" "$num" "$dn" "$ds" "$dm" "$dt"
            else
                printf "    %d) %-7s %-8s %-25s (%s)\n" "$num" "$dn" "$ds" "$dm" "$dt"
            fi
        done

        echo ""
        echo -e "${CYAN}  [Up/Down] Select   [1-${count}] Direct Jump   [Enter] Confirm Selection${NC}\n"

        IFS= read -rsn1 key
        if [[ "$key" == $'\x1b' ]]; then
            read -rsn2 -t 1 rest || true
            if [[ "$rest" == "[A" || "$rest" == "OA" ]]; then
                ((selected--))
                [ "$selected" -lt 0 ] && selected=$((count - 1))
            elif [[ "$rest" == "[B" || "$rest" == "OB" ]]; then
                ((selected++))
                [ "$selected" -ge "$count" ] && selected=0
            fi
        elif [[ "$key" == "" ]]; then
            break
        elif [[ "$key" =~ ^[1-9]$ ]]; then
            local target_idx=$((key - 1))
            if [ "$target_idx" -ge 0 ] && [ "$target_idx" -lt "$count" ]; then
                selected=$target_idx
                break
            fi
        elif [[ "$key" == "k" || "$key" == "K" ]]; then
            ((selected--))
            [ "$selected" -lt 0 ] && selected=$((count - 1))
        elif [[ "$key" == "j" || "$key" == "J" ]]; then
            ((selected++))
            [ "$selected" -ge "$count" ] && selected=0
        fi
    done

    tput cnorm 2>/dev/null || printf "\033[?25h"

    TARGET_DEV_NAME="${dev_names[selected]}"
    TARGET_DISK="/dev/${TARGET_DEV_NAME}"
    local sel_bytes="${dev_bytes[selected]}"
    local sel_has_data="${dev_data_flags[selected]}"
    local sel_mib=$((sel_bytes / 1048576))

    echo -e "${GREEN}[Selected Disk] ${TARGET_DISK} (${dev_sizes[selected]}, ${dev_models[selected]}, ${dev_types[selected]})${NC}"

    # Minimum size validation
    if [ "$sel_mib" -gt 0 ] && [ "$sel_mib" -lt "$REQUIRED_MIB" ]; then
        echo -e "\n${RED}Error: ${TARGET_DISK} has only ${sel_mib} MiB.${NC}"
        echo -e "${RED}The A/B dual-slot layout requires at least ${REQUIRED_MIB} MiB (~$((REQUIRED_MIB / 1024)) GB).${NC}"
        read -r -p "Press Enter to return to disk selection..."
        select_target_disk_interactive
        return
    fi

    # Existing data warning & double confirmation
    if [ "$sel_has_data" -eq 1 ]; then
        echo -e "\n${YELLOW}══════════════════════════════════════════════════════════════════════${NC}"
        echo -e "${YELLOW}WARNING: Existing partitions and filesystems detected on ${TARGET_DISK}!${NC}"
        echo -e "${YELLOW}All existing data, partitions, and operating systems will be destroyed!${NC}"
        echo -e "${YELLOW}══════════════════════════════════════════════════════════════════════${NC}"
        read -r -p "Are you sure you want to completely erase ${TARGET_DISK}? [Y/n]: " CONFIRM_1
        CONFIRM_1=${CONFIRM_1:-Y}
        if [[ "$CONFIRM_1" =~ ^[nN] ]]; then
            echo "Selection cancelled."
            select_target_disk_interactive
            return
        fi
    fi

    echo -e "\n${RED}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}FINAL CONFIRMATION: ALL DATA ON ${TARGET_DISK} WILL BE PERMANENTLY ERASED!${NC}"
    echo -e "${RED}══════════════════════════════════════════════════════════════════════${NC}"
    read -r -p "Proceed with partitioning and installation? [Y/n]: " CONFIRM_2
    CONFIRM_2=${CONFIRM_2:-Y}
    if [[ "$CONFIRM_2" =~ ^[nN] ]]; then
        echo "Installation aborted."
        select_target_disk_interactive
        return
    fi
}

if [ "$KICKSTART_MODE" -eq 1 ]; then
    select_target_disk_kickstart
else
    select_target_disk_interactive
fi

# ------------------------------------------------------------------------------
# Step 4: Administrator & Console Credentials
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}[Step 4/5] Appliance Administrative Credentials${NC}"
if [ "$KICKSTART_MODE" -eq 1 ]; then
    ADMIN_PW=${ADMIN_PW:-M1Cr0N}
    echo -e "${GREEN}[Kickstart] Administrative password configured.${NC}\n"
else
    echo "Unified credentials for Web GUI ('Ministrator') and OS ('root', 'ministrator')."
    read -r -s -p "Enter appliance administrative password [M1Cr0N]: " ADMIN_PW
    echo ""
    ADMIN_PW=${ADMIN_PW:-M1Cr0N}
    echo -e "${GREEN}[OK] Administrative password configured.${NC}\n"
fi

# ------------------------------------------------------------------------------
# Step 5: Disk Partitioning, Image Deployment & Bootloader Setup
# ------------------------------------------------------------------------------
echo -e "${BOLD}[Step 5/5] Partitioning, Formatting and Image Deployment...${NC}"

# Preflight: the A/B boot chain depends on these assets, and a live medium built
# without them must not leave a wiped disk behind. Check before touching the disk.
for asset in micron-slot micron-healthcheck micron-healthcheck.service grub.d/09_micron_slots; do
    if [ ! -f "${SCRIPT_DIR}/${asset}" ]; then
        echo -e "${RED}Error: required installer asset '${asset}' is missing from ${SCRIPT_DIR}.${NC}"
        exit 1
    fi
done

# Release any active locks, swaps, or mounts on target disk
echo "Releasing existing device mounts and swap locks..."
swapoff -a 2>/dev/null || true
umount -R "$TARGET_DISK"* 2>/dev/null || true
umount -R /mnt/micron_target* 2>/dev/null || true

echo "Writing GPT partition table to ${TARGET_DISK}..."
wipefs -a "$TARGET_DISK" || true
sgdisk -Z "$TARGET_DISK"
sgdisk -n 1:2048:+512M -t 1:ef00 -c 1:"EFI System Partition" "$TARGET_DISK"
sgdisk -n 2:0:+4G -t 2:8200 -c 2:"MicronOS Swap" "$TARGET_DISK"
sgdisk -n 3:0:+"${SLOT_SIZE}" -t 3:8300 -c 3:"MicronOS Root Slot A" "$TARGET_DISK"
sgdisk -n 4:0:+"${SLOT_SIZE}" -t 4:8300 -c 4:"MicronOS Root Slot B" "$TARGET_DISK"
sgdisk -n 5:0:0 -t 5:8300 -c 5:"MicronOS Persistent Data" "$TARGET_DISK"
sync
partprobe "$TARGET_DISK" || true
udevadm settle 2>/dev/null || sleep 2

# Resolve partition identifiers (handling nvme0n1p1 vs sda1)
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

# Ensure device nodes are ready
for _ in 1 2 3 4 5; do
    [ -b "$P_ROOT_A" ] && break
    sleep 1
    partprobe "$TARGET_DISK" 2>/dev/null || true
    udevadm settle 2>/dev/null || true
done

echo "Formatting filesystems..."
mkfs.vfat -F32 -n "EFI" "$P_EFI"
mkswap -L "SWAP" "$P_SWAP"
mkfs.ext4 -F -L "MICRON_ROOT_A" "$P_ROOT_A"
mkfs.ext4 -F -L "MICRON_ROOT_B" "$P_ROOT_B"
mkfs.ext4 -F -L "MICRON_DATA" "$P_DATA"
echo -e "${GREEN}[OK] Partitions and filesystems prepared.${NC}\n"

TARGET_MNT="/mnt/micron_target"
mkdir -p "$TARGET_MNT"
mount "$P_ROOT_A" "$TARGET_MNT"
mkdir -p "$TARGET_MNT/boot/efi"
mount "$P_EFI" "$TARGET_MNT/boot/efi"
mkdir -p "$TARGET_MNT/var/lib/micron"
mount "$P_DATA" "$TARGET_MNT/var/lib/micron"
mkdir -p "$TARGET_MNT/mnt/micron-slot-b"

echo "Extracting live root filesystem to Slot A..."
rsync -aAX --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} / "$TARGET_MNT/"

echo "Synchronizing factory baseline to Standby Slot B..."
TARGET_MNT_B="/mnt/micron_target_b"
mkdir -p "$TARGET_MNT_B"
mount "$P_ROOT_B" "$TARGET_MNT_B"
rsync -aAX --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} / "$TARGET_MNT_B/"
mkdir -p "$TARGET_MNT_B/boot/efi"
mkdir -p "$TARGET_MNT_B/var/lib/micron"
mkdir -p "$TARGET_MNT_B/mnt/micron-slot-a"

# UUID discovery
ROOT_A_UUID=$(blkid -s UUID -o value "$P_ROOT_A")
ROOT_B_UUID=$(blkid -s UUID -o value "$P_ROOT_B")
EFI_UUID=$(blkid -s UUID -o value "$P_EFI")
SWAP_UUID=$(blkid -s UUID -o value "$P_SWAP")
DATA_UUID=$(blkid -s UUID -o value "$P_DATA")

# Generate fstab for Slot A
cat << EOF > "$TARGET_MNT/etc/fstab"
# Micron OS Filesystem Table (Slot A)
UUID=${ROOT_A_UUID}          /                    ext4    defaults,noatime,errors=remount-ro   0      1
UUID=${EFI_UUID}             /boot/efi            vfat    umask=0077                           0      1
UUID=${SWAP_UUID}            none                 swap    sw                                   0      0
UUID=${DATA_UUID}            /var/lib/micron      ext4    defaults,noatime,barrier=1,commit=5,errors=remount-ro 0 2
UUID=${ROOT_B_UUID}          /mnt/micron-slot-b   ext4    noauto,nofail                        0      0
EOF

# Generate fstab for Slot B
cat << EOF > "$TARGET_MNT_B/etc/fstab"
# Micron OS Filesystem Table (Slot B)
UUID=${ROOT_B_UUID}          /                    ext4    defaults,noatime,errors=remount-ro   0      1
UUID=${EFI_UUID}             /boot/efi            vfat    umask=0077                           0      1
UUID=${SWAP_UUID}            none                 swap    sw                                   0      0
UUID=${DATA_UUID}            /var/lib/micron      ext4    defaults,noatime,barrier=1,commit=5,errors=remount-ro 0 2
UUID=${ROOT_A_UUID}          /mnt/micron-slot-a   ext4    noauto,nofail                        0      0
EOF

# Apply Localization, NumLock & Base System Configuration to both slots
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    mkdir -p "$mnt/etc/default" "$mnt/etc/profile.d" "$mnt/etc/systemd/system" "$mnt/etc/systemd/system/multi-user.target.wants"

    # Keyboard layout
    cat << EOF > "$mnt/etc/default/keyboard"
XKBMODEL="pc105"
XKBLAYOUT="$KEYMAP"
XKBVARIANT=""
XKBOPTIONS=""
EOF

    # Timezone & Clock
    echo "$TIMEZONE" > "$mnt/etc/timezone"
    ln -sf "/usr/share/zoneinfo/$TIMEZONE" "$mnt/etc/localtime" 2>/dev/null || true
    hwclock --systohc 2>/dev/null || true

    # NUM LOCK 5.2: GRUB console input
    if [ -f "$mnt/etc/default/grub" ]; then
        grep -q 'GRUB_TERMINAL_INPUT=' "$mnt/etc/default/grub" || echo 'GRUB_TERMINAL_INPUT="console"' >> "$mnt/etc/default/grub"
    fi

    # A/B boot slot selector (/etc/grub.d/09_micron_slots) must be the default entry
    mkdir -p "$mnt/etc/default"
    sed -i '/^GRUB_DEFAULT=/d;/^GRUB_TIMEOUT=/d;/^GRUB_TIMEOUT_STYLE=/d;/^GRUB_DISABLE_OS_PROBER=/d' "$mnt/etc/default/grub" 2>/dev/null || true
    cat >> "$mnt/etc/default/grub" << 'EOF'

# Micron OS — A/B boot slot selector (see /etc/grub.d/09_micron_slots)
GRUB_DEFAULT=0
GRUB_TIMEOUT=3
GRUB_TIMEOUT_STYLE=menu
GRUB_DISABLE_OS_PROBER=true
EOF

    # systemd hardware watchdog defaults (30s ping, 60s reboot limit)
    if [ -f "$mnt/etc/systemd/system.conf" ]; then
        sed -i 's/^#*RuntimeWatchdogSec=.*/RuntimeWatchdogSec=30s/' "$mnt/etc/systemd/system.conf" || true
        sed -i 's/^#*RebootWatchdogSec=.*/RebootWatchdogSec=60s/' "$mnt/etc/systemd/system.conf" || true
    fi

    # NUM LOCK 5.4: Console / TTY system-wide startup script
    cat << 'EOF' > "$mnt/etc/profile.d/numlock.sh"
#!/bin/sh
# Assert NumLock ON across all virtual consoles
if command -v setleds >/dev/null 2>&1; then
    setleds -D +num 2>/dev/null || true
    if [ "${EUID:-$(id -u)}" -eq 0 ]; then
        for tty in /dev/tty[1-6] /dev/tty0 /dev/console; do
            if [ -e "$tty" ] && [ -r "$tty" ] && [ -w "$tty" ]; then
                { setleds -D +num < "$tty"; } 2>/dev/null || true
            fi
        done
    fi
fi
EOF
    chmod 0755 "$mnt/etc/profile.d/numlock.sh"

    # NUM LOCK 5.4: systemd service to enforce NumLock at boot
    cat << 'EOF' > "$mnt/etc/systemd/system/numlock.service"
[Unit]
Description=Enable NumLock on Virtual Consoles
DefaultDependencies=no
After=systemd-vconsole-setup.service console-setup.service
Before=getty.target

[Service]
Type=oneshot
ExecStart=/bin/sh -c 'for tty in /dev/tty[1-6] /dev/tty0 /dev/console; do [ -e "$$tty" ] && /usr/bin/setleds -D +num < "$$tty" >/dev/null 2>&1 || true; done'
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
    ln -sf /etc/systemd/system/numlock.service "$mnt/etc/systemd/system/multi-user.target.wants/numlock.service"

    # NUM LOCK 5.3: initramfs hook and script
    mkdir -p "$mnt/etc/initramfs-tools/hooks" "$mnt/etc/initramfs-tools/scripts/init-top"
    cat << 'EOF' > "$mnt/etc/initramfs-tools/hooks/numlock"
#!/bin/sh
PREREQ=""
prereqs() { echo "$PREREQ"; }
case $1 in prereqs) prereqs; exit 0;; esac
. /usr/share/initramfs-tools/hook-functions
SETLEDS=$(command -v setleds 2>/dev/null || true)
if [ -n "$SETLEDS" ] && [ -x "$SETLEDS" ]; then
    copy_exec "$SETLEDS" /bin
elif [ -x /usr/bin/setleds ]; then
    copy_exec /usr/bin/setleds /bin
elif [ -x /bin/setleds ]; then
    copy_exec /bin/setleds /bin
fi
exit 0
EOF
    chmod 0755 "$mnt/etc/initramfs-tools/hooks/numlock"

    cat << 'EOF' > "$mnt/etc/initramfs-tools/scripts/init-top/numlock"
#!/bin/sh
PREREQ=""
prereqs() { echo "$PREREQ"; }
case $1 in prereqs) prereqs; exit 0;; esac
if [ -x /bin/setleds ] || [ -x /usr/bin/setleds ]; then
    setleds -D +num < /dev/tty0 2>/dev/null || true
fi
EOF
    chmod 0755 "$mnt/etc/initramfs-tools/scripts/init-top/numlock"

    # Base Network Configuration (Default Unconfigured, or Preconfigured via Kickstart)
    mkdir -p "$mnt/etc/network" "$mnt/etc/micron"
    if [ "$KICKSTART_MODE" -eq 1 ] && [ -n "$WAN_IF" ]; then
        cat << EOF > "$mnt/etc/network/interfaces"
auto lo
iface lo inet loopback

allow-hotplug $WAN_IF
iface $WAN_IF inet dhcp
EOF
        if [ -n "$LAN_IF" ]; then
            LAN_IP_ADDR="${LAN_IP:-10.10.10.10}"
            LAN_NM="${LAN_NETMASK:-255.255.255.0}"
            if [ "$LAN_IF" = "br0" ] || [ "${LAN_IS_BRIDGE:-no}" = "yes" ] || [ -n "${BRIDGE_SLAVES:-}" ]; then
                LAN_DEV="br0"
                SLAVES="${BRIDGE_SLAVES:-eth1 eth2 eth3}"
                cat << EOF >> "$mnt/etc/network/interfaces"

auto $LAN_DEV
allow-hotplug $LAN_DEV
iface $LAN_DEV inet static
    address $LAN_IP_ADDR
    netmask $LAN_NM
    bridge_ports $SLAVES
    bridge_stp on
    bridge_fd 2
EOF
                for sl in $SLAVES; do
                    cat << EOF >> "$mnt/etc/network/interfaces"

allow-hotplug $sl
iface $sl inet manual
EOF
                done
                sl_json=$(printf '["%s"]' "$(echo $SLAVES | sed 's/ /", "/g')")
                cat << EOF > "$mnt/etc/micron/network_init.json"
{
  "wan_interface": "$WAN_IF",
  "lan_interface": "$LAN_DEV",
  "lan_is_bridge": true,
  "bridge_slaves": $sl_json,
  "configured": true
}
EOF
            else
                cat << EOF >> "$mnt/etc/network/interfaces"

auto $LAN_IF
allow-hotplug $LAN_IF
iface $LAN_IF inet static
    address $LAN_IP_ADDR
    netmask $LAN_NM
EOF
                cat << EOF > "$mnt/etc/micron/network_init.json"
{
  "wan_interface": "$WAN_IF",
  "lan_interface": "$LAN_IF",
  "configured": true
}
EOF
            fi
        fi
    else
        cat << 'EOF' > "$mnt/etc/network/interfaces"
auto lo
iface lo inet loopback
EOF
        cat << 'EOF' > "$mnt/etc/micron/network_init.json"
{
  "wan_interface": "",
  "lan_interface": "",
  "configured": false
}
EOF
    fi
    echo "micron-os" > "$mnt/etc/hostname"
    if [ -f "$mnt/etc/hosts" ]; then
        sed -i 's/127\.0\.1\.1.*/127.0.1.1 micron-os/g' "$mnt/etc/hosts" || echo "127.0.1.1 micron-os" >> "$mnt/etc/hosts"
    fi
done

# Apply Administrative Passwords
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    if [ -f "$mnt/etc/shadow" ]; then
        chroot "$mnt" bash -c "echo 'ministrator:${ADMIN_PW}' | chpasswd; echo 'root:${ADMIN_PW}' | chpasswd" 2>/dev/null || true
    fi
done

# Setup GRUB Bootloader with A/B dual failover
echo "Configuring GRUB bootloader with A/B dual failover..."
mount --bind /dev "$TARGET_MNT/dev"
mount --bind /dev/pts "$TARGET_MNT/dev/pts"
mount --bind /proc "$TARGET_MNT/proc"
mount --bind /sys "$TARGET_MNT/sys"
[ -d /sys/firmware/efi/efivars ] && mount -t efivarfs efivarfs "$TARGET_MNT/sys/firmware/efi/efivars" 2>/dev/null || true

# Install GRUB slot scripts
if [ -d "${SCRIPT_DIR}/grub.d" ]; then
    install -d "$TARGET_MNT/etc/grub.d" "$TARGET_MNT_B/etc/grub.d"
    install -m 0755 "${SCRIPT_DIR}/grub.d/09_micron_slots" "$TARGET_MNT/etc/grub.d/09_micron_slots"
    install -m 0755 "${SCRIPT_DIR}/grub.d/09_micron_slots" "$TARGET_MNT_B/etc/grub.d/09_micron_slots"
fi

# Seed initial GRUB environment
mkdir -p "$TARGET_MNT/boot/grub" "$TARGET_MNT_B/boot/grub"
chmod 755 "$TARGET_MNT/boot/grub" "$TARGET_MNT_B/boot/grub"
if [ -x "${SCRIPT_DIR}/micron-slot" ]; then
    MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" "${SCRIPT_DIR}/micron-slot" init
    # Both slots hold the same factory baseline, so both start verified-good;
    # otherwise an LKGC failover to Slot B would be treated as untrusted.
    MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" "${SCRIPT_DIR}/micron-slot" mark-ok a
    MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" "${SCRIPT_DIR}/micron-slot" mark-ok b
    cp -p "$TARGET_MNT/boot/grub/grubenv" "$TARGET_MNT_B/boot/grub/grubenv"
    chmod 644 "$TARGET_MNT/boot/grub/grubenv" "$TARGET_MNT_B/boot/grub/grubenv"
fi

# Update initramfs with numlock hook
chroot "$TARGET_MNT" update-initramfs -u 2>/dev/null || true

# Install GRUB binary with removable fallback
if [ -d /sys/firmware/efi ]; then
    echo "Detected UEFI firmware. Installing grub-efi with fallback bootloader..."
    chroot "$TARGET_MNT" grub-install --target=x86_64-efi --efi-directory=/boot/efi --bootloader-id=micron-os --recheck --removable "$TARGET_DISK" || true
    
    mkdir -p "$TARGET_MNT/boot/efi/EFI/BOOT"
    if [ -f "$TARGET_MNT/boot/efi/EFI/micron-os/grubx64.efi" ]; then
        cp -fp "$TARGET_MNT/boot/efi/EFI/micron-os/grubx64.efi" "$TARGET_MNT/boot/efi/EFI/BOOT/BOOTX64.EFI" 2>/dev/null || true
    fi
else
    echo "Detected Legacy BIOS firmware. Installing grub-pc..."
    chroot "$TARGET_MNT" grub-install --target=i386-pc --recheck "$TARGET_DISK" || true
fi
chroot "$TARGET_MNT" update-grub || true

# Copy GRUB config to Slot B
cp -fp "$TARGET_MNT/boot/grub/grub.cfg" "$TARGET_MNT_B/boot/grub/grub.cfg" 2>/dev/null || true
chmod 644 "$TARGET_MNT/boot/grub/grub.cfg" "$TARGET_MNT_B/boot/grub/grub.cfg" 2>/dev/null || true

# Install healthcheck, slot rescue and micrond units in both slots
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    mkdir -p "$mnt/usr/local/micron/installer" "$mnt/usr/local/bin" "$mnt/etc/systemd/system/multi-user.target.wants"
    install -m 0755 "${SCRIPT_DIR}/micron-slot" "$mnt/usr/local/micron/installer/micron-slot"
    install -m 0755 "${SCRIPT_DIR}/micron-healthcheck" "$mnt/usr/local/micron/installer/micron-healthcheck"
    if [ -f "${SCRIPT_DIR}/micron-slot-rescue" ]; then
        install -m 0755 "${SCRIPT_DIR}/micron-slot-rescue" "$mnt/usr/local/micron/installer/micron-slot-rescue"
        install -m 0755 "${SCRIPT_DIR}/micron-slot-rescue" "$mnt/usr/local/bin/micron-slot-rescue"
    fi
    install -m 0644 "${SCRIPT_DIR}/micron-healthcheck.service" "$mnt/etc/systemd/system/micron-healthcheck.service"
    ln -sf /etc/systemd/system/micron-healthcheck.service "$mnt/etc/systemd/system/multi-user.target.wants/micron-healthcheck.service"
    
    # Custom shell prompt & profile scripts
    if [ -f "$mnt/usr/local/micron/system/micron-prompt.sh" ]; then
        install -d "$mnt/etc/profile.d"
        install -m 0644 "$mnt/usr/local/micron/system/micron-prompt.sh" "$mnt/etc/profile.d/micron-prompt.sh"
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-menu.sh" ]; then
        install -d "$mnt/etc/profile.d"
        install -m 0644 "$mnt/usr/local/micron/system/micron-menu.sh" "$mnt/etc/profile.d/micron-menu.sh"
    fi
    if [ -f "$mnt/usr/local/micron/bin/micron-menu" ]; then
        install -m 0755 "$mnt/usr/local/micron/bin/micron-menu" "$mnt/usr/local/bin/micron-menu"
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-banner" ]; then
        install -m 0755 "$mnt/usr/local/micron/system/micron-banner" "$mnt/usr/local/bin/micron-banner"
    fi
    if [ -f "$mnt/usr/local/micron/system/os-release" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/os-release" "$mnt/etc/os-release"
        cp "$mnt/etc/os-release" "$mnt/usr/lib/os-release" 2>/dev/null || true
    fi
done

# Initialize SQLite database
if [ -d "$TARGET_MNT/usr/local/micron" ]; then
    chroot "$TARGET_MNT" bash -c "cd /usr/local/micron && php spark migrate --all && php spark db:seed InitialApplianceSeeder && php spark db:seed RolesPermissionsSeeder && php spark user:reset-admin '${ADMIN_PW}'" || true
    chroot "$TARGET_MNT" chown -R www-data:www-data /usr/local/micron/writable || true
    chroot "$TARGET_MNT" chmod -R 777 /usr/local/micron/writable || true
fi

# Clone seeded database to Slot B
if [ -f "$TARGET_MNT/usr/local/micron/writable/micron_config.db" ] && [ -d "$TARGET_MNT_B/usr/local/micron/writable" ]; then
    cp -fp "$TARGET_MNT/usr/local/micron/writable/micron_config.db"* "$TARGET_MNT_B/usr/local/micron/writable/" || true
    chown -R www-data:www-data "$TARGET_MNT_B/usr/local/micron/writable" || true
    chmod -R 777 "$TARGET_MNT_B/usr/local/micron/writable" || true
fi

# Cleanup mounts
sync
umount "$TARGET_MNT_B" 2>/dev/null || true
rmdir "$TARGET_MNT_B" 2>/dev/null || true

umount "$TARGET_MNT/var/lib/micron" 2>/dev/null || true
umount "$TARGET_MNT/boot/efi" 2>/dev/null || true
umount "$TARGET_MNT/sys/firmware/efi/efivars" 2>/dev/null || true
umount "$TARGET_MNT/dev/pts" 2>/dev/null || true
umount "$TARGET_MNT/dev" 2>/dev/null || true
umount "$TARGET_MNT/proc" 2>/dev/null || true
umount "$TARGET_MNT/sys" 2>/dev/null || true
umount "$TARGET_MNT" 2>/dev/null || true

echo -e "\n${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  MICRON FIREWALL OS (Micron OS) INSTALLATION COMPLETE!${NC}"
echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "Active Boot Slot   : Slot A (Primary)"
echo -e "Standby Slot       : Slot B (Healthy Failover)"
echo -e "\n${BOLD}Network Notice:${NC}"
echo -e "Network interface roles (WAN, LAN, OPT) will be assigned on first boot"
echo -e "from the interactive CLI console menu ('1) Assign Interfaces')."
echo -e "\nPlease remove the USB flash drive or optical media, then restart."
echo ""
if [ "$KICKSTART_MODE" -eq 1 ]; then
    echo -e "${GREEN}[Kickstart] Automated unattended installation completed successfully!${NC}"
    if [[ "$REBOOT" =~ ^([yY]|[yY][eE][sS]|1|true|TRUE)$ ]]; then
        echo -e "${YELLOW}Rebooting appliance automatically in 5 seconds (Press Ctrl+C to cancel)...${NC}"
        sleep 5
        reboot
    else
        echo -e "${CYAN}Automatic reboot disabled in Kickstart (REBOOT=${REBOOT}). Finished.${NC}"
    fi
else
    read -r -p "Reboot appliance now? [Y/n]: " REBOOT_NOW
    REBOOT_NOW=${REBOOT_NOW:-Y}
    if [[ ! "$REBOOT_NOW" =~ ^[nN] ]]; then
        reboot
    fi
fi

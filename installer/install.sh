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
echo -e "${YELLOW}"
cat << 'BANNER'

███╗   ███╗██╗ ██████╗██████╗  ██████╗ ███╗   ██╗     ██████╗ ███████╗
████╗ ████║██║██╔════╝██╔══██╗██╔═══██╗████╗  ██║    ██╔═══██╗██╔════╝
██╔████╔██║██║██║     ██████╔╝██║   ██║██╔██╗ ██║    ██║   ██║███████╗
██║╚██╔╝██║██║██║     ██╔══██╗██║   ██║██║╚██╗██║    ██║   ██║╚════██║
██║ ╚═╝ ██║██║╚██████╗██║  ██║╚██████╔╝██║ ╚████║    ╚██████╔╝███████║
╚═╝     ╚═╝╚═╝ ╚═════╝╚═╝  ╚═╝ ╚═════╝ ╚═╝  ╚═══╝     ╚═════╝ ╚══════╝
 Enterprise  Next-Gen Firewall & Network Appliance — Installation Wizard
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

    # Existing data warning & confirmation
    if [ "$sel_has_data" -eq 1 ]; then
        echo -e "\n${YELLOW}══════════════════════════════════════════════════════════════════════${NC}"
        echo -e "${YELLOW}WARNING: Existing partitions and filesystems detected on ${TARGET_DISK}!${NC}"
        echo -e "${YELLOW}All existing data, partitions, and operating systems will be destroyed!${NC}"
        echo -e "${YELLOW}══════════════════════════════════════════════════════════════════════${NC}"
    fi

    echo -e "\n${RED}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}CONFIRMATION: Target disk ${TARGET_DISK} will be formatted for Micron OS.${NC}"
    echo -e "${RED}══════════════════════════════════════════════════════════════════════${NC}"
    read -r -p "Proceed with partitioning and installation? [Y/n]: " CONFIRM_INSTALL
    CONFIRM_INSTALL=${CONFIRM_INSTALL:-Y}
    if [[ "$CONFIRM_INSTALL" =~ ^[nN] ]]; then
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
# There is no default password. The published factory password (M1Cr0N) is
# refused so that no installed appliance shares a known credential.
ADMIN_PW_MIN_LENGTH=8
ADMIN_PW_GENERATED=0

admin_pw_problem() {
    local pw="$1"
    if [ "${#pw}" -lt "$ADMIN_PW_MIN_LENGTH" ]; then
        echo "must be at least ${ADMIN_PW_MIN_LENGTH} characters"
    elif [ "$pw" = "M1Cr0N" ]; then
        echo "must not be the published factory password"
    elif [[ "$pw" == *:* ]] || [[ "$pw" == *$'\n'* ]]; then
        echo "must not contain ':' or a line break"
    fi
}

if [ "$KICKSTART_MODE" -eq 1 ]; then
    if [ -z "${ADMIN_PW:-}" ]; then
        # Unattended install without a password: generate one and leave it
        # for the operator on the console and in a root-only file.
        ADMIN_PW="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 16)"
        ADMIN_PW_GENERATED=1
        echo -e "${YELLOW}[Kickstart] No ADMIN_PW set; a random password was generated.${NC}\n"
    else
        PW_PROBLEM="$(admin_pw_problem "$ADMIN_PW")"
        if [ -n "$PW_PROBLEM" ]; then
            echo -e "${RED}[Kickstart] ADMIN_PW ${PW_PROBLEM}. Installation aborted before touching the disk.${NC}"
            exit 1
        fi
        echo -e "${GREEN}[Kickstart] Administrative password configured.${NC}\n"
    fi
else
    echo "Unified credentials for Web GUI ('Ministrator') and OS ('root', 'ministrator')."
    echo "Choose a password of at least ${ADMIN_PW_MIN_LENGTH} characters. There is no default."
    while true; do
        read -r -s -p "Enter appliance administrative password: " ADMIN_PW
        echo ""
        PW_PROBLEM="$(admin_pw_problem "$ADMIN_PW")"
        if [ -n "$PW_PROBLEM" ]; then
            echo -e "${RED}[!] The password ${PW_PROBLEM}. Try again.${NC}"
            continue
        fi
        read -r -s -p "Confirm administrative password: " ADMIN_PW_CONFIRM
        echo ""
        if [ "$ADMIN_PW" != "$ADMIN_PW_CONFIRM" ]; then
            echo -e "${RED}[!] The passwords do not match. Try again.${NC}"
            continue
        fi
        unset ADMIN_PW_CONFIRM
        break
    done
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

echo "Writing hybrid GPT partition table to ${TARGET_DISK}..."
wipefs -a "$TARGET_DISK" || true
sgdisk -Z "$TARGET_DISK"
# Partition 1: EFI System Partition (512MB)
sgdisk -n 1:2048:+512M   -t 1:ef00 -c 1:"EFI System Partition"   "$TARGET_DISK"
# Partition 2: BIOS Boot Partition (2MB — MUST be placed early for grub-install i386-pc on GPT)
sgdisk -n 2:0:+2M        -t 2:ef02 -c 2:"BIOS Boot Partition"     "$TARGET_DISK"
# Partition 3: Linux Swap (4GB)
sgdisk -n 3:0:+4G        -t 3:8200 -c 3:"MicronOS Swap"           "$TARGET_DISK"
# Partition 4: Root Slot A (Active System)
sgdisk -n 4:0:+"${SLOT_SIZE}" -t 4:8300 -c 4:"MicronOS Root Slot A" "$TARGET_DISK"
# Partition 5: Root Slot B (Standby Failover System)
sgdisk -n 5:0:+"${SLOT_SIZE}" -t 5:8300 -c 5:"MicronOS Root Slot B" "$TARGET_DISK"
# Partition 6: Persistent Data & Configuration (Remainder)
sgdisk -n 6:0:0          -t 6:8300 -c 6:"MicronOS Persistent Data" "$TARGET_DISK"

# Set ESP and Boot flags on Partition 1 for universal UEFI firmware detection
sgdisk -A 1:set:2 "$TARGET_DISK" 2>/dev/null || true
if command -v parted >/dev/null 2>&1; then
    parted -s "$TARGET_DISK" set 1 esp on 2>/dev/null || true
    parted -s "$TARGET_DISK" set 1 boot on 2>/dev/null || true
fi

# Hybrid MBR: makes Legacy BIOS firmware recognize the GPT disk as bootable.
# Without this, many BIOS implementations refuse to boot a GPT-partitioned disk.
# We mark partition 1 (EFI) and partition 2 (BIOS Boot) in the hybrid MBR.
if command -v sgdisk >/dev/null 2>&1; then
    sgdisk --hybrid 1:2 "$TARGET_DISK" 2>/dev/null || true
fi

sync
partprobe "$TARGET_DISK" || true
udevadm settle 2>/dev/null || sleep 2

# Resolve partition identifiers (handling nvme0n1p1, mmcblk0p1 vs sda1)
if [[ "$TARGET_DISK" =~ [0-9]$ ]]; then
    P_EFI="${TARGET_DISK}p1"
    P_BIOS_BOOT="${TARGET_DISK}p2"
    P_SWAP="${TARGET_DISK}p3"
    P_ROOT_A="${TARGET_DISK}p4"
    P_ROOT_B="${TARGET_DISK}p5"
    P_DATA="${TARGET_DISK}p6"
else
    P_EFI="${TARGET_DISK}1"
    P_BIOS_BOOT="${TARGET_DISK}2"
    P_SWAP="${TARGET_DISK}3"
    P_ROOT_A="${TARGET_DISK}4"
    P_ROOT_B="${TARGET_DISK}5"
    P_DATA="${TARGET_DISK}6"
fi

# Ensure device nodes are ready
for _ in 1 2 3 4 5; do
    [ -b "$P_ROOT_A" ] && break
    sleep 1
    partprobe "$TARGET_DISK" 2>/dev/null || true
    udevadm settle 2>/dev/null || true
done

echo "Formatting filesystems..."
mkfs.vfat -F32 -n "EFI"         "$P_EFI"
# P_BIOS_BOOT (partition 2, type ef02) needs NO filesystem — GRUB writes directly to it
mkswap    -L "SWAP"             "$P_SWAP"
mkfs.ext4 -F -L "MICRON_ROOT_A" "$P_ROOT_A"
mkfs.ext4 -F -L "MICRON_ROOT_B" "$P_ROOT_B"
mkfs.ext4 -F -L "MICRON_DATA"   "$P_DATA"
echo -e "${GREEN}[OK] Partitions and filesystems prepared.${NC}\n"

TARGET_MNT="/mnt/micron_target"
mkdir -p "$TARGET_MNT"
mount "$P_ROOT_A" "$TARGET_MNT"
mkdir -p "$TARGET_MNT/boot/efi"
mount "$P_EFI"    "$TARGET_MNT/boot/efi"
mkdir -p "$TARGET_MNT/var/lib/micron"
mount "$P_DATA"   "$TARGET_MNT/var/lib/micron"
mkdir -p "$TARGET_MNT/mnt/micron-slot-b"

echo -e "\nExtracting live root filesystem to Slot A..."
echo -e "${CYAN}Showing overall transfer percentage progress:${NC}"
rsync -aAX --info=progress2 --no-inc-recursive \
    --exclude={"/dev/*","/proc/*","/sys/*","/tmp/*","/run/*","/mnt/*","/media/*","/lost+found"} \
    / "$TARGET_MNT/"
echo -e "${GREEN}Overall transfer complete: 100%${NC}"

echo -e "\nSynchronizing factory baseline to Standby Slot B..."
TARGET_MNT_B="/mnt/micron_target_b"
mkdir -p "$TARGET_MNT_B"
mount "$P_ROOT_B" "$TARGET_MNT_B"

echo -e "${CYAN}Cloning local baseline to Slot B (high-speed local sync):${NC}"
rsync -aAX --info=progress2 --no-inc-recursive \
    --exclude={"/boot/efi/*","/var/lib/micron/*","/mnt/*","/lost+found"} \
    "$TARGET_MNT/" "$TARGET_MNT_B/"

mkdir -p "$TARGET_MNT_B/boot/efi"
mkdir -p "$TARGET_MNT_B/var/lib/micron"
mkdir -p "$TARGET_MNT_B/mnt/micron-slot-a"

# UUID discovery
ROOT_A_UUID=$(blkid -s UUID -o value "$P_ROOT_A")
ROOT_B_UUID=$(blkid -s UUID -o value "$P_ROOT_B")
EFI_UUID=$(blkid  -s UUID -o value "$P_EFI")
SWAP_UUID=$(blkid -s UUID -o value "$P_SWAP")
DATA_UUID=$(blkid -s UUID -o value "$P_DATA")

# Generate fstab for Slot A
cat << EOF > "$TARGET_MNT/etc/fstab"
# Micron OS Filesystem Table (Slot A)
UUID=${ROOT_A_UUID}          /                    ext4    defaults,noatime,errors=remount-ro   0      1
UUID=${EFI_UUID}             /boot/efi            vfat    umask=0077                           0      1
UUID=${SWAP_UUID}            none                 swap    sw                                   0      0
UUID=${DATA_UUID}            /var/lib/micron      ext4    defaults,noatime,barrier=1,commit=5,data=ordered,errors=remount-ro 0 2
UUID=${ROOT_B_UUID}          /mnt/micron-slot-b   ext4    noauto,nofail                        0      0
tmpfs                        /run                 tmpfs   defaults,noatime,nosuid,nodev,mode=0755,size=256M 0 0
tmpfs                        /tmp                 tmpfs   defaults,noatime,nosuid,nodev,mode=1777,size=512M 0 0
EOF

# Generate fstab for Slot B
cat << EOF > "$TARGET_MNT_B/etc/fstab"
# Micron OS Filesystem Table (Slot B)
UUID=${ROOT_B_UUID}          /                    ext4    defaults,noatime,errors=remount-ro   0      1
UUID=${EFI_UUID}             /boot/efi            vfat    umask=0077                           0      1
UUID=${SWAP_UUID}            none                 swap    sw                                   0      0
UUID=${DATA_UUID}            /var/lib/micron      ext4    defaults,noatime,barrier=1,commit=5,data=ordered,errors=remount-ro 0 2
UUID=${ROOT_A_UUID}          /mnt/micron-slot-a   ext4    noauto,nofail                        0      0
tmpfs                        /run                 tmpfs   defaults,noatime,nosuid,nodev,mode=0755,size=256M 0 0
tmpfs                        /tmp                 tmpfs   defaults,noatime,nosuid,nodev,mode=1777,size=512M 0 0
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
    sed -i '/^GRUB_DEFAULT=/d;/^GRUB_TIMEOUT=/d;/^GRUB_TIMEOUT_STYLE=/d;/^GRUB_DISABLE_OS_PROBER=/d;/^GRUB_TERMINAL_INPUT=/d;/^GRUB_TERMINAL_OUTPUT=/d;/^GRUB_SERIAL_COMMAND=/d;/^GRUB_CMDLINE_LINUX=/d' "$mnt/etc/default/grub" 2>/dev/null || true
    cat >> "$mnt/etc/default/grub" << 'EOF'

# Micron OS — A/B boot slot selector (see /etc/grub.d/09_micron_slots)
# Serial console is fixed at 115200 8N1 (not configurable from the Web UI);
# GRUB's menu is mirrored to it so headless appliances can be recovered.
GRUB_TERMINAL_INPUT="console serial"
GRUB_TERMINAL_OUTPUT="console serial"
GRUB_SERIAL_COMMAND="serial --speed=115200 --unit=0 --word=8 --parity=no --stop=1"
GRUB_CMDLINE_LINUX="console=ttyS0,115200n8 console=tty0"
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

    # Kickstart interface names are logical (eth0/eth1/wan/lan) while modern
    # kernels rename NICs by PCI slot (enp0s3, ens18, eno1, ...). Writing an
    # ifupdown stanza for a device that does not exist makes `ifup -a` fail, which
    # leaves networking.service in a failed state on every boot, on every machine
    # whose NIC names differ from the kickstart file. Resolve each requested name
    # against the devices actually present and emit a stanza only for those.
    # micron-network owns the data plane anyway and auto-assigns the rest.
    micron_resolve_if() { # <requested-name> -> resolved device name, or empty
        local want="${1:-}"
        [ -n "$want" ] || return 0
        # Devices we create ourselves (bond/VLAN) always pass through.
        case "$want" in
            bond*|*.*) printf '%s\n' "$want"; return 0 ;;
        esac
        [ -e "/sys/class/net/$want" ] && { printf '%s\n' "$want"; return 0; }
        local phys=() d
        for d in /sys/class/net/*; do
            d="$(basename "$d")"
            case "$d" in lo|virbr*|docker*|veth*|wg*|tun*|br*|bond*) continue ;; esac
            phys+=("$d")
        done
        case "${want,,}" in
            wan|eth0|eth_wan|wan0)  [ "${#phys[@]}" -ge 1 ] && printf '%s\n' "${phys[0]}" ;;
            lan|eth1|eth_lan|lan0)  [ "${#phys[@]}" -ge 2 ] && printf '%s\n' "${phys[1]}" ;;
            *) : ;;
        esac
        return 0
    }

    WAN_IF_DEV="$(micron_resolve_if "${WAN_IF:-}")"
    LAN_IF_DEV="$(micron_resolve_if "${LAN_IF:-}")"
    if [ -n "${WAN_IF:-}" ] && [ -z "$WAN_IF_DEV" ]; then
        echo -e "${YELLOW}[Kickstart] WAN interface '${WAN_IF}' is not present on this hardware; leaving WAN to micron-network auto-detection.${NC}"
    fi
    if [ -n "${LAN_IF:-}" ] && [ -z "$LAN_IF_DEV" ]; then
        echo -e "${YELLOW}[Kickstart] LAN interface '${LAN_IF}' is not present on this hardware; leaving LAN to micron-network auto-detection.${NC}"
    fi

    if [ "$KICKSTART_MODE" -eq 1 ] && [ -n "$WAN_IF_DEV" ]; then
        cat << EOF > "$mnt/etc/network/interfaces"
auto lo
iface lo inet loopback

allow-hotplug $WAN_IF_DEV
iface $WAN_IF_DEV inet dhcp
EOF
        if [ -n "$LAN_IF_DEV" ]; then
            LAN_IF="$LAN_IF_DEV"
            LAN_IP_ADDR="${LAN_IP:-10.10.10.10}"
            LAN_NM="${LAN_NETMASK:-255.255.255.0}"
            cat << EOF >> "$mnt/etc/network/interfaces"

auto $LAN_IF_DEV
allow-hotplug $LAN_IF_DEV
iface $LAN_IF_DEV inet static
    address $LAN_IP_ADDR
    netmask $LAN_NM
EOF
            cat << EOF > "$mnt/etc/micron/network_init.json"
{
  "wan_interface": "$WAN_IF_DEV",
  "lan_interface": "$LAN_IF_DEV",
  "configured": true
}
EOF
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

# Apply Administrative Passwords and production access policy.
# The live image keeps its accounts password-locked with console autologin and
# passwordless sudo so the installer can run; an installed appliance must not.
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    if [ -f "$mnt/etc/shadow" ]; then
        # Passwords go over stdin, never through a shell string or argv.
        printf 'ministrator:%s\nroot:%s\n' "$ADMIN_PW" "$ADMIN_PW" | chroot "$mnt" chpasswd 2>/dev/null || true
    fi
    # sudo asks for the administrator's own password.
    if [ -d "$mnt/etc/sudoers.d" ]; then
        printf 'ministrator ALL=(ALL:ALL) ALL\n' > "$mnt/etc/sudoers.d/99-ministrator"
        chmod 0440 "$mnt/etc/sudoers.d/99-ministrator"
    fi
    # The console asks for a login; the menu still starts right after it.
    rm -f "$mnt/etc/systemd/system/getty@tty1.service.d/autologin.conf"
    rmdir "$mnt/etc/systemd/system/getty@tty1.service.d" 2>/dev/null || true
    # SSH: no password logins as root; sign in as ministrator and use sudo.
    if [ -d "$mnt/etc/ssh/sshd_config.d" ]; then
        cat > "$mnt/etc/ssh/sshd_config.d/99-micronos.conf" << 'SSHEOF'
PasswordAuthentication yes
PermitRootLogin prohibit-password
MaxAuthTries 4
LoginGraceTime 30
Banner /etc/issue.net
PrintMotd yes
SSHEOF
    fi
done
if [ "$ADMIN_PW_GENERATED" -eq 1 ] && [ -d "$TARGET_MNT/etc/micron" ]; then
    ( umask 077; printf '%s\n' "$ADMIN_PW" > "$TARGET_MNT/etc/micron/initial-admin-password" )
fi

# Setup chroot environment mounts with full recursive bindings
# IMPORTANT: These mounts must happen BEFORE update-initramfs and grub-install.
# Without /dev /proc /sys bound, initramfs generation and grub-install both fail silently.
echo "Preparing chroot virtual filesystems..."
mount --rbind /dev  "$TARGET_MNT/dev"
mount --make-rslave "$TARGET_MNT/dev"
mount --rbind /proc "$TARGET_MNT/proc"
mount --make-rslave "$TARGET_MNT/proc"
mount --rbind /sys  "$TARGET_MNT/sys"
mount --make-rslave "$TARGET_MNT/sys"
if [ -d /sys/firmware/efi/efivars ]; then
    mount -t efivarfs efivarfs "$TARGET_MNT/sys/firmware/efi/efivars" 2>/dev/null || true
fi

# Export slot UUIDs into /etc/default/grub so 09_micron_slots is guaranteed to resolve them
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    mkdir -p "$mnt/etc/default"
    cat >> "$mnt/etc/default/grub" <<EOF

# Explicit Micron A/B partition UUID overrides
MICRON_UUID_A="${ROOT_A_UUID}"
MICRON_UUID_B="${ROOT_B_UUID}"
EOF
done

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
    MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" "${SCRIPT_DIR}/micron-slot" mark-ok a
    MICRON_SLOT_ENV="$TARGET_MNT/boot/grub/grubenv" "${SCRIPT_DIR}/micron-slot" mark-ok b
    cp -p  "$TARGET_MNT/boot/grub/grubenv" "$TARGET_MNT_B/boot/grub/grubenv"
    chmod 644 "$TARGET_MNT/boot/grub/grubenv" "$TARGET_MNT_B/boot/grub/grubenv"
fi

# Update initramfs — chroot mounts are now active so /dev /proc /sys are available
echo "Regenerating initramfs inside installed root..."
chroot "$TARGET_MNT" update-initramfs -u -k all 2>&1 || true

# ------------------------------------------------------------------------------
# Bootloader Installation: Hybrid UEFI & Legacy BIOS Resilience
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}Installing bootloaders for UEFI & Legacy BIOS...${NC}"

# Ensure EFI directories exist on ESP
mkdir -p "$TARGET_MNT/boot/efi/EFI/BOOT"
mkdir -p "$TARGET_MNT/boot/efi/EFI/micron-os"

# ── Safety net: ensure grub-pc-bin (i386-pc modules) are present in the installed system.
# grub-pc-bin provides /usr/lib/grub/i386-pc/modinfo.sh WITHOUT conflicting with grub-efi-amd64.
if [ ! -f "$TARGET_MNT/usr/lib/grub/i386-pc/modinfo.sh" ]; then
    echo -e "${YELLOW}[!] grub-pc-bin not found in target — installing now (no conflict)...${NC}"
    chroot "$TARGET_MNT" apt-get install -y --no-install-recommends grub-pc-bin grub-efi-amd64-bin 2>&1 || true
fi

# Detect firmware mode from the live environment (the booted ISO)
is_uefi=0
if [ -d /sys/firmware/efi ]; then
    is_uefi=1
fi

if [ "$is_uefi" -eq 1 ]; then
    echo "Detected UEFI firmware environment."
    echo "Installing GRUB EFI bootloader..."

    # 1. Primary NVRAM-registered UEFI bootloader
    # CRITICAL: Never pass target disk block device ($TARGET_DISK) to grub-install on x86_64-efi!
    chroot "$TARGET_MNT" grub-install \
        --target=x86_64-efi \
        --efi-directory=/boot/efi \
        --bootloader-id=micron-os \
        --recheck 2>&1

    # 2. Removable fallback bootloader (/boot/efi/EFI/BOOT/BOOTX64.EFI)
    # Essential for motherboards whose NVRAM resets or ignores custom boot entries
    chroot "$TARGET_MNT" grub-install \
        --target=x86_64-efi \
        --efi-directory=/boot/efi \
        --recheck \
        --removable 2>&1 || true

    # 3. Cross-copy grubx64.efi <-> BOOTX64.EFI to guarantee both exist
    if [ -f "$TARGET_MNT/boot/efi/EFI/micron-os/grubx64.efi" ] && [ ! -f "$TARGET_MNT/boot/efi/EFI/BOOT/BOOTX64.EFI" ]; then
        cp -fp "$TARGET_MNT/boot/efi/EFI/micron-os/grubx64.efi" "$TARGET_MNT/boot/efi/EFI/BOOT/BOOTX64.EFI"
    fi
    if [ -f "$TARGET_MNT/boot/efi/EFI/BOOT/BOOTX64.EFI" ] && [ ! -f "$TARGET_MNT/boot/efi/EFI/micron-os/grubx64.efi" ]; then
        cp -fp "$TARGET_MNT/boot/efi/EFI/BOOT/BOOTX64.EFI" "$TARGET_MNT/boot/efi/EFI/micron-os/grubx64.efi"
    fi

    # 4. Fallback grub.cfg on ESP — directs removable bootloader to Slot A by UUID
    cat <<EOF > "$TARGET_MNT/boot/efi/EFI/BOOT/grub.cfg"
search --no-floppy --fs-uuid --set=root ${ROOT_A_UUID}
set prefix=(\$root)'/boot/grub'
configfile \$prefix/grub.cfg
EOF
    cp -fp "$TARGET_MNT/boot/efi/EFI/BOOT/grub.cfg" "$TARGET_MNT/boot/efi/EFI/micron-os/grub.cfg" 2>/dev/null || true

    # 5. Register Micron OS in UEFI NVRAM (remove stale entries first)
    if command -v efibootmgr >/dev/null 2>&1; then
        echo "Registering Micron OS in UEFI NVRAM boot table..."
        for b_num in $(efibootmgr 2>/dev/null | grep -i "micron.os" | sed -E 's/^Boot([0-9A-Fa-f]+).*/\1/' || true); do
            efibootmgr -b "$b_num" -B 2>/dev/null || true
        done
        efibootmgr --create --disk "$TARGET_DISK" --part 1 \
            --label "Micron OS" \
            --loader '\EFI\micron-os\grubx64.efi' 2>/dev/null || true
    fi

    # 6. Also install Legacy BIOS bootloader for hybrid UEFI+BIOS hardware
    if [ -f "$TARGET_MNT/usr/lib/grub/i386-pc/modinfo.sh" ]; then
        echo "Installing Legacy BIOS GRUB (hybrid) on partition 2 (BIOS Boot)..."
        chroot "$TARGET_MNT" grub-install --target=i386-pc --recheck "$TARGET_DISK" 2>&1 || true
    fi
else
    echo "Detected Legacy BIOS firmware environment."
    echo "Installing GRUB PC (MBR/GPT) bootloader..."

    # Verify i386-pc modules are present before calling grub-install
    if [ ! -f "$TARGET_MNT/usr/lib/grub/i386-pc/modinfo.sh" ]; then
        echo -e "${RED}[!] CRITICAL: /usr/lib/grub/i386-pc/modinfo.sh missing in target!${NC}"
        echo -e "${RED}    Cannot install Legacy BIOS GRUB. Aborting bootloader install.${NC}"
        false  # This will trigger the handle_error trap
    fi

    # BIOS Boot partition is partition 2 (type ef02, no filesystem)
    # grub-install writes directly to this partition on GPT disks
    chroot "$TARGET_MNT" grub-install \
        --target=i386-pc \
        --recheck \
        "$TARGET_DISK" 2>&1

    # Also install UEFI bootloader for hybrid compatibility
    if [ -f "$TARGET_MNT/usr/lib/grub/x86_64-efi/modinfo.sh" ]; then
        echo "Installing UEFI GRUB (hybrid) for firmware compatibility..."
        chroot "$TARGET_MNT" grub-install \
            --target=x86_64-efi \
            --efi-directory=/boot/efi \
            --bootloader-id=micron-os \
            --recheck 2>&1 || true
        chroot "$TARGET_MNT" grub-install \
            --target=x86_64-efi \
            --efi-directory=/boot/efi \
            --recheck --removable 2>&1 || true

        # Fallback grub.cfg on ESP
        cat <<EOF > "$TARGET_MNT/boot/efi/EFI/BOOT/grub.cfg"
search --no-floppy --fs-uuid --set=root ${ROOT_A_UUID}
set prefix=(\$root)'/boot/grub'
configfile \$prefix/grub.cfg
EOF
        mkdir -p "$TARGET_MNT/boot/efi/EFI/micron-os"
        cp -fp "$TARGET_MNT/boot/efi/EFI/BOOT/grub.cfg" "$TARGET_MNT/boot/efi/EFI/micron-os/grub.cfg" 2>/dev/null || true
    fi
fi

# Generate complete /boot/grub/grub.cfg
chroot "$TARGET_MNT" update-grub

# Copy GRUB config to Slot B
cp -fp "$TARGET_MNT/boot/grub/grub.cfg" "$TARGET_MNT_B/boot/grub/grub.cfg" 2>/dev/null || true
chmod 644 "$TARGET_MNT/boot/grub/grub.cfg" "$TARGET_MNT_B/boot/grub/grub.cfg" 2>/dev/null || true

# Install healthcheck, slot rescue and micrond units in both slots
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    mkdir -p "$mnt/usr/local/micron/installer" "$mnt/usr/local/bin" "$mnt/etc/systemd/system/multi-user.target.wants" "$mnt/etc/sysctl.d"
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
    if [ -f "$mnt/usr/local/micron/system/micron-network" ]; then
        install -m 0755 "$mnt/usr/local/micron/system/micron-network" "$mnt/usr/local/bin/micron-network"
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-network.service" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/micron-network.service" "$mnt/etc/systemd/system/micron-network.service"
        ln -sf /etc/systemd/system/micron-network.service "$mnt/etc/systemd/system/multi-user.target.wants/micron-network.service"
    fi
    if [ -f "$mnt/usr/local/micron/system/wireguard.service" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/wireguard.service" "$mnt/etc/systemd/system/wireguard.service"
        ln -sf /etc/systemd/system/wireguard.service "$mnt/etc/systemd/system/multi-user.target.wants/wireguard.service"
    fi
    if [ -f "$mnt/usr/local/micron/system/micrond.service" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/micrond.service" "$mnt/etc/systemd/system/micrond.service"
        ln -sf /etc/systemd/system/micrond.service "$mnt/etc/systemd/system/multi-user.target.wants/micrond.service"
    fi
    if [ -f "$mnt/usr/local/micron/system/micrond/micrond.py" ]; then
        chmod 0755 "$mnt/usr/local/micron/system/micrond/micrond.py"
        ln -sf /usr/local/micron/system/micrond/micrond.py "$mnt/usr/local/bin/micrond"
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-db-check.service" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/micron-db-check.service" "$mnt/etc/systemd/system/micron-db-check.service"
        mkdir -p "$mnt/etc/systemd/system/basic.target.wants"
        ln -sf /etc/systemd/system/micron-db-check.service "$mnt/etc/systemd/system/basic.target.wants/micron-db-check.service"
    fi
    if [ -f "$mnt/usr/local/micron/system/99-micron-routing.conf" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/99-micron-routing.conf" "$mnt/etc/sysctl.d/99-micron-routing.conf"
    fi
    if [ -f "$mnt/usr/local/micron/system/50-micron-resilience.conf" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/50-micron-resilience.conf" "$mnt/etc/sysctl.d/50-micron-resilience.conf"
    fi
    if [ -f "$mnt/usr/local/micron/system/50-micron-watchdog.conf" ]; then
        mkdir -p "$mnt/etc/systemd/system.conf.d"
        install -m 0644 "$mnt/usr/local/micron/system/50-micron-watchdog.conf" "$mnt/etc/systemd/system.conf.d/50-micron-watchdog.conf"
    fi
    mkdir -p "$mnt/etc/systemd/system/apache2.service.d"
    cat << 'EOF' > "$mnt/etc/systemd/system/apache2.service.d/50-micron-resilience.conf"
[Unit]
StartLimitIntervalSec=60s
StartLimitBurst=5

[Service]
Restart=always
RestartSec=2s
TimeoutStopSec=10s
OOMScoreAdjust=-800
EOF
    for php_ver in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
        mkdir -p "$mnt/etc/systemd/system/${php_ver}.service.d"
        cat << 'EOF' > "$mnt/etc/systemd/system/${php_ver}.service.d/50-micron-resilience.conf"
[Unit]
StartLimitIntervalSec=60s
StartLimitBurst=5

[Service]
Restart=always
RestartSec=2s
TimeoutStopSec=10s
OOMScoreAdjust=-800
EOF
    done
    # IPv6 factory default: off. micron-network rewrites this at every boot from
    # the configuration database, so an administrator's choice survives slot
    # upgrades (the database, not this file, is the authority).
    if [ -f "$mnt/usr/local/micron/system/60-micron-ipv6.conf" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/60-micron-ipv6.conf" "$mnt/etc/sysctl.d/60-micron-ipv6.conf"
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-terminald.service" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/micron-terminald.service" "$mnt/etc/systemd/system/micron-terminald.service"
        ln -sf /etc/systemd/system/micron-terminald.service "$mnt/etc/systemd/system/multi-user.target.wants/micron-terminald.service"
        chmod 0755 "$mnt/usr/local/micron/system/micron-terminald"
    fi
    if ! chroot "$mnt" getent passwd micron-term >/dev/null 2>&1; then
        chroot "$mnt" useradd --system --create-home --home-dir /var/lib/micron-term --shell /bin/bash \
            --comment "Micron OS browser terminal (standard sessions)" micron-term 2>/dev/null || true
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-ready-beep" ]; then
        install -m 0755 "$mnt/usr/local/micron/system/micron-ready-beep" "$mnt/usr/local/bin/micron-ready-beep"
    fi
    if [ -f "$mnt/usr/local/micron/system/micron-ready-beep.service" ]; then
        install -m 0644 "$mnt/usr/local/micron/system/micron-ready-beep.service" "$mnt/etc/systemd/system/micron-ready-beep.service"
        ln -sf /etc/systemd/system/micron-ready-beep.service "$mnt/etc/systemd/system/multi-user.target.wants/micron-ready-beep.service"
    fi
    mkdir -p "$mnt/etc/modules-load.d"
    echo "pcspkr" > "$mnt/etc/modules-load.d/pcspkr.conf" 2>/dev/null || true
    # Regenerate /etc/os-release from the slot's own VERSION file rather than
    # copying a checked-in os-release, which had drifted from it (the file said
    # one release while VERSION and the ISO filename said another).
    MICRON_OS_VERSION=""
    [ -f "$mnt/usr/local/micron/VERSION" ] && \
        MICRON_OS_VERSION=$(head -n1 "$mnt/usr/local/micron/VERSION" 2>/dev/null | tr -d '[:space:]')
    if [ -z "$MICRON_OS_VERSION" ] && [ -f "$mnt/usr/local/micron/system/os-release" ]; then
        MICRON_OS_VERSION=$(sed -n 's/^VERSION_ID="\{0,1\}\([^"]*\)"\{0,1\}/\1/p' \
            "$mnt/usr/local/micron/system/os-release" | head -n1)
    fi
    [ -n "$MICRON_OS_VERSION" ] || MICRON_OS_VERSION="0.1.44"
    cat > "$mnt/etc/os-release" << OSRELEASEEOF
PRETTY_NAME="Micron OS ${MICRON_OS_VERSION} (amd64)"
NAME="Micron OS"
VERSION_ID="${MICRON_OS_VERSION}"
VERSION="${MICRON_OS_VERSION} (amd64)"
VERSION_CODENAME=trixie
ID=micronos
ID_LIKE=debian
HOME_URL="https://micronos.local/"
SUPPORT_URL="https://micronos.local/support"
BUG_REPORT_URL="https://micronos.local/bugs"
PRIVACY_POLICY_URL="https://micronos.local/privacy"
BUILD_ID="$(date -u +%Y%m%d)"
VARIANT="Enterprise Linux Firewall & Network Appliance"
VARIANT_ID="appliance"
OSRELEASEEOF
    cp "$mnt/etc/os-release" "$mnt/usr/lib/os-release" 2>/dev/null || true
done

# Initialize SQLite database
if [ -d "$TARGET_MNT/usr/local/micron" ]; then
    MICRON_ADMIN_PW="$ADMIN_PW" chroot "$TARGET_MNT" bash -c "cd /usr/local/micron && export MICRON_APPLIANCE=1 && php spark migrate --all && php spark db:seed InitialApplianceSeeder && php spark db:seed RolesPermissionsSeeder && php spark user:reset-admin --from-env && php spark micron:db-resilience --snapshot" || true
    chroot "$TARGET_MNT" chown -R www-data:www-data /usr/local/micron/writable || true
    chroot "$TARGET_MNT" chmod -R 777 /usr/local/micron/writable || true
fi

# Clone seeded database to Slot B
if [ -f "$TARGET_MNT/usr/local/micron/writable/micron_config.db" ] && [ -d "$TARGET_MNT_B/usr/local/micron/writable" ]; then
    cp -fp "$TARGET_MNT/usr/local/micron/writable/micron_config.db"* "$TARGET_MNT_B/usr/local/micron/writable/" || true
    chown -R www-data:www-data "$TARGET_MNT_B/usr/local/micron/writable" || true
    chmod -R 777 "$TARGET_MNT_B/usr/local/micron/writable" || true
fi

# ==============================================================================
# FIX: Explicitly enable MicronOS Apache virtual host in both installed slots
# ==============================================================================
# The rsync copy transfers the live filesystem, but Apache site-enabled symlinks
# are fragile and 000-default.conf may win on first boot. We write the vhost
# config fresh and run a2ensite/a2dissite directly inside the chroot to guarantee
# the MicronOS web UI is served — not the Apache default page.
echo -e "\n${BOLD}Configuring Apache2 virtual host in installed system...${NC}"
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    # Write the MicronOS vhost config directly (don't rely on rsync copy)
    mkdir -p "$mnt/etc/apache2/sites-available"
    cat > "$mnt/etc/apache2/sites-available/micronos.conf" << 'APACHEEOF'
<VirtualHost *:80>
    ServerName micronos.local
    DocumentRoot /usr/local/micron/public

    <Directory /usr/local/micron/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Web Terminal: the WebSocket goes to micron-terminald on 127.0.0.1:7681
    # (never exposed directly). The service only accepts a one-time ticket the
    # Web UI issued for this user, Origin and client address.
    ProxyPass        "/terminal/ws" "ws://127.0.0.1:7681/ws"
    ProxyPassReverse "/terminal/ws" "ws://127.0.0.1:7681/ws"

    # Clientless Gateway viewer: guacd is not a WebSocket server, so the
    # browser's Guacamole tunnel goes to micron-guacd-bridge on 127.0.0.1:4823,
    # which checks the signed session token and relays to guacd.
    ProxyPass        "/vpn/clientless-gateway/tunnel" "ws://127.0.0.1:4823/vpn/clientless-gateway/tunnel"
    ProxyPassReverse "/vpn/clientless-gateway/tunnel" "ws://127.0.0.1:4823/vpn/clientless-gateway/tunnel"

    ErrorLog ${APACHE_LOG_DIR}/micron_error.log
    CustomLog ${APACHE_LOG_DIR}/micron_access.log combined
</VirtualHost>
APACHEEOF

    # Apache2 owns the management GUI on *:80 only. Debian's stock ports.conf
    # declares `Listen 443` whenever mod_ssl is enabled, which made apache2 bind
    # 0.0.0.0:443 even though no Apache vhost speaks TLS. Port 443 belongs to the
    # ZTNA Clientless Gateway (nginx), which micrond starts with `ztna.apply`, so
    # nginx failed to bind and the gateway never came up on a fresh install:
    #     nginx: [emerg] bind() to 0.0.0.0:443 failed (98: Address already in use)
    # Rewrite ports.conf and drop mod_ssl in both slots so apache2 only listens
    # on 80 and leaves 443 free for nginx.
    cat > "$mnt/etc/apache2/ports.conf" << 'APACHEPORTS'
# Micron OS — Apache2 listener configuration
#
# The management GUI is plain HTTP on *:80. Port 443 is reserved for the ZTNA
# Clientless Gateway reverse proxy (nginx, /etc/nginx/sites-available/micron-ztna.conf),
# which micrond enables on demand through `ztna.apply`.
#
# DO NOT re-add `Listen 443` here: apache2 would claim the port before nginx
# starts and the Clientless Gateway would fail with EADDRINUSE.
Listen 80
APACHEPORTS

    # Enable required modules, disable default site, enable MicronOS site
    chroot "$mnt" bash -c "
        a2enmod rewrite proxy proxy_fcgi proxy_wstunnel headers deflate 2>/dev/null || true
        a2dismod ssl 2>/dev/null || true
        a2dissite 000-default.conf 2>/dev/null || true
        a2dissite default-ssl.conf 2>/dev/null || true
        a2ensite micronos.conf 2>/dev/null || true
        systemctl enable apache2 2>/dev/null || true
        systemctl enable php8.4-fpm 2>/dev/null || systemctl enable php8.3-fpm 2>/dev/null || true
    " 2>/dev/null || true
    echo -e "  ${GREEN}[OK]${NC} Apache2 configured in ${mnt}"
done

# ==============================================================================
# FIX: Nginx must never fight Apache2 for port 80
# ==============================================================================
# The installed appliance serves the management GUI from apache2 on *:80.
# Nginx is present only for the ZTNA / Clientless-Gateway reverse proxy on
# :443. Debian's nginx package ships /etc/nginx/sites-enabled/default with
# `listen 80 default_server;` and enables nginx.service at boot, so a stock
# install had nginx and apache2 racing for port 80 on every boot; when apache2
# lost it failed micron-healthcheck's management probe and the appliance
# rebooted on every boot. Remove the stock site, install an inert baseline and
# keep the unit disabled at boot (micrond enables it when ZTNA is turned on).
echo -e "\n${BOLD}Ensuring nginx does not contend with Apache2 for port 80...${NC}"
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    if [ -f "$mnt/etc/nginx/nginx.conf" ]; then
        mkdir -p "$mnt/etc/nginx/sites-available" "$mnt/etc/nginx/sites-enabled"
        rm -f "$mnt/etc/nginx/sites-enabled/default"
        rm -f "$mnt/etc/nginx/sites-enabled/micron-ztna.conf"
        cat > "$mnt/etc/nginx/sites-available/micron-ztna.conf" << 'NGINXEOF'
# Micron OS — ZTNA Clientless Gateway (baseline placeholder)
#
# Intentionally inert: no server block, so nginx starts without claiming a port.
# micrond's `ztna.apply` replaces this with the compiled reverse-proxy config.
# Do not add a `server { listen 80; }` block: port 80 is the Apache2 GUI.
NGINXEOF
        chroot "$mnt" systemctl disable nginx 2>/dev/null || true
        echo -e "  ${GREEN}[OK]${NC} nginx default site removed in ${mnt}"
    fi
done

# ==============================================================================
# FIX: Unblacklist pcspkr kernel module so boot-ready beeps work
# ==============================================================================
# Debian 13 Trixie blacklists pcspkr by default. The micron-ready-beep script
# calls 'modprobe pcspkr || true' which silently fails when blacklisted.
# Remove the blacklist entry and ensure the module loads at boot.
echo -e "\n${BOLD}Enabling pcspkr module for boot-ready beep sound...${NC}"
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    # Remove pcspkr blacklist entries from all modprobe conf files
    for f in "$mnt/etc/modprobe.d/blacklist.conf" \
              "$mnt/etc/modprobe.d/blacklist-oss.conf" \
              "$mnt/etc/modprobe.d/blacklist-framebuffer.conf"; do
        [ -f "$f" ] && sed -i '/blacklist pcspkr/d' "$f" 2>/dev/null || true
    done
    # Ensure pcspkr loads at boot via modules-load.d
    mkdir -p "$mnt/etc/modules-load.d"
    echo "pcspkr" > "$mnt/etc/modules-load.d/pcspkr.conf"
    echo -e "  ${GREEN}[OK]${NC} pcspkr unblacklisted in ${mnt}"
done

# ==============================================================================
# Appliance Service Initializers & Port 53 Deconfliction
# ==============================================================================
echo -e "\n${BOLD}Configuring appliance service initializers, daemons, and resolvers...${NC}"
for mnt in "$TARGET_MNT" "$TARGET_MNT_B"; do
    # 1. Deconflict Port 53: disable/mask systemd-resolved so Unbound binds cleanly
    chroot "$mnt" systemctl disable systemd-resolved 2>/dev/null || true
    chroot "$mnt" systemctl mask systemd-resolved 2>/dev/null || true
    if [ -L "$mnt/etc/resolv.conf" ]; then
        rm -f "$mnt/etc/resolv.conf"
        cat > "$mnt/etc/resolv.conf" << 'RESOLVEOF'
# MicronOS Baseline Appliance Resolver
nameserver 127.0.0.1
nameserver 1.1.1.1
nameserver 8.8.8.8
RESOLVEOF
    fi

    # 1b. Deconflict mDNS & Net-SNMP daemons:
    # Mask Debian's stock mdns-reflector.service so it never starts with missing interfaces.
    # Micron OS runs its own mdns-repeater.service wrapper.
    chroot "$mnt" systemctl disable mdns-reflector 2>/dev/null || true
    chroot "$mnt" systemctl mask mdns-reflector 2>/dev/null || true
    rm -f "$mnt/etc/systemd/system/multi-user.target.wants/mdns-reflector.service" 2>/dev/null || true

    # Net-SNMP: disable snmpd at boot (managed on-demand via Services > SNMP)
    # and provide a clean IPv4-only baseline configuration so snmpd never crashes on [::1].
    chroot "$mnt" systemctl disable snmpd 2>/dev/null || true
    rm -f "$mnt/etc/systemd/system/multi-user.target.wants/snmpd.service" 2>/dev/null || true
    mkdir -p "$mnt/etc/snmp"
    cat > "$mnt/etc/snmp/snmpd.conf" << 'SNMPEOF'
# Micron OS — Net-SNMP daemon baseline configuration
# SNMP is disabled by default; configure and enable via Services > SNMP.
agentaddress udp:127.0.0.1:161
SNMPEOF
    chmod 0600 "$mnt/etc/snmp/snmpd.conf" 2>/dev/null || true

    # 2. Unbound DNSSEC Trust Anchor, Baseline Configuration & Enablement
    mkdir -p "$mnt/var/lib/unbound" "$mnt/etc/unbound/unbound.conf.d"
    if chroot "$mnt" command -v unbound-anchor >/dev/null 2>&1; then
        chroot "$mnt" unbound-anchor -a /var/lib/unbound/root.key 2>/dev/null || true
    elif [ -x "$mnt/usr/sbin/unbound-anchor" ]; then
        chroot "$mnt" /usr/sbin/unbound-anchor -a /var/lib/unbound/root.key 2>/dev/null || true
    fi

    # Fallback: seed root trust anchor if unbound-anchor could not run or lacked network
    if [ ! -s "$mnt/var/lib/unbound/root.key" ]; then
        if [ -f "$mnt/usr/share/dns/root.key" ]; then
            cp "$mnt/usr/share/dns/root.key" "$mnt/var/lib/unbound/root.key"
        else
            cat > "$mnt/var/lib/unbound/root.key" << 'ROOTEK'
. IN DS 20326 8 2 E06D44B80B8F1D39A95C0B0D7C65D08458E880409BBC683457104237C7F8EC8D
ROOTEK
        fi
    fi
    chmod 0644 "$mnt/var/lib/unbound/root.key" 2>/dev/null || true

    # Baseline IPv4-only Unbound configuration (prevents crash on [::1] when IPv6 is disabled)
    cat > "$mnt/etc/unbound/unbound.conf" << 'UNBOUNDEOF'
# ----------------------------------------------------------------------
# Micron Firewall OS (MicronOS) — Baseline Unbound DNS Resolver
# ----------------------------------------------------------------------
server:
    verbosity: 1
    interface: 0.0.0.0
    port: 53
    do-ip4: yes
    do-ip6: no
    do-udp: yes
    do-tcp: yes

    # Access Control
    access-control: 127.0.0.0/8 allow
    access-control: 10.0.0.0/8 allow
    access-control: 172.16.0.0/12 allow
    access-control: 192.168.0.0/16 allow
    access-control: 0.0.0.0/0 refuse

    # Security & Hardening
    hide-identity: yes
    hide-version: yes
    harden-glue: yes
    harden-dnssec-stripped: yes
    use-caps-for-id: yes
    auto-trust-anchor-file: "/var/lib/unbound/root.key"
    tls-cert-bundle: "/etc/ssl/certs/ca-certificates.crt"

    # Performance & Memory
    num-threads: 2
    msg-cache-slabs: 4
    rrset-cache-slabs: 4
    infra-cache-slabs: 4
    key-cache-slabs: 4
    msg-cache-size: 64m
    rrset-cache-size: 128m

forward-zone:
    name: "."
    forward-tls-upstream: yes
    # Cloudflare & Quad9 DNS over TLS (DoT)
    forward-addr: 1.1.1.1@853#cloudflare-dns.com
    forward-addr: 1.0.0.1@853#cloudflare-dns.com
    forward-addr: 9.9.9.9@853#dns.quad9.net
UNBOUNDEOF

    chroot "$mnt" chown -R unbound:unbound /var/lib/unbound /etc/unbound 2>/dev/null || true
    chroot "$mnt" systemctl enable unbound.service 2>/dev/null || true

    # 3. Time Synchronization: enable chrony, disable systemd-timesyncd
    chroot "$mnt" systemctl disable systemd-timesyncd 2>/dev/null || true
    chroot "$mnt" systemctl enable chrony 2>/dev/null || true

    # 4. C-ICAP Service Enablement
    if [ -f "$mnt/etc/default/c-icap" ]; then
        sed -i 's/^START=.*/START=yes/' "$mnt/etc/default/c-icap" || echo "START=yes" >> "$mnt/etc/default/c-icap"
    fi

    # 5. FRRouting Daemons Configuration
    mkdir -p "$mnt/etc/frr"
    cat > "$mnt/etc/frr/daemons" << 'FRREOF'
zebra=yes
bgpd=yes
ospfd=yes
ospf6d=yes
ripd=yes
ripngd=yes
isisd=yes
pimd=yes
ldpd=no
nhrpd=no
eigrpd=no
babeld=no
sharpd=no
pbrd=yes
bfdd=yes
fabricd=no
vrrpd=no
pathd=no

vtysh_enable=yes
zebra_options="  -A 127.0.0.1 -s 90000000"
bgpd_options="   -A 127.0.0.1"
ospfd_options="  -A 127.0.0.1"
ospf6d_options=" -A ::1"
ripd_options="   -A 127.0.0.1"
ripngd_options=" -A ::1"
isisd_options="  -A 127.0.0.1"
pimd_options="   -A 127.0.0.1"
pbrd_options="   -A 127.0.0.1"
bfdd_options="   -A 127.0.0.1"
FRREOF
    touch "$mnt/etc/frr/frr.conf" "$mnt/etc/frr/vtysh.conf"
    chroot "$mnt" chown -R frr:frr /etc/frr 2>/dev/null || true
    chmod 640 "$mnt/etc/frr/frr.conf" "$mnt/etc/frr/daemons" 2>/dev/null || true
    chroot "$mnt" systemctl enable frr.service 2>/dev/null || true


    # 6. Squid Cache Directories
    mkdir -p "$mnt/var/spool/squid" "$mnt/var/log/squid"
    chroot "$mnt" chown -R proxy:proxy /var/spool/squid /var/log/squid 2>/dev/null || true
    chroot "$mnt" squid -z 2>/dev/null || true

    # 7. Custom Service Units: gateway-monitor & mdns-repeater
    if [ -f "$mnt/etc/systemd/system/micron-sdwan-probe.service" ]; then
        ln -sf micron-sdwan-probe.service "$mnt/etc/systemd/system/gateway-monitor.service"
        chroot "$mnt" systemctl enable gateway-monitor.service 2>/dev/null || true
    fi
    if [ -f "$mnt/etc/systemd/system/mdns-repeater.service" ]; then
        chroot "$mnt" systemctl enable mdns-repeater.service 2>/dev/null || true
    fi
    # 8. Cap shutdown/reboot timeouts (prevent 90s hang on networking stop / DHCP release)
    mkdir -p "$mnt/etc/systemd/system/networking.service.d" "$mnt/etc/systemd/system.conf.d"
    cat > "$mnt/etc/systemd/system/networking.service.d/timeout.conf" << 'TIMEOUTEOF'
[Service]
TimeoutStopSec=5s
TIMEOUTEOF
    cat > "$mnt/etc/systemd/system.conf.d/timeout.conf" << 'TIMEOUTEOF'
[Manager]
DefaultTimeoutStopSec=10s
TIMEOUTEOF

    echo -e "  ${GREEN}[OK]${NC} Appliance services and initializers configured in ${mnt}"
done

# Cleanup mounts
sync
umount "$TARGET_MNT_B" 2>/dev/null || true
rmdir "$TARGET_MNT_B" 2>/dev/null || true

umount "$TARGET_MNT/var/lib/micron" 2>/dev/null || true
umount "$TARGET_MNT/boot/efi" 2>/dev/null || true
umount -R "$TARGET_MNT/sys" 2>/dev/null || umount -l "$TARGET_MNT/sys" 2>/dev/null || true
umount -R "$TARGET_MNT/dev" 2>/dev/null || umount -l "$TARGET_MNT/dev" 2>/dev/null || true
umount -R "$TARGET_MNT/proc" 2>/dev/null || umount -l "$TARGET_MNT/proc" 2>/dev/null || true
umount "$TARGET_MNT" 2>/dev/null || true

# Pre-enable SysRq in running kernel so reboot is guaranteed even if media is removed
echo 1 > /proc/sys/kernel/sysrq 2>/dev/null || true

echo -e "\n${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  MICRON FIREWALL OS (Micron OS) INSTALLATION COMPLETE!${NC}"
echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "Active Boot Slot   : Slot A (Primary)"
echo -e "Standby Slot       : Slot B (Healthy Failover)"
if [ "$ADMIN_PW_GENERATED" -eq 1 ]; then
    echo -e "\n${BOLD}${YELLOW}Generated administrator password: ${ADMIN_PW}${NC}"
    echo -e "Used for the Web GUI (Ministrator) and the console (ministrator, root)."
    echo -e "Also saved to /etc/micron/initial-admin-password (root only). Delete it after first sign-in."
fi
echo -e "\n${BOLD}${YELLOW}Reboot Instructions:${NC}"
echo -e "Leave the USB installation drive connected while rebooting."
echo -e "Remove the USB drive as soon as the screen powers off or the BIOS logo appears."
echo ""
if [ "$KICKSTART_MODE" -eq 1 ]; then
    echo -e "${GREEN}[Kickstart] Automated unattended installation completed successfully!${NC}"
    if [[ "$REBOOT" =~ ^([yY]|[yY][eE][sS]|1|true|TRUE)$ ]]; then
        echo -e "${YELLOW}Rebooting appliance automatically in 5 seconds (Press Ctrl+C to cancel)...${NC}"
        sleep 5
        sync 2>/dev/null || true
        # Zero-disk-I/O kernel reboot via SysRq guarantees instant clean reboot
        # without touching SquashFS even if the USB media was already removed:
        echo s > /proc/sysrq-trigger 2>/dev/null || true
        echo u > /proc/sysrq-trigger 2>/dev/null || true
        echo b > /proc/sysrq-trigger 2>/dev/null || true
        systemctl reboot -f 2>/dev/null || reboot -f 2>/dev/null || true
    else
        echo -e "${CYAN}Automatic reboot disabled in Kickstart (REBOOT=${REBOOT}). Finished.${NC}"
    fi
else
    read -r -p "Reboot appliance now? [Y/n]: " REBOOT_NOW
    REBOOT_NOW=${REBOOT_NOW:-Y}
    if [[ ! "$REBOOT_NOW" =~ ^[nN] ]]; then
        echo -e "\n${YELLOW}Rebooting appliance... Remove USB drive when screen turns off.${NC}"
        sync 2>/dev/null || true
        # Zero-disk-I/O kernel reboot via SysRq guarantees instant clean reboot
        # without touching SquashFS even if the USB media was already removed:
        echo s > /proc/sysrq-trigger 2>/dev/null || true
        echo u > /proc/sysrq-trigger 2>/dev/null || true
        echo b > /proc/sysrq-trigger 2>/dev/null || true
        systemctl reboot -f 2>/dev/null || reboot -f 2>/dev/null || true
    fi
fi

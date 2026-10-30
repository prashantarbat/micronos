# shellcheck shell=bash
# /etc/profile.d/micron-menu.sh
# Launch pfSense-style Micron OS interactive console menu for top-level interactive logins

# Prevent menu launch inside child subshells, interactive root shells, or if already running
if [ -n "${MICRON_SHELL:-}" ] || [ -f /tmp/.micron_in_shell ] || [ -n "${IN_MICRON_MENU:-}" ]; then
    return 0 2>/dev/null || exit 0
fi

if [ -t 0 ] && [ -t 1 ] && [ -z "${SSH_ORIGINAL_COMMAND:-}" ] && [ -x /usr/local/bin/micron-menu ]; then
    if [ "${SHLVL:-1}" -le 2 ]; then
        # If booted from ISO with 'micron.installer=1', 'micron.autoinstall=1' or 'micron.kickstart', launch installer
        if grep -E -q "micron\.(installer|autoinstall|kickstart|ks)" /proc/cmdline 2>/dev/null || [ -f /run/live/medium/micron-kickstart.cfg ]; then
            while true; do
                if [ -x /usr/local/micron/installer/install.sh ]; then
                    if [ "${EUID:-$(id -u)}" -eq 0 ]; then
                        /usr/local/micron/installer/install.sh || true
                    elif command -v sudo >/dev/null 2>&1; then
                        sudo /usr/local/micron/installer/install.sh || true
                    fi
                fi
                echo ""
                echo -e "\033[1;33m══════════════════════════════════════════════════════════════════════\033[0m"
                echo -e "\033[1;33m  Installation wizard exited or paused.\033[0m"
                echo -e "\033[1;33m══════════════════════════════════════════════════════════════════════\033[0m"
                echo "  1) Restart Micron OS Installation Wizard (Default)"
                echo "  2) Open Root Maintenance Shell"
                echo "  3) Launch Micron OS Console CLI Menu"
                echo "  4) Reboot System"
                echo "  5) Power Off"
                echo ""
                read -r -p "Select option [1]: " ISO_ACT
                ISO_ACT=${ISO_ACT:-1}
                case "$ISO_ACT" in
                    1) continue ;;
                    2) if [ "${EUID:-$(id -u)}" -eq 0 ]; then /bin/bash -i; else sudo /bin/bash -i; fi; continue ;;
                    3) /usr/local/bin/micron-menu; continue ;;
                    4) reboot; exit 0 ;;
                    5) poweroff; exit 0 ;;
                    *) continue ;;
                esac
            done
            return 0 2>/dev/null || exit 0
        elif grep -E -q "micron\.(slot_rescue|rescue)" /proc/cmdline 2>/dev/null; then
            if [ -x /usr/local/bin/micron-slot-rescue ]; then
                if [ "${EUID:-$(id -u)}" -eq 0 ]; then
                    /usr/local/bin/micron-slot-rescue || true
                elif command -v sudo >/dev/null 2>&1; then
                    sudo /usr/local/bin/micron-slot-rescue || true
                fi
            elif [ -x /usr/local/micron/installer/micron-slot-rescue ]; then
                if [ "${EUID:-$(id -u)}" -eq 0 ]; then
                    /usr/local/micron/installer/micron-slot-rescue || true
                elif command -v sudo >/dev/null 2>&1; then
                    sudo /usr/local/micron/installer/micron-slot-rescue || true
                fi
            fi
        fi
        
        /usr/local/bin/micron-menu
    fi
fi

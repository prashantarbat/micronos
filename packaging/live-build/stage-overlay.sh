#!/bin/bash
# ==============================================================================
# MicronOS ISO Overlay Staging
# ==============================================================================
# live-build copies config/includes.chroot/ verbatim onto the appliance's root
# filesystem, so that directory is what actually ships the Terminal CLI and the
# whole web application. Both are staged here from their single source of truth —
# bin/microctl and the repository itself — rather than copied by hand or checked
# in as a second tree, which is how a staged file and a developed file drift.
#
# Run before `lb build` (see docs/ISO_CREATION_AND_INSTALLER_GUIDE.md, §5):
#
#   packaging/live-build/stage-overlay.sh
#
# Options:
#   --out <dir>   Overlay root to stage into.
#                 Default: packaging/live-build/config/includes.chroot
#   -h, --help    Show this help.
#
# The application tree in the overlay is replaced on every run, so a stale file
# cannot ship; the CLI is installed beside it and the rest is left alone.
# ==============================================================================
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="${MICRONOS_SRC:-$(cd "${here}/../.." && pwd)}"
out="${here}/config/includes.chroot"

while [ $# -gt 0 ]; do
    case "$1" in
        --src)
            [ -n "${2:-}" ] || { echo "stage-overlay: --src requires a directory." >&2; exit 2; }
            root="$2"
            shift 2
            ;;
        --out)
            [ -n "${2:-}" ] || { echo "stage-overlay: --out requires a directory." >&2; exit 2; }
            out="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '2,22p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "stage-overlay: unknown argument: $1" >&2
            exit 2
            ;;
    esac
done

cli="${root}/bin/microctl"
completion="${here}/completion/microctl.bash"

if [ ! -f "${cli}" ]; then
    echo "stage-overlay: missing Terminal CLI at ${cli}" >&2
    exit 1
fi

if [ ! -f "${completion}" ]; then
    echo "stage-overlay: missing completion at ${completion}" >&2
    exit 1
fi

# The appliance's application root, as the provisioning hook configures it: the
# Apache vhost in config/hooks/live/01-micronos-setup.hook.chroot serves
# DocumentRoot /usr/local/micron/public, the IPC daemon runs
# /usr/local/micron/system/micrond/micrond.py, and the CLI resolves the same root
# by looking for spark + vendor/autoload.php beside it. tests/packaging/ asserts
# these stay in step.
app_root="${out}/usr/local/micron"

# This script owns that path, so it is generated output rather than a tree to
# merge into: a stale file from an earlier build — or state an operator copied in
# by hand — must not ride along into the image. Everything else the overlay holds
# is left alone.
case "${out}" in
    ''|/|.)
        echo "stage-overlay: refusing to stage into '${out}'" >&2
        exit 2
        ;;
esac

install -d "${out}/tmp"
chmod 1777 "${out}/tmp" 2>/dev/null || true
install -d "${out}/var/tmp"
chmod 1777 "${out}/var/tmp" 2>/dev/null || true
rm -rf "${app_root}"
install -d "${app_root}"

install -d "${out}/usr/local/bin"
install -m 0755 "${cli}" "${out}/usr/local/bin/microctl"

if [ -f "${root}/system/micron-banner" ]; then
    install -m 0755 "${root}/system/micron-banner" "${out}/usr/local/bin/micron-banner"
fi

if [ -f "${root}/bin/micron-menu" ]; then
    install -m 0755 "${root}/bin/micron-menu" "${out}/usr/local/bin/micron-menu"
fi

if [ -f "${root}/installer/micron-slot-rescue" ]; then
    install -m 0755 "${root}/installer/micron-slot-rescue" "${out}/usr/local/bin/micron-slot-rescue"
fi

if [ -f "${root}/system/micron-menu.sh" ]; then
    install -d "${out}/etc/profile.d"
    install -m 0644 "${root}/system/micron-menu.sh" "${out}/etc/profile.d/micron-menu.sh"
fi

if [ -f "${root}/system/micron-prompt.sh" ]; then
    install -d "${out}/etc/profile.d"
    install -m 0644 "${root}/system/micron-prompt.sh" "${out}/etc/profile.d/micron-prompt.sh"
fi

if [ -f "${root}/system/numlock.sh" ]; then
    install -d "${out}/etc/profile.d"
    install -m 0755 "${root}/system/numlock.sh" "${out}/etc/profile.d/numlock.sh"
fi

if [ -f "${root}/system/numlock.service" ]; then
    install -d "${out}/etc/systemd/system"
    install -m 0644 "${root}/system/numlock.service" "${out}/etc/systemd/system/numlock.service"
fi

if [ -f "${root}/system/micron-network" ]; then
    install -m 0755 "${root}/system/micron-network" "${out}/usr/local/bin/micron-network"
fi

if [ -f "${root}/system/micron-network.service" ]; then
    install -d "${out}/etc/systemd/system"
    install -m 0644 "${root}/system/micron-network.service" "${out}/etc/systemd/system/micron-network.service"
fi

if [ -f "${root}/system/wireguard.service" ]; then
    install -d "${out}/etc/systemd/system/multi-user.target.wants"
    install -m 0644 "${root}/system/wireguard.service" "${out}/etc/systemd/system/wireguard.service"
    ln -sf /etc/systemd/system/wireguard.service "${out}/etc/systemd/system/multi-user.target.wants/wireguard.service" 2>/dev/null || true
fi

if [ -f "${root}/system/99-micron-routing.conf" ]; then
    install -d "${out}/etc/sysctl.d"
    install -m 0644 "${root}/system/99-micron-routing.conf" "${out}/etc/sysctl.d/99-micron-routing.conf"
fi

if [ -f "${root}/system/micron-ready-beep" ]; then
    install -m 0755 "${root}/system/micron-ready-beep" "${out}/usr/local/bin/micron-ready-beep"
fi

if [ -f "${root}/system/micron-ready-beep.service" ]; then
    install -d "${out}/etc/systemd/system"
    install -m 0644 "${root}/system/micron-ready-beep.service" "${out}/etc/systemd/system/micron-ready-beep.service"
fi

# Install check-in (micronos.microninfo.com): at boot, then daily. The
# administrator turns it off in System > Networking; the timer then sends nothing.
if [ -f "${root}/system/micron-checkin.service" ] && [ -f "${root}/system/micron-checkin.timer" ]; then
    install -d "${out}/etc/systemd/system/timers.target.wants"
    install -m 0644 "${root}/system/micron-checkin.service" "${out}/etc/systemd/system/micron-checkin.service"
    install -m 0644 "${root}/system/micron-checkin.timer" "${out}/etc/systemd/system/micron-checkin.timer"
    ln -sf /etc/systemd/system/micron-checkin.timer "${out}/etc/systemd/system/timers.target.wants/micron-checkin.timer"
fi

if [ -f "${root}/system/micron-sdwan-probe" ]; then
    install -m 0755 "${root}/system/micron-sdwan-probe" "${out}/usr/local/bin/micron-sdwan-probe"
fi

if [ -f "${root}/system/micron-sdwan-probe.service" ]; then
    install -d "${out}/etc/systemd/system"
    install -m 0644 "${root}/system/micron-sdwan-probe.service" "${out}/etc/systemd/system/micron-sdwan-probe.service"
    ln -sf micron-sdwan-probe.service "${out}/etc/systemd/system/gateway-monitor.service" 2>/dev/null || true
fi

if [ -f "${root}/system/micron-mdns-repeater" ]; then
    install -m 0755 "${root}/system/micron-mdns-repeater" "${out}/usr/local/bin/micron-mdns-repeater"
fi

if [ -f "${root}/system/mdns-repeater.service" ]; then
    install -d "${out}/etc/systemd/system"
    install -m 0644 "${root}/system/mdns-repeater.service" "${out}/etc/systemd/system/mdns-repeater.service"
fi

install -d "${out}/etc/modules-load.d"
echo "pcspkr" > "${out}/etc/modules-load.d/pcspkr.conf"

if [ -f "${root}/system/os-release" ]; then
    install -d "${out}/etc"
    install -m 0644 "${root}/system/os-release" "${out}/etc/os-release"
fi

if [ -f "${root}/VERSION" ]; then
    install -d "${out}/usr/local/micron"
    install -m 0644 "${root}/VERSION" "${out}/usr/local/micron/VERSION"
fi

# Debian's bash-completion package lazy-loads this directory, which is why
# bash-completion is a declared package in config/package-lists/micronos.list.chroot.
install -d "${out}/usr/share/bash-completion/completions"
install -m 0644 "${completion}" "${out}/usr/share/bash-completion/completions/microctl"

# ------------------------------------------------------------------------------
# The web application itself
# ------------------------------------------------------------------------------
# The image runs the application from /usr/local/micron, so the repository has
# to reach that path *whole*: the front controller, the framework, the CLI's
# command classes, the IPC daemon, the interface's offline assets and the vendor
# tree the autoloader needs.
#
# Copying that tree by hand — which is what this replaces — is the failure mode
# the offline assets were only the most visible example of. A tree copied a
# release ago, or copied minus a directory added since, boots an appliance that
# answers 200 and behaves like an older build, and nothing in the image says so.
#
# The list below is an allow-list rather than a set of excludes, so development
# state is excluded by construction instead of by remembering: the repository's
# .env, its test suite, its build output and the writable/ state — including the
# development configuration database — are simply never named, and a later
# addition to those directories cannot start shipping by accident.
shipped=(
    app
    bin
    docs
    installer
    public
    system
    vendor
    spark
    preload.php
    composer.json
    composer.lock
    env
    VERSION
    EULA.txt
    LICENSE
    README.md
)

for entry in "${shipped[@]}"; do
    if [ ! -e "${root}/${entry}" ]; then
        echo "stage-overlay: the application is missing ${entry}" >&2
        exit 1
    fi

    cp -a "${root}/${entry}" "${app_root}/"
    echo "stage-overlay: ${app_root}/${entry}"
done

# Byte-compiled Python from a development run is not part of the application.
find "${app_root}" -type d -name '__pycache__' -prune -exec rm -rf {} +
find "${app_root}" -type f -name '*.pyc' -delete

# Deterministic modes: the image must not depend on the checkout's umask, on a
# tarball that dropped the execute bit, or on the build host's filesystem.
# Everything is readable; only the entry points the appliance executes are not.
find "${app_root}" -type d -exec chmod 0755 {} +
find "${app_root}" -type f -exec chmod 0644 {} +

for executable in \
    spark \
    bin/microctl \
    bin/micron-menu \
    installer/install.sh \
    installer/micron-slot \
    installer/micron-slot-rescue \
    installer/micron-healthcheck \
    system/micron-banner \
    system/micron-network \
    system/micron_netcfg.py \
    system/micron-terminald \
    system/micron-guacd-bridge \
    system/micron-ready-beep \
    system/micrond/micrond.py
do
    if [ -f "${app_root}/${executable}" ]; then
        chmod 0755 "${app_root}/${executable}"
    fi
done

# writable/ is state, not code: the directories the application expects are
# created empty, and their contents — a development database, captured sessions,
# exported backups — never ship. The provisioning hook chowns this tree to
# www-data afterwards.
for state in backups cache connectors debugbar fqdn geoip isdb logs pki session uploads; do
    install -d "${app_root}/writable/${state}"
done

echo "stage-overlay: ${out}/usr/local/bin/microctl"
echo "stage-overlay: ${out}/usr/share/bash-completion/completions/microctl"

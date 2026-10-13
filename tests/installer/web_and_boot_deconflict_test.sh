#!/bin/bash
# ==============================================================================
# MicronOS — Web-plane and boot-configuration deconfliction test
# ==============================================================================
# Guards four defects that were reproduced on freshly installed appliances:
#
#   1. apache2 bound 0.0.0.0:443 (Debian's stock `Listen 443` + enabled mod_ssl)
#      even though no Apache vhost speaks TLS, so the ZTNA Clientless Gateway
#      (nginx, which owns :443) could never start:
#          nginx: [emerg] bind() to 0.0.0.0:443 failed (98: Address already in use)
#   2. The installer wrote ifupdown stanzas for the kickstart's logical interface
#      names (eth0/eth1) without checking the hardware, leaving
#      networking.service failed on every boot whenever the kernel named the
#      NICs differently (enp0s3, ens18, eno1, ...).
#   3. The boot banner resolved the active A/B slot by partition number, which is
#      off by one for the installer's own layout (p4 = MICRON_ROOT_A) and so
#      announced "Slot B" on every fresh install.
#
# Usage: bash tests/installer/web_and_boot_deconflict_test.sh
# Exit:  0 when every assertion holds, 1 otherwise.
# ==============================================================================
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
INSTALLER="${ROOT}/installer/install.sh"
HOOK="${ROOT}/packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot"
BANNER="${ROOT}/system/micron-banner"
MENU="${ROOT}/bin/micron-menu"
ZTNA="${ROOT}/app/Libraries/ZtnaCompiler.php"
MICROND="${ROOT}/system/micrond/micrond.py"

PASS=0
FAIL=0

ok()  { printf '  ok   %s\n' "$1"; PASS=$((PASS + 1)); }
bad() { printf '  FAIL %s\n' "$1"; FAIL=$((FAIL + 1)); }

assert_contains() { # <label> <file> <pattern>
    if grep -qE "$3" "$2" 2>/dev/null; then ok "$1"; else bad "$1 (missing /$3/ in $(basename "$2"))"; fi
}

assert_lacks() { # <label> <file> <pattern>
    if grep -qE "$3" "$2" 2>/dev/null; then bad "$1 (unexpected /$3/ in $(basename "$2"))"; else ok "$1"; fi
}

# Directive checks must ignore explanatory comments: the fix documents the
# collision it prevents, which means the text "Listen 443" legitimately appears
# in prose right next to the configuration.
strip_comments() { grep -vE '^[[:space:]]*#'; }

echo "== A. every script parses =="
for script in "$INSTALLER" "$HOOK" "$BANNER" "$MENU"; do
    if bash -n "$script" 2>/dev/null; then ok "$(basename "$script") parses"; else bad "$(basename "$script") has a syntax error"; fi
done

echo
echo "== B. apache2 must not reserve :443 (nginx/ZTNA owns it) =="
# The ports.conf blocks are heredocs inside the installer and the image hook; the
# stock Debian `Listen 443` lines must not survive either of them.
installer_ports=$(sed -n '/^Listen 80$/,/^APACHEPORTS$/p' "$INSTALLER")
hook_ports=$(sed -n '/cat << .EOF. > \/etc\/apache2\/ports.conf/,/^EOF$/p' "$HOOK")
if printf '%s' "$installer_ports" | grep -q '^Listen 80$'; then
    ok "installer writes a ports.conf that listens on 80"
else
    bad "installer has no explicit ports.conf"
fi
if printf '%s' "$installer_ports" | strip_comments | grep -qE 'Listen 443'; then
    bad "installer ports.conf still declares Listen 443"
else
    ok "installer ports.conf does not claim 443"
fi
if printf '%s' "$hook_ports" | strip_comments | grep -qE 'Listen 443'; then
    bad "image hook ports.conf still declares Listen 443"
else
    ok "image hook ports.conf does not claim 443"
fi
if printf '%s' "$installer_ports" | strip_comments | grep -qE '^Listen 80$'; then
    ok "installer ports.conf still serves the GUI on 80"
else
    bad "installer ports.conf dropped Listen 80"
fi
assert_contains "installer drops mod_ssl"        "$INSTALLER" 'a2dismod ssl'
assert_contains "image hook drops mod_ssl"       "$HOOK"      'a2dismod ssl'
assert_lacks    "installer no longer enables ssl" "$INSTALLER" 'a2enmod [^"]*\bssl\b'
assert_lacks    "image hook no longer enables ssl" "$HOOK"     'a2enmod [^\n]*\bssl\b'
# The other side of the contract: the ZTNA compiler really does want 443.
assert_contains "ZTNA gateway listens on 443"    "$ZTNA"      'listen 443 ssl'
assert_contains "micrond starts nginx for ZTNA"  "$MICROND"   'systemctl", "enable", "--now", "nginx"'

echo
echo "== C. kickstart interface names are resolved against real hardware =="
assert_contains "installer resolves requested NIC names" "$INSTALLER" 'micron_resolve_if\(\)'
# Stanzas must be emitted from the resolved names, never the raw kickstart ones.
assert_lacks    "no stanza for unresolved WAN name" "$INSTALLER" 'iface \$WAN_IF inet'
assert_lacks    "no stanza for unresolved LAN name" "$INSTALLER" 'iface \$LAN_IF inet'
assert_contains "WAN stanza uses resolved device"   "$INSTALLER" 'iface \$WAN_IF_DEV inet dhcp'
assert_contains "LAN stanza uses resolved device"   "$INSTALLER" 'iface \$LAN_IF_DEV inet static'
assert_contains "bridge slaves are filtered"        "$INSTALLER" 'sys/class/net/\$sl'

echo
echo "== D. boot slot is resolved by label, not partition number =="
assert_contains "banner resolves root filesystem label" "$BANNER" 'blkid -s LABEL -o value'
assert_contains "banner matches MICRON_ROOT_A"          "$BANNER" 'MICRON_ROOT_A\) ACTIVE_SLOT="A"'
assert_contains "banner matches MICRON_ROOT_B"          "$BANNER" 'MICRON_ROOT_B\) ACTIVE_SLOT="B"'
assert_lacks    "banner has no partition-number guess"  "$BANNER" 'sda4\|nvme0n1p4\|vda4'
# micron-menu already did this correctly; keep it that way.
assert_contains "console menu resolves by label too"    "$MENU"   'MICRON_ROOT_A\) ACTIVE_SLOT="A"'
assert_lacks    "console menu has no numbering guess"   "$MENU"   'nvme0n1p4'

echo
if [ "$FAIL" -eq 0 ]; then
    printf 'PASS: %s assertions, 0 failures\n' "$PASS"
    exit 0
fi
printf 'FAILED: %s passed, %s failed\n' "$PASS" "$FAIL"
exit 1

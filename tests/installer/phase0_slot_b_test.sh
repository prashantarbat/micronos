#!/bin/bash
# ==============================================================================
# MicronOS — Phase 0 Acceptance Test: A/B Slot B
# ==============================================================================
# Verifies the installer provisions a real second root slot and that the boot
# slot bookkeeping actually fails over. Runs anywhere bash is available (the
# appliance, CI, or a developer workstation) because every slot operation is
# redirected to a scratch environment block via MICRON_SLOT_ENV.
#
# Usage:  bash tests/installer/phase0_slot_b_test.sh
# Exit:   0 when every assertion holds, 1 otherwise.
# ==============================================================================
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SLOT="${ROOT}/installer/micron-slot"
HEALTH="${ROOT}/installer/micron-healthcheck"
INSTALLER="${ROOT}/installer/install.sh"
GRUB_FRAGMENT="${ROOT}/installer/grub.d/09_micron_slots"
UNIT="${ROOT}/installer/micron-healthcheck.service"

PASS=0
FAIL=0

ok()  { printf '  ok   %s\n' "$1"; PASS=$((PASS + 1)); }
bad() { printf '  FAIL %s\n' "$1"; FAIL=$((FAIL + 1)); }

assert_eq() { # assert_eq <label> <expected> <actual>
    if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$2', got '$3')"; fi
}

assert_file_contains() { # assert_file_contains <label> <file> <pattern>
    if grep -qE "$3" "$2" 2>/dev/null; then ok "$1"; else bad "$1 (no match for /$3/ in $(basename "$2"))"; fi
}

assert_file_lacks() { # assert_file_lacks <label> <file> <pattern>
    if grep -qE "$3" "$2" 2>/dev/null; then bad "$1 (unexpected /$3/)"; else ok "$1"; fi
}

TMP=$(mktemp -d)
# shellcheck disable=SC2064
trap "rm -rf '${TMP}'" EXIT
export MICRON_SLOT_ENV="${TMP}/grubenv"

echo "== A. scripts parse =="
for script in "$INSTALLER" "$SLOT" "$HEALTH" "$GRUB_FRAGMENT"; do
    if bash -n "$script" 2>/dev/null || sh -n "$script" 2>/dev/null; then
        ok "$(basename "$script") parses"
    else
        bad "$(basename "$script") has a syntax error"
    fi
done

echo
echo "== B. slot environment helper =="
if out=$(bash "$SLOT" --self-test 2>&1); then
    ok 'micron-slot --self-test passes'
else
    bad "micron-slot --self-test failed: $(printf '%s' "$out" | grep FAIL | head -3 | tr '\n' ';')"
fi

echo
echo "== C. installer provisions slot B =="
assert_file_contains 'slot B partition is created'      "$INSTALLER" 'sgdisk -n 4:0:\+'
assert_file_contains 'slot B is formatted'              "$INSTALLER" 'mkfs\.ext4 -F -L "MICRON_ROOT_B"'
assert_file_contains 'data partition is created'        "$INSTALLER" 'sgdisk -n 5:0:0'
assert_file_contains 'root A is labelled'               "$INSTALLER" 'MICRON_ROOT_A'
assert_file_contains 'fstab carries slot B by UUID'     "$INSTALLER" 'UUID=\$\{ROOT_B_UUID\}.*micron-slot-b'
assert_file_contains 'slot B is not auto-mounted'       "$INSTALLER" 'micron-slot-b\s+ext4\s+noauto'
assert_file_contains 'persistent data layer is mounted' "$INSTALLER" 'mount "\$P_DATA" "\$TARGET_MNT/var/lib/micron"'
assert_file_contains 'slot selector is installed'       "$INSTALLER" 'install -m 0755 "\$\{SCRIPT_DIR\}/grub\.d/09_micron_slots"'
assert_file_contains 'slot selector becomes the default' "$INSTALLER" 'GRUB_DEFAULT=0'
assert_file_contains 'slot environment is seeded'       "$INSTALLER" 'MICRON_SLOT_ENV="\$TARGET_MNT/boot/grub/grubenv"'
assert_file_contains 'slot B is populated at install'   "$INSTALLER" 'Populating Slot B with factory baseline'
assert_file_contains 'slot B baseline is marked ok'    "$INSTALLER" 'micron-slot" mark-ok b'
assert_file_contains 'data fstab sets write barriers'   "$INSTALLER" 'barrier=1,commit=5'
assert_file_contains 'hardware watchdog is configured' "$INSTALLER" 'RuntimeWatchdogSec=30s'
assert_file_contains 'health check is installed'        "$INSTALLER" 'micron-healthcheck" "\$TARGET_MNT/usr/local/micron/installer/micron-healthcheck'
assert_file_contains 'health check unit is enabled'     "$INSTALLER" 'multi-user\.target\.wants/micron-healthcheck\.service'
assert_file_contains 'undersized disks are refused'     "$INSTALLER" 'the A/B layout needs'
assert_file_contains 'missing assets abort the install' "$INSTALLER" 'required installer asset'

echo
echo "== D. GRUB selector fragment =="
FRAG="${TMP}/grub.cfg"
MICRON_UUID_A='1111-AAAA' MICRON_UUID_B='2222-BBBB' MICRON_KERNEL='/boot/vmlinuz-6.12.0-test' \
    sh "$GRUB_FRAGMENT" > "$FRAG" 2>/dev/null
assert_file_contains 'reads the GRUB environment block' "$FRAG" '^load_env'
assert_file_contains 'defines the boot function'        "$FRAG" '^function micron_boot'
assert_file_contains 'intercepts Escape keypress'       "$FRAG" 'keystatus --escape'
assert_file_contains 'intercepts Shift keypress'        "$FRAG" 'keystatus --shift'
assert_file_contains 'auto slot entry exists'           "$FRAG" 'menuentry "MicronOS \(auto slot\)" --id micronos-auto'
assert_file_contains 'LKGC alternate slot entry exists' "$FRAG" 'menuentry "MicronOS \(LKGC - alternate slot\)" --id micronos-lkgc'
assert_file_contains 'factory recovery entry exists'    "$FRAG" 'menuentry "MicronOS \(factory recovery - pristine boot\)" --id micronos-factory'
assert_file_contains 'failsafe shell entry exists'      "$FRAG" 'menuentry "MicronOS \(failsafe maintenance shell\)" --id micronos-recovery'
assert_file_contains 'manual slot A entry exists'       "$FRAG" 'menuentry "MicronOS \(slot A, manual\)"'
assert_file_contains 'manual slot B entry exists'       "$FRAG" 'menuentry "MicronOS \(slot B, manual\)"'
assert_file_contains 'slot A UUID is booted'            "$FRAG" 'root=UUID=1111-AAAA'
assert_file_contains 'slot B UUID is booted'            "$FRAG" 'root=UUID=2222-BBBB'
assert_file_contains 'kernel path is absolute'          "$FRAG" 'linux  /boot/vmlinuz-6\.12\.0-test'
assert_file_contains 'initrd is derived from kernel'    "$FRAG" 'initrd /boot/initrd\.img-6\.12\.0-test'
assert_file_contains 'untrusted slot falls back'        "$FRAG" 'fall back to the other one'
# GRUB script has no reliable arithmetic; the selector must not try any.
assert_file_lacks 'no arithmetic in the selector'       "$FRAG" '\$\(\(|\$\[\('
# A fragment without slot B must not offer a slot B or LKGC entry.
FRAG_NO_B="${TMP}/grub-nob.cfg"
MICRON_UUID_A='1111-AAAA' MICRON_UUID_B='' MICRON_KERNEL='/boot/vmlinuz-test' \
    sh "$GRUB_FRAGMENT" > "$FRAG_NO_B" 2>/dev/null
assert_file_lacks 'no slot B entry when B is unlabelled' "$FRAG_NO_B" 'slot B, manual'
assert_file_lacks 'no LKGC entry when B is unlabelled'   "$FRAG_NO_B" 'LKGC - alternate slot'

echo
echo "== E. health check decides the slot state =="
bash "$SLOT" init --force >/dev/null
assert_eq 'fresh install: slot a active'    'a' "$(bash "$SLOT" active)"
assert_eq 'fresh install: slot a unverified' '0' "$(bash "$SLOT" get micron_a_ok)"

# A healthy boot promotes the running slot.
MICRON_SLOT_HELPER="$SLOT" MICRON_HEALTHCHECK_FORCE=ok MICRON_HEALTHCHECK_REBOOT=0 \
    bash "$HEALTH" >/dev/null 2>&1
assert_eq 'healthy boot marks slot a good'  '1' "$(bash "$SLOT" get micron_a_ok)"

# Dry runs must never touch the block.
MICRON_SLOT_HELPER="$SLOT" MICRON_HEALTHCHECK_FORCE=ok MICRON_HEALTHCHECK_MODE=dry-run \
    bash "$HEALTH" >/dev/null 2>&1
assert_eq 'dry run leaves the block alone'  '1' "$(bash "$SLOT" get micron_a_ok)"

# Repeated failures abandon the slot so the selector can move on. Reboot is
# suppressed here; on a real appliance each failed boot reboots to retry.
for _ in 1 2 3; do
    MICRON_SLOT_HELPER="$SLOT" MICRON_HEALTHCHECK_FORCE=fail MICRON_HEALTHCHECK_REBOOT=0 \
        MICRON_MAX_FAILS=3 bash "$HEALTH" >/dev/null 2>&1
done
assert_eq 'failed boots clear the good mark' '0' "$(bash "$SLOT" get micron_a_ok)"
assert_eq 'failed boots are counted'         '3' "$(bash "$SLOT" get micron_a_fail)"

# Activating slot B (what an update does) starts it unverified.
bash "$SLOT" activate b >/dev/null
assert_eq 'activated slot becomes active'    'b' "$(bash "$SLOT" active)"
assert_eq 'activated slot is unverified'     '0' "$(bash "$SLOT" get micron_b_ok)"
assert_eq 'activation resets its own count'  '0' "$(bash "$SLOT" get micron_b_fail)"
assert_eq 'the other slot keeps its history' '3' "$(bash "$SLOT" get micron_a_fail)"

MICRON_SLOT_HELPER="$SLOT" MICRON_HEALTHCHECK_FORCE=ok MICRON_HEALTHCHECK_REBOOT=0 \
    bash "$HEALTH" >/dev/null 2>&1
assert_eq 'slot B is promoted once verified' '1' "$(bash "$SLOT" get micron_b_ok)"

# The block must stay a valid 1024-byte GRUB environment block throughout.
assert_eq 'block size stays at 1024 bytes' '1024' "$(wc -c < "${TMP}/grubenv" | tr -d ' ')"

echo
echo "== F. health check probes are wired =="
for probe in management ipc dataplane persistence; do
    assert_file_contains "probe_${probe} is defined" "$HEALTH" "^probe_${probe}\(\)"
done
assert_file_contains 'service unit runs the health check' "$UNIT" 'ExecStart=/usr/local/micron/installer/micron-healthcheck'
assert_file_contains 'unit is wanted by multi-user'        "$UNIT" 'WantedBy=multi-user.target'

echo
if [ "$FAIL" -eq 0 ]; then
    echo "phase0_slot_b_test: PASSED (${PASS} assertions)"
    exit 0
fi
echo "phase0_slot_b_test: FAILED (${FAIL} failed, ${PASS} passed)"
exit 1

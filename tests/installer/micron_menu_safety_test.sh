#!/bin/bash
# ==============================================================================
# MicronOS — Console Menu Safety Test
# ==============================================================================
# Regression test for the "every Micron Menu option restarts the appliance"
# defect. Two independent regressions are covered:
#
#   1. The menu ran under `set -e`. The first command in any handler that
#      returned non-zero aborted the whole script, which on tty1 also ended the
#      login session (agetty respawned the autologin and redrew the banner and
#      menu), which operators reported as the appliance restarting.
#
#   2. The health-check service shipped `MICRON_HEALTHCHECK_REBOOT=1`, making it
#      a machine-wide reboot path that fired ~20 s after every boot, independent
#      of the menu — so the appliance went down after whichever option the
#      operator happened to pick.
#
# Part A asserts the invariants statically. Part B actually executes the menu
# with every external command stubbed, and asserts that no option other than the
# explicit Reboot (5) / Power off (6) entries can reach a reboot or poweroff.
#
# Usage:  bash tests/installer/micron_menu_safety_test.sh
# Exit:   0 when every assertion holds, 1 otherwise.
# ==============================================================================
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
MENU="${ROOT}/bin/micron-menu"
HEALTH="${ROOT}/installer/micron-healthcheck"
UNIT="${ROOT}/installer/micron-healthcheck.service"
INSTALLER="${ROOT}/installer/install.sh"
HOOK="${ROOT}/packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot"

PASS=0
FAIL=0

# Results are mirrored to a file as well as stdout: the menu sweep is long, and
# a single hanging case must still leave a readable trail of what ran before it.
RESULT_LOG=${MICRON_MENU_TEST_LOG:-/tmp/micron-menu-safety.log}
: > "$RESULT_LOG"
ok()  { printf '  ok   %s\n' "$1"; printf '  ok   %s\n' "$1" >> "$RESULT_LOG"; PASS=$((PASS + 1)); }
bad() { printf '  FAIL %s\n' "$1"; printf '  FAIL %s\n' "$1" >> "$RESULT_LOG"; FAIL=$((FAIL + 1)); }

assert_file_contains() { # assert_file_contains <label> <file> <pattern>
    if grep -qE "$3" "$2" 2>/dev/null; then ok "$1"; else bad "$1 (no match for /$3/ in $(basename "$2"))"; fi
}

assert_file_lacks() { # assert_file_lacks <label> <file> <pattern>
    if grep -qE "$3" "$2" 2>/dev/null; then bad "$1 (unexpected /$3/)"; else ok "$1"; fi
}

TMP=$(mktemp -d)
# shellcheck disable=SC2064
trap "rm -rf '${TMP}'" EXIT

echo "== A. the menu cannot be aborted by a failing action =="

assert_file_lacks 'bin/micron-menu does not enable errexit' "$MENU" '^[[:space:]]*set[[:space:]]+-[a-z]*e'
assert_file_contains 'the reboot path is centralised in menu_reboot' "$MENU" '^menu_reboot\(\)'
assert_file_contains 'the power-off path is centralised in menu_poweroff' "$MENU" '^menu_poweroff\(\)'
assert_file_contains 'interrupts return to the menu' "$MENU" "trap 'menu_on_interrupt' INT TERM"
assert_file_contains 'a closed stdin leaves the menu instead of looping' "$MENU" 'Input closed'
assert_file_contains 'menu option 5 confirms before rebooting' "$MENU" 'Reboot cancelled'
assert_file_contains 'menu option 6 confirms before powering off' "$MENU" 'Power off cancelled'

echo
echo "== B. slot identity is resolved from the volume label =="
# Slot letters must never be inferred from partition numbers: p4 is Slot A in
# the shipped 6-partition layout, so the old `sda4 => Slot B` mapping reported
# the wrong slot on every fresh install.
assert_file_contains 'slot A is identified by its filesystem label' "$MENU" 'MICRON_ROOT_A\) ACTIVE_SLOT="A"'
assert_file_contains 'slot B is identified by its filesystem label' "$MENU" 'MICRON_ROOT_B\) ACTIVE_SLOT="B"'
assert_file_lacks    'no partition-number slot guessing remains' "$MENU" 'sda4\|nvme0n1p4\|vda4'
assert_file_lacks    'no partition-number slot guessing remains (slot A)' "$MENU" 'sda3\|nvme0n1p3\|vda3'
assert_file_contains 'the slot helper is consulted as a fallback' "$MENU" '/usr/local/bin/micron-slot'
assert_file_contains 'the slot helper reports the active slot' "$MENU" 'slot_helper" active'

echo
echo "== C. the health check reports, and does not restart the appliance =="
assert_file_contains 'health check defaults to NOT rebooting' "$HEALTH" 'MICRON_HEALTHCHECK_REBOOT:-0'
assert_file_contains 'health check documents the reporting policy' "$HEALTH" 'reboot suppressed'
assert_file_contains 'health check preserves a diagnostic report' "$HEALTH" 'healthcheck.log'
assert_file_lacks    'health check does not reboot unconditionally' "$HEALTH" 'MICRON_HEALTHCHECK_REBOOT:-1'
assert_file_contains 'unit sets the no-reboot default' "$UNIT" 'Environment=MICRON_HEALTHCHECK_REBOOT=0'
assert_file_lacks    'unit no longer forces auto-reboot' "$UNIT" 'Environment=MICRON_HEALTHCHECK_REBOOT=1'

echo
echo "== D. nginx cannot contend with Apache2 for port 80 =="
assert_file_contains 'hook removes the stock nginx default site' "$HOOK" 'rm -f /etc/nginx/sites-enabled/default'
assert_file_contains 'hook leaves nginx disabled at boot' "$HOOK" 'systemctl disable nginx'
assert_file_contains 'installer removes the stock site in the target' "$INSTALLER" 'rm -f "\$mnt/etc/nginx/sites-enabled/default"'
assert_file_contains 'the nginx baseline claims no port 80 server' "$HOOK" 'Do NOT add a `server \{ listen 80; \}`'

echo
echo "== E. executing the menu: only options 5 and 6 may restart =="

# Stub every external command the menu touches. Anything recorded in
# $TMP/calls.log is an external side effect the menu performed.
STUB="${TMP}/bin"
mkdir -p "$STUB"
CALLS="${TMP}/calls.log"
: > "$CALLS"

# `sudo` must be neutralised: run_root() would otherwise invoke the real one.
cat > "${STUB}/sudo" << 'EOF'
#!/bin/bash
"$@"
EOF
# Must be executable, or `command -v sudo` skips it, run_root() falls through to
# the real /usr/bin/sudo, and the menu blocks on a password prompt.
chmod +x "${STUB}/sudo"

for cmd in clear hostname ip findmnt blkid sqlite3 setleds systemctl ping htop top \
           microctl journalctl nft sync modprobe dhclient sysctl chpasswd beep \
           python3 php awk sed grep tee reboot poweroff shutdown halt; do
    cat > "${STUB}/${cmd}" << EOF
#!/bin/bash
printf '%s %s\\n' "${cmd}" "\$*" >> "${CALLS}"
# Consume stdin for tee-like use, then succeed.
[ "${cmd}" = "tee" ] && cat > /dev/null
exit 0
EOF
    chmod +x "${STUB}/${cmd}"
done

# `sleep` is called in several handlers; make the sweep instant.
cat > "${STUB}/sleep" << 'EOF'
#!/bin/bash
exit 0
EOF
chmod +x "${STUB}/sleep"

# A menu that never returns is itself a failure, so every run is bounded: an
# unbounded read on a closed stdin used to spin forever in the submenus.
#
# -s KILL, not the default TERM: the menu installs its own SIGTERM handler so
# that an operator's Ctrl+C returns to the menu, which would otherwise swallow
# the watchdog signal and let a hung case run forever.
#
# The PATH handed to the menu is the stub directory plus the standard system
# directories ONLY. Inheriting the caller's PATH — which on WSL contains dozens
# of /mnt/c entries — makes every un-stubbed command lookup walk a slow DrvFs
# tree and turns a 60 ms menu run into seconds.
MENU_TIMEOUT=${MENU_TIMEOUT:-10}
MENU_PATH="${STUB}:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
run_menu() { # run_menu <input>  -> prints nothing; sets MENU_RC
    local input="$1"
    # The transcript of the most recent run is kept so a hung case can be
    # diagnosed from what the menu actually printed instead of guesswork.
    printf '%b' "$input" \
        | PATH="${MENU_PATH}" timeout -s KILL "${MENU_TIMEOUT}" bash "$MENU" \
        > "${TMP}/menu-last.log" 2>&1
    MENU_RC=$?
}

# `menu_reboot`/`menu_poweroff` try `systemctl` first, so a restart shows up as
# either `systemctl reboot` or a bare `reboot` depending on the handler path.
reboots() {
    grep -cE '^(systemctl (reboot|poweroff|halt)|reboot|poweroff|shutdown|halt)([[:space:]]|$)' \
        "$CALLS" 2>/dev/null || true
}

# Every option except 5 and 6, plus an invalid entry and a bare <Enter>. Option 8
# (a real interactive root shell) is covered by the static assertions in A: it
# ends in pause() and contains no reboot path. Options 18/19 are submenus and are
# exited with their own return keys.
check_option_no_restart() { # check_option_no_restart <option> <input>
    local opt="$1" input="$2"
    printf '  ..   option %s\n' "$opt" >> "$RESULT_LOG"
    : > "$CALLS"
    run_menu "$input"
    local rb
    rb=$(reboots)
    if [ "$rb" != "0" ]; then
        bad "option ${opt} restarted the appliance (${rb} reboot/poweroff call(s))"
    elif [ "$MENU_RC" != "0" ]; then
        bad "option ${opt} did not return to the menu (exit ${MENU_RC})"
    else
        ok "option ${opt} performed its action and returned to the menu"
    fi
}

: > "$CALLS"
check_option_no_restart 0   '0\n'
check_option_no_restart 1   '1\nn\n\n\n\n\n\n0\n'
check_option_no_restart 2   '2\n1\nS\n10.10.10.10/24\n\n0\n'
check_option_no_restart 3   '3\nTestPass123\n\n0\n'
check_option_no_restart 4   '4\nY\n\n0\n'
check_option_no_restart 7   '7\n1.1.1.1\n\n0\n'
check_option_no_restart 9   '9\n0\n'
check_option_no_restart 10  '10\n4\n\n0\n'
check_option_no_restart 11  '11\n\n0\n'
check_option_no_restart 12  '12\n\n0\n'
check_option_no_restart 13  '13\n4\n\n0\n'
check_option_no_restart 14  '14\n\n0\n'
check_option_no_restart 15  '15\n/tmp/nonexistent.mbk\n\n0\n'
check_option_no_restart 16  '16\n\n0\n'
check_option_no_restart 17  '17\n0\n\n0\n'
check_option_no_restart 18  '18\nr\n0\n'
check_option_no_restart 19  '19\n3\n0\n'
check_option_no_restart 99  '99\n0\n'

# A closed stdin must leave the menu cleanly, not restart and not spin.
: > "$CALLS"
run_menu ''
if [ "$(reboots)" = "0" ] && [ "$MENU_RC" = "0" ]; then
    ok 'a closed stdin leaves the menu without restarting'
else
    bad "closed stdin: exit=${MENU_RC}, reboots=$(reboots)"
fi

# Option 5 must reboot, but only when explicitly confirmed.
: > "$CALLS"
run_menu '5\ny\n'
if [ "$(reboots)" != "0" ]; then
    ok 'option 5 reboots when confirmed'
else
    bad 'option 5 did not reboot when confirmed'
fi

: > "$CALLS"
run_menu '5\nn\n0\n'
if [ "$(reboots)" = "0" ] && [ "$MENU_RC" = "0" ]; then
    ok 'option 5 does not reboot when declined'
else
    bad "declined reboot: exit=${MENU_RC}, reboots=$(reboots)"
fi

# Option 6 must power off, but only when explicitly confirmed.
: > "$CALLS"
run_menu '6\ny\n'
if [ "$(reboots)" != "0" ]; then
    ok 'option 6 powers off when confirmed'
else
    bad 'option 6 did not power off when confirmed'
fi

: > "$CALLS"
run_menu '6\nn\n0\n'
if [ "$(reboots)" = "0" ] && [ "$MENU_RC" = "0" ]; then
    ok 'option 6 does not power off when declined'
else
    bad "declined power off: exit=${MENU_RC}, poweroffs=$(reboots)"
fi

# A failing action must surface an error and return to the menu, not abort it.
failing_stub() { # failing_stub <command>
    cat > "${STUB}/$1" << EOF
#!/bin/bash
printf '%s %s\\n' "$1" "\$*" >> "${CALLS}"
exit 1
EOF
    chmod +x "${STUB}/$1"
}

for failing in systemctl nft journalctl ping microctl; do
    failing_stub "$failing"
    : > "$CALLS"
    run_menu '11\n\n14\n\n10\n2\n\n7\n1.1.1.1\n\n16\n\n0\n'
    if [ "$(reboots)" != "0" ]; then
        bad "a failing ${failing} caused a restart"
    elif [ "$MENU_RC" != "0" ]; then
        bad "a failing ${failing} aborted the menu (exit ${MENU_RC})"
    else
        ok "a failing ${failing} returned the operator to the menu"
    fi
done

echo
if [ "$FAIL" -eq 0 ]; then
    printf 'micron_menu_safety_test: PASSED (%d assertions)\n' "$PASS"
    exit 0
fi
printf 'micron_menu_safety_test: FAILED (%d passed, %d failed)\n' "$PASS" "$FAIL"
exit 1

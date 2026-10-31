#!/bin/bash
# ==============================================================================
# micron-network boot path, end to end against a real configuration database.
#
# Runs system/micron-network in a private mount namespace (unshare -m) so that
# /etc/micron, /etc/unbound, /etc/kea, /etc/sysctl.d, /etc/nftables.conf,
# /run/micron and the application's writable/ are throw-away tmpfs/bind mounts.
# Kernel-touching tools (ip, nft, sysctl, systemctl, dhclient, kea-dhcp4,
# unbound-checkconf) are stubs that log their argv; sqlite3 is a small Python
# stand-in. php, python3 and micron_netcfg.py are the real ones.
#
# Proves: the plan, ruleset and DHCP config come from the database (no
# hard-coded LAN address or pool), IPv6 is off by default at every layer, a LAN
# address set in the database is what boot applies, a fresh install leaves the
# WAN/LAN roles unassigned (nothing is guessed; the console assigns them,
# pfSense style), a kickstart choice is adopted, and an assigned 802.1Q VLAN is
# re-created on boot. The NICs boot sees are faked too: a
# tmpfs over /sys/class/net holds one physical port, enp1s0 (not the factory
# eth0, as on most real hardware), so the result does not depend on the host's
# own network cards.
# Requires root (mount namespaces). Skips otherwise.
# ==============================================================================
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
pass=0; fail=0
ok()  { echo "PASS $1"; pass=$((pass + 1)); }
bad() { echo "FAIL $1${2:+  [$2]}"; fail=$((fail + 1)); }

if [ "$(id -u)" -ne 0 ] || ! command -v unshare >/dev/null 2>&1 || ! unshare -m true 2>/dev/null; then
    echo "SKIP: needs root and mount namespaces"; exit 0
fi
command -v php >/dev/null 2>&1 || { echo "SKIP: php not installed"; exit 0; }

T="$(mktemp -d /tmp/micron-netboot.XXXXXX)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/writable/cache" "$T/writable/logs" "$T/writable/session" "$T/log"

# ---- stubs --------------------------------------------------------------------
for tool in nft sysctl systemctl dhclient kea-dhcp4 unbound-checkconf; do
    cat > "$T/bin/$tool" <<EOF
#!/bin/bash
echo "$tool \$*" >> "$T/log/calls"
exit 0
EOF
    chmod +x "$T/bin/$tool"
done
cat > "$T/bin/ip" <<EOF
#!/bin/bash
echo "ip \$*" >> "$T/log/calls"
case "\$*" in
    *-j*) echo "[]" ;;
esac
exit 0
EOF
chmod +x "$T/bin/ip"
cat > "$T/bin/sqlite3" <<'EOF'
#!/usr/bin/env python3
import sqlite3, sys
db, sql = sys.argv[1], sys.argv[2]
c = sqlite3.connect(db)
for row in c.execute(sql):
    print("|".join("" if v is None else str(v) for v in row))
c.commit()
EOF
chmod +x "$T/bin/sqlite3"

# ---- a fresh appliance database ----------------------------------------------
(cd "$ROOT" && env "database.default.database=$T/writable/micron_config.db" MICRON_APPLIANCE=1 php spark migrate --all >/dev/null 2>&1 \
    && env "database.default.database=$T/writable/micron_config.db" MICRON_APPLIANCE=1 php spark db:seed InitialApplianceSeeder >/dev/null 2>&1) \
    || { bad "fresh database could be created"; echo "FAILED"; exit 1; }
sql() { python3 -c "import sqlite3,sys;c=sqlite3.connect(sys.argv[1]);r=c.execute(sys.argv[2]).fetchall();c.commit();print('|'.join(str(x) for x in (r[0] if r else ())))" "$T/writable/micron_config.db" "$1"; }

run_boot() {
    : > "$T/log/calls"
    unshare -m bash -c '
        set -e
        T="$1"; ROOT="$2"
        for d in /etc/micron /etc/unbound /etc/kea /etc/sysctl.d /run/micron /var/lib/kea; do
            mkdir -p "$d"; mount -t tmpfs tmpfs "$d"
        done
        [ -f "$T/seed-init.json" ] && cp "$T/seed-init.json" /etc/micron/network_init.json
        mount -t tmpfs tmpfs /sys/class/net
        mkdir -p /sys/class/net/lo /sys/class/net/enp1s0/device
        export MICRON_NFT_CONF="$T/nftables.conf"
        mount --bind "$T/writable" "$ROOT/writable"
        export PATH="$T/bin:$PATH"
        bash "$ROOT/system/micron-network" > "$T/log/out" 2>&1; rc=$?
        cp -a /etc/micron "$T/etc-micron" 2>/dev/null || true
        cp -a /etc/unbound "$T/etc-unbound" 2>/dev/null || true
        cp -a /etc/kea "$T/etc-kea" 2>/dev/null || true
        cp -a /etc/sysctl.d "$T/etc-sysctl" 2>/dev/null || true
        exit $rc
    ' _ "$T" "$ROOT"
}

# ---- first boot of a fresh install: nothing is assigned, nothing is guessed --
rm -rf "$T/etc-"*
run_boot
rc=$?
[ "$rc" -eq 0 ] && ok "micron-network completes with unassigned interfaces" || bad "unassigned boot completes" "rc=$rc $(tail -5 "$T/log/out")"
[ -z "$(sql "SELECT physical_device FROM interfaces WHERE id IN ('wan','lan') AND physical_device != ''")" ] \
    && ok "a fresh install does not bind WAN/LAN to a guessed port" || bad "no guessed roles" "$(sql "SELECT id, physical_device FROM interfaces")"
! grep -q "ip addr replace" "$T/log/calls" && ok "no address is put on an unassigned port" || bad "unassigned address" "$(grep 'ip addr' "$T/log/calls")"
grep -q '"configured": false' "$T/etc-micron/network_init.json" 2>/dev/null && grep -q "not assigned yet" "$T/log/out" \
    && ok "boot records and logs that interfaces still need assigning" || bad "unassigned state recorded" "$(cat "$T/etc-micron/network_init.json" 2>/dev/null)"

# ---- the installer recorded a kickstart choice: it is adopted once ------------
printf '{\n  "wan_interface": "",\n  "lan_interface": "enp1s0",\n  "configured": true\n}\n' > "$T/seed-init.json"
rm -rf "$T/etc-"*
run_boot
rc=$?
rm -f "$T/seed-init.json"
[ "$(sql "SELECT physical_device FROM interfaces WHERE id='lan'")" = "enp1s0" ] && ok "a kickstart LAN choice is adopted into the database" || bad "kickstart adopt"
[ "$rc" -eq 0 ] && ok "micron-network completes" || bad "micron-network completes" "rc=$rc $(tail -5 "$T/log/out")"

plan="$T/etc-micron/network.json"
[ -s "$plan" ] && ok "the plan built from the database was committed" || bad "the plan built from the database was committed" "$(cat "$T/log/out")"
python3 - "$plan" <<'PY' && ok "the committed plan has IPv6 off and the LAN from the database (10.10.10.10/24)" || bad "plan contents" "$(cat "$plan" 2>/dev/null)"
import json, sys
p = json.load(open(sys.argv[1]))
lan = next(i for i in p["interfaces"] if i["id"] == "lan")
assert p["ipv6_enabled"] is False
assert lan["ipv4"] == "10.10.10.10/24"
PY
grep -q "disable_ipv6 = 1" "$T/etc-sysctl/60-micron-ipv6.conf" 2>/dev/null && ok "IPv6 is disabled persistently (sysctl drop-in)" || bad "IPv6 sysctl drop-in"
grep -q "net.ipv6.conf.all.disable_ipv6=1" "$T/log/calls" && ok "IPv6 is disabled in the running kernel" || bad "runtime sysctl" "$(grep sysctl "$T/log/calls" | head -3)"
grep -q "ip addr replace 10.10.10.10/24 dev enp1s0" "$T/log/calls" && ok "the LAN address is applied to the single physical port" || bad "LAN address applied" "$(grep 'ip addr' "$T/log/calls")"
[ -z "$(sql "SELECT physical_device FROM interfaces WHERE id='wan'")" ] && ok "the WAN stays unassigned (no port is doubled up)" || bad "WAN unassigned"
grep -q "meta nfproto ipv6 drop" "$T/nftables.conf" && ok "the loaded ruleset is the compiled one and drops IPv6" || bad "compiled ruleset installed" "$(head -5 "$T/nftables.conf")"
grep -q 'ip saddr 10.10.10.0/24 ip daddr 10.10.10.10 th dport 443 accept' "$T/nftables.conf" && ok "factory LAN rules are compiled from the LAN address (no open accept)" || bad "factory rule compilation"
! grep -q "interface: ::0" "$T/etc-unbound/unbound.conf" && grep -q "do-ip6: no" "$T/etc-unbound/unbound.conf" \
    && ok "Unbound has no IPv6 listener while IPv6 is off" || bad "unbound ipv6" "$(cat "$T/etc-unbound/unbound.conf" 2>/dev/null | head -8)"
[ ! -s "$T/etc-kea/kea-dhcp4.conf" ] && ok "no DHCP server config is installed while DHCP is disabled" || bad "kea while disabled"

# ---- second boot: the administrator moved the LAN and enabled DHCP ------------
sql "UPDATE interfaces SET ipv4_address='172.30.5.1', ipv4_netmask=24 WHERE id='lan'" >/dev/null
sql "UPDATE dhcp_server_settings SET enabled=1, range_from='172.30.5.100', range_to='172.30.5.150', gateway='172.30.5.1', dns_1='172.30.5.1' WHERE interface_id='lan'" >/dev/null
rm -rf "$T/etc-"*
run_boot
grep -q "ip addr replace 172.30.5.1/24 dev enp1s0" "$T/log/calls" && ok "a reboot applies the address in the database, never an old one" || bad "reboot address" "$(grep 'ip addr' "$T/log/calls")"
! grep -q "10.10.10" "$T/nftables.conf" && grep -q "172.30.5.0/24" "$T/nftables.conf" && ok "firewall rules follow the new LAN at boot" || bad "ruleset after move"
grep -q '172.30.5.100 - 172.30.5.150' "$T/etc-kea/kea-dhcp4.conf" 2>/dev/null && ok "the DHCP server config comes from the database" || bad "kea from db" "$(cat "$T/etc-kea/kea-dhcp4.conf" 2>/dev/null | head -c 300)"
grep -q "systemctl restart --no-block kea-dhcp4-server" "$T/log/calls" && ok "the DHCP server is restarted with it" || bad "kea restart"

# ---- an assigned VLAN is re-created before the plan is applied ----------------
sql "UPDATE interfaces SET physical_device='enp1s0.20', enabled=1 WHERE id='wan'" >/dev/null
rm -rf "$T/etc-"*
run_boot
grep -q "ip link add link enp1s0 name enp1s0.20 type vlan id 20" "$T/log/calls" && ok "an assigned 802.1Q VLAN is re-created at boot" || bad "vlan recreate" "$(grep 'link add' "$T/log/calls")"
sql "UPDATE interfaces SET physical_device='' WHERE id='wan'" >/dev/null

# ---- third boot: IPv6 switched on ---------------------------------------------
sql "UPDATE system_settings SET ipv6_enabled=1 WHERE id=1" >/dev/null
rm -rf "$T/etc-"*
run_boot
grep -q "disable_ipv6 = 0" "$T/etc-sysctl/60-micron-ipv6.conf" 2>/dev/null && ok "an enabled IPv6 switch survives a reboot" || bad "ipv6 on after reboot"
grep -q "interface: ::0" "$T/etc-unbound/unbound.conf" && ok "Unbound listens on IPv6 when it is on" || bad "unbound v6 on"
! grep -q "meta nfproto ipv6 drop" "$T/nftables.conf" && ok "the firewall passes IPv6 when it is on" || bad "nft v6 on"

echo
echo "micron-network boot: $pass passed, $fail failed"
[ "$fail" -eq 0 ]

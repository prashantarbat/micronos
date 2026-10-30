"""
Micron OS network engine + micrond network transactions
=======================================================

Standalone harness (run: `python3 tests/test_micron_netcfg.py`).

A fake kernel stands in for `ip`, `sysctl`, `nft`, `dhclient` and
`systemctl`, so every path — validation, apply, verify, restore, confirm and
expiry, IPv6 on/off, ifupdown hand-over — is exercised without touching the
host.
"""
import asyncio
import importlib.util
import json
import os
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


netcfg = load("micron_netcfg", os.path.join(ROOT, "system", "micron_netcfg.py"))
micrond = load("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


class FakeKernel:
    """Just enough of iproute2/sysctl/nft for the engine."""

    def __init__(self):
        self.addr = {"enp1s0": [], "enp2s0": ["10.10.10.10/24"]}
        self.v6 = {"enp1s0": [], "enp2s0": []}
        self.routes = []
        self.sysctl = {"net.ipv6.conf.all.disable_ipv6": "0"}
        self.calls = []
        self.fail = set()          # substrings that make a command fail
        self.nft_loaded = None

    def _ok(self, out=""):
        return subprocess.CompletedProcess([], 0, out, "")

    def __call__(self, argv):
        self.calls.append(list(argv))
        line = " ".join(argv)
        for f in self.fail:
            if f in line:
                return subprocess.CompletedProcess(argv, 2, "", f"simulated failure: {f}")
        if argv[:2] == ["ip", "-j"]:
            if "addr" in argv:
                dev = argv[argv.index("dev") + 1]
                if "-6" in argv:
                    info = [{"family": "inet6", "local": a.split("/")[0], "prefixlen": int(a.split("/")[1])} for a in self.v6.get(dev, [])]
                else:
                    info = [{"family": "inet", "scope": "global", "local": a.split("/")[0], "prefixlen": int(a.split("/")[1])} for a in self.addr.get(dev, [])]
                return self._ok(json.dumps([{"ifname": dev, "addr_info": info}]))
            if "route" in argv:
                return self._ok(json.dumps([{"dst": "default", "gateway": g, "dev": d} for g, d in self.routes]))
        if argv[:3] == ["ip", "addr", "del"]:
            dev = argv[argv.index("dev") + 1]
            if argv[3] in self.addr.get(dev, []):
                self.addr[dev].remove(argv[3])
            return self._ok()
        if argv[:3] == ["ip", "addr", "replace"]:
            dev = argv[argv.index("dev") + 1]
            self.addr.setdefault(dev, [])
            if argv[3] not in self.addr[dev]:
                self.addr[dev].append(argv[3])
            return self._ok()
        if argv[:4] == ["ip", "-6", "addr", "replace"]:
            self.v6.setdefault(argv[argv.index("dev") + 1], []).append(argv[4])
            return self._ok()
        if argv[:4] == ["ip", "route", "replace", "default"]:
            self.routes = [(argv[5], argv[7])]
            return self._ok()
        if argv[:4] == ["ip", "route", "del", "default"]:
            self.routes = [r for r in self.routes if r[0] != argv[5]]
            return self._ok()
        if argv[0] == "sysctl":
            k, v = argv[-1].split("=", 1)
            self.sysctl[k] = v
            if k == "net.ipv6.conf.all.disable_ipv6" and v == "1":
                for d in self.v6:
                    self.v6[d] = []
            return self._ok()
        if argv[:2] == ["nft", "-f"]:
            with open(argv[2], encoding="utf-8") as fh:
                self.nft_loaded = fh.read()
            return self._ok()
        if argv[:2] == ["nft", "-c"]:
            return self._ok()
        if argv[:3] == ["nft", "list", "ruleset"]:
            return self._ok(self.nft_loaded or "table inet old {}\n")
        return self._ok()


TMP = tempfile.mkdtemp(prefix="micron-netcfg-test-")
netcfg.PLAN_PATH = os.path.join(TMP, "network.json")
netcfg.IPV6_SYSCTL_PATH = os.path.join(TMP, "60-micron-ipv6.conf")
netcfg.IFUPDOWN_PATH = os.path.join(TMP, "interfaces")
netcfg.IFUPDOWN_BACKUP = os.path.join(TMP, "interfaces.micron-orig")
netcfg.device_exists = lambda dev: dev in ("enp1s0", "enp2s0", "enp3s0")
micrond.netcfg = netcfg
micrond.NETCFG_PENDING = os.path.join(TMP, "pending.json")
micrond.NETCFG_LAST = os.path.join(TMP, "last.json")
micrond.NFTABLES_CONF = os.path.join(TMP, "nftables.conf")
micrond.KEA_DHCP4_CONF = os.path.join(TMP, "kea-dhcp4.conf")
micrond.KEA_MANAGED_MARKER = os.path.join(TMP, "managed", "kea")
# effective_ipv6 reads /proc; follow the fake kernel instead
KERNEL = FakeKernel()
netcfg.effective_ipv6 = lambda read=None: KERNEL.sysctl.get("net.ipv6.conf.all.disable_ipv6", "0") == "0"


def plan(lan="10.10.10.10/24", wan_mode="dhcp", wan_ip=None, gw=None, v6=False, lan_dev="enp2s0"):
    return {"ipv6_enabled": v6, "interfaces": [
        {"id": "wan", "role": "wan", "device": "enp1s0", "enabled": True, "mode": wan_mode,
         "ipv4": wan_ip, "gateway": gw, "mtu": 1500, "ipv6_mode": "slaac" if v6 else "none"},
        {"id": "lan", "role": "lan", "device": lan_dev, "enabled": True, "mode": "static",
         "ipv4": lan, "gateway": None, "mtu": 1500, "ipv6_mode": "none"},
    ]}


# ---------------------------------------------------------------- validation
ok, errs = netcfg.validate_plan(plan())
check("a well-formed plan validates", ok is not None and errs == [], str(errs))
for label, bad, needle in [
    ("network address", plan(lan="10.10.10.0/24"), "network or broadcast"),
    ("broadcast address", plan(lan="10.10.10.255/24"), "network or broadcast"),
    ("overlapping subnets", plan(lan="192.0.2.10/24", wan_mode="static", wan_ip="192.0.2.20/24"), "overlaps"),
    ("gateway outside the subnet", plan(wan_mode="static", wan_ip="198.51.100.2/24", gw="203.0.113.1"), "outside"),
    ("gateway equal to own address", plan(wan_mode="static", wan_ip="198.51.100.2/24", gw="198.51.100.2"), "own address"),
    ("bad device name", plan(lan_dev="eth0;reboot"), "invalid kernel device"),
    ("same device twice", plan(lan_dev="enp1s0"), "already used"),
    ("missing prefix", plan(lan="10.1.1.1"), "with prefix"),
]:
    r, e = netcfg.validate_plan(bad)
    check(f"validation refuses {label}", r is None and any(needle in x for x in e), str(e))
two_gw = plan(wan_mode="static", wan_ip="198.51.100.2/24", gw="198.51.100.1")
two_gw["interfaces"][1]["gateway"] = "10.10.10.1"
r, e = netcfg.validate_plan(two_gw)
check("validation refuses two static default gateways", r is None and any("only one" in x for x in e), str(e))

# ------------------------------------------------------------ ifupdown hand-over
legacy = """auto lo
iface lo inet loopback

auto br0
allow-hotplug br0
iface br0 inet static
    address 10.10.10.10
    netmask 255.255.255.0
    bridge_ports enp2s0 enp3s0
    bridge_stp on

allow-hotplug enp1s0
iface enp1s0 inet dhcp
"""
out = netcfg.neutralize_ifupdown(legacy, ["br0", "enp1s0"])
check("installer static stanza no longer carries an address", "address 10.10.10.10" not in out and "netmask" not in out, out)
check("bridge membership is kept so the bridge is still created", "bridge_ports enp2s0 enp3s0" in out, out)
check("managed devices become inet manual", "iface br0 inet manual" in out and "iface enp1s0 inet manual" in out, out)
check("loopback is untouched", "iface lo inet loopback" in out, out)
check("hand-over is idempotent", netcfg.neutralize_ifupdown(out, ["br0", "enp1s0"]) == out)

# ------------------------------------------------------------ apply: LAN change
with open(netcfg.IFUPDOWN_PATH, "w") as fh:
    fh.write("auto lo\niface lo inet loopback\n\nauto enp2s0\niface enp2s0 inet static\n    address 10.10.10.10\n    netmask 255.255.255.0\n")
p1, _ = netcfg.validate_plan(plan())
netcfg.apply_plan(KERNEL, p1, None)
p2, _ = netcfg.validate_plan(plan(lan="192.168.50.1/24"))
res = netcfg.apply_plan(KERNEL, p2, p1)
check("LAN address change applies without errors", res["errors"] == [], str(res))
check("the old LAN address is removed, the new one assigned", KERNEL.addr["enp2s0"] == ["192.168.50.1/24"], str(KERNEL.addr))
check("the committed plan holds the new address", "192.168.50.1/24" in open(netcfg.PLAN_PATH).read())
check("/etc/network/interfaces can no longer restore the old address on reboot",
      "address 10.10.10.10" not in open(netcfg.IFUPDOWN_PATH).read() and os.path.exists(netcfg.IFUPDOWN_BACKUP))
check("verify agrees with the kernel", netcfg.verify(KERNEL, p2) == [])

# ------------------------------------------------------------ apply: static WAN
p3, _ = netcfg.validate_plan(plan(lan="192.168.50.1/24", wan_mode="static", wan_ip="198.51.100.2/24", gw="198.51.100.1"))
netcfg.apply_plan(KERNEL, p3, p2)
check("static WAN address and default route are applied",
      KERNEL.addr["enp1s0"] == ["198.51.100.2/24"] and KERNEL.routes == [("198.51.100.1", "enp1s0")], str((KERNEL.addr, KERNEL.routes)))
p4, _ = netcfg.validate_plan(plan(lan="192.168.50.1/24", wan_mode="static", wan_ip="198.51.100.9/24", gw="198.51.100.254"))
netcfg.apply_plan(KERNEL, p4, p3)
check("a WAN address and gateway change replaces both",
      KERNEL.addr["enp1s0"] == ["198.51.100.9/24"] and KERNEL.routes == [("198.51.100.254", "enp1s0")], str((KERNEL.addr, KERNEL.routes)))
p5, _ = netcfg.validate_plan(plan(lan="192.168.50.1/24"))
netcfg.apply_plan(KERNEL, p5, p4)
check("going back to DHCP removes the static address and static default route",
      "198.51.100.9/24" not in KERNEL.addr["enp1s0"] and KERNEL.routes == [], str((KERNEL.addr, KERNEL.routes)))

# ------------------------------------------------------------ IPv6 switch
p6, _ = netcfg.validate_plan(plan(lan="192.168.50.1/24", v6=True))
netcfg.apply_plan(KERNEL, p6, p5)
sysctl_text = open(netcfg.IPV6_SYSCTL_PATH).read()
check("enabling IPv6 is persistent (sysctl drop-in) and live",
      "net.ipv6.conf.all.disable_ipv6 = 0" in sysctl_text and KERNEL.sysctl["net.ipv6.conf.all.disable_ipv6"] == "0", sysctl_text)
check("only the WAN accepts router advertisements", KERNEL.sysctl.get("net.ipv6.conf.enp1s0.accept_ra") == "2"
      and KERNEL.sysctl.get("net.ipv6.conf.enp2s0.accept_ra") == "0", str(KERNEL.sysctl))
p7, _ = netcfg.validate_plan(plan(lan="192.168.50.1/24", v6=False))
netcfg.apply_plan(KERNEL, p7, p6)
sysctl_text = open(netcfg.IPV6_SYSCTL_PATH).read()
check("disabling IPv6 writes all/default/lo/per-device keys",
      all(k in sysctl_text for k in ("all.disable_ipv6 = 1", "default.disable_ipv6 = 1", "lo.disable_ipv6 = 1",
                                     "enp1s0.disable_ipv6 = 1", "enp2s0.disable_ipv6 = 1")), sysctl_text)
check("disabling IPv6 is applied to the running kernel too",
      KERNEL.sysctl["net.ipv6.conf.all.disable_ipv6"] == "1" and KERNEL.sysctl["net.ipv6.conf.enp2s0.disable_ipv6"] == "1")
check("IPv4 addressing is untouched by the IPv6 switch", KERNEL.addr["enp2s0"] == ["192.168.50.1/24"])

# ------------------------------------------------------------ snapshot/restore
snap = netcfg.snapshot(KERNEL, ["enp1s0", "enp2s0"])
KERNEL.addr["enp2s0"] = ["172.16.0.1/24"]
netcfg.restore(KERNEL, snap)
check("restore puts the previous addresses back", KERNEL.addr["enp2s0"] == ["192.168.50.1/24"], str(KERNEL.addr))

# ------------------------------------------------------------ boot
with open(os.path.join(TMP, "fresh.json"), "w") as fh:
    json.dump(plan(lan="192.168.77.1/24"), fh)
KERNEL.addr["enp2s0"] = ["10.10.10.10/24"]          # what ifupdown would have restored
rc = netcfg.boot(os.path.join(TMP, "fresh.json"), run=KERNEL)
check("boot applies the plan generated from the database", rc == 0 and KERNEL.addr["enp2s0"] == ["192.168.77.1/24"], str(KERNEL.addr))
with open(os.path.join(TMP, "broken.json"), "w") as fh:
    fh.write("{not json")
KERNEL.addr["enp2s0"] = ["10.10.10.10/24"]
rc = netcfg.boot(os.path.join(TMP, "broken.json"), run=KERNEL)
check("boot falls back to the last committed plan when the new one is unusable",
      rc == 0 and KERNEL.addr["enp2s0"] == ["192.168.77.1/24"], str(KERNEL.addr))

# ------------------------------------------------------------ micrond transaction
loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)
handler._run = KERNEL
handler._net_run = KERNEL


def rpc(method, params=None):
    resp = loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}, None))
    return resp.get("result", {"_error": resp.get("error")})


RULES_NEW = "table inet micron_filter { }\n# lan 192.168.88.0/24\n"
res = rpc("network.apply", {"plan": plan(lan="192.168.88.1/24"), "ruleset": RULES_NEW})
check("network.apply commits a valid change", res.get("status") == "applied", str(res))
check("the firewall ruleset is loaded and persisted in the same transaction",
      KERNEL.nft_loaded == RULES_NEW and open(micrond.NFTABLES_CONF).read() == RULES_NEW)

before_addr = list(KERNEL.addr["enp2s0"])
before_rules = KERNEL.nft_loaded
res = rpc("network.apply", {"plan": plan(lan="10.0.0.0/24"), "ruleset": RULES_NEW})
check("an invalid plan is refused before anything changes",
      res.get("status") == "invalid" and KERNEL.addr["enp2s0"] == before_addr, str(res))

KERNEL.fail = {"ip addr replace 172.20.0.1/24"}
res = rpc("network.apply", {"plan": plan(lan="172.20.0.1/24"), "ruleset": "table inet x { }\n"})
KERNEL.fail = set()
check("a failure during apply rolls everything back",
      res.get("status") == "rolled_back" and KERNEL.addr["enp2s0"] == before_addr and KERNEL.nft_loaded == before_rules,
      str((res.get("status"), KERNEL.addr, res.get("errors"))))
check("the committed plan is unchanged after a rollback", "192.168.88.1/24" in open(netcfg.PLAN_PATH).read())

KERNEL.fail = {"nft -f"}
res = rpc("network.apply", {"plan": plan(lan="172.21.0.1/24"), "ruleset": "table inet y { }\n"})
KERNEL.fail = set()
check("a firewall load failure restores the previous addresses",
      res.get("status") == "rolled_back" and KERNEL.addr["enp2s0"] == before_addr, str(res))

res = rpc("network.apply", {"plan": plan(lan="192.168.99.1/24"), "ruleset": RULES_NEW, "confirm_timeout": 60})
check("a management-affecting change waits for confirmation", res.get("status") == "pending_confirm" and res.get("tx"), str(res))
check("while pending, the committed plan still holds the old address", "192.168.88.1/24" in open(netcfg.PLAN_PATH).read())
check("a second change is refused while one is pending",
      rpc("network.apply", {"plan": plan(lan="192.168.100.1/24")}).get("status") == "busy")
st = rpc("network.status")
check("network.status reports the pending transaction", (st.get("pending") or {}).get("tx") == res["tx"], str(st.get("pending")))
conf = rpc("network.confirm", {"tx": res["tx"]})
check("confirming commits the new plan", conf.get("status") == "applied" and "192.168.99.1/24" in open(netcfg.PLAN_PATH).read(), str(conf))

res = rpc("network.apply", {"plan": plan(lan="192.168.111.1/24"), "ruleset": RULES_NEW, "confirm_timeout": 60})
handler._net_expire(res["tx"])
check("an unconfirmed change is rolled back when the timer expires",
      KERNEL.addr["enp2s0"] == ["192.168.99.1/24"] and not os.path.exists(micrond.NETCFG_PENDING), str(KERNEL.addr))
late = rpc("network.confirm", {"tx": res["tx"]})
check("a late confirmation reports the rollback", late.get("status") == "rolled_back", str(late))

res = rpc("network.apply", {"plan": plan(lan="192.168.112.1/24"), "confirm_timeout": 60})
with open(micrond.NETCFG_PENDING) as fh:
    pend = netcfg.loads(fh.read())
pend["deadline"] = 0
with open(micrond.NETCFG_PENDING, "w") as fh:
    json.dump(pend, fh)
check("a pending change found at daemon start is rolled back", handler.net_recover_on_start() == "rolled_back"
      and KERNEL.addr["enp2s0"] == ["192.168.99.1/24"], str(KERNEL.addr))

res = rpc("system.ipv6.set", {"enabled": True})
check("system.ipv6.set switches IPv6 on the committed plan", res.get("status") == "applied"
      and netcfg.loads(open(netcfg.PLAN_PATH).read())["ipv6_enabled"] is True, str(res))
res = rpc("system.ipv6.set", {"enabled": False})
check("and back off, persistently", res.get("status") == "applied"
      and "all.disable_ipv6 = 1" in open(netcfg.IPV6_SYSCTL_PATH).read(), str(res))
old_plan, _ = netcfg.validate_plan(plan(lan="192.168.99.1/24"))
new_plan, _ = netcfg.validate_plan(plan(lan="192.168.99.1/24", lan_dev="enp3s0"))
KERNEL.addr["enp2s0"] = ["192.168.99.1/24"]
KERNEL.addr["enp3s0"] = []
move_result = netcfg.apply_plan(KERNEL, new_plan, old_plan, persist=False)
check("moving the LAN role removes its old address and applies it to the new device",
    move_result["errors"] == [] and KERNEL.addr["enp2s0"] == []
    and KERNEL.addr["enp3s0"] == ["192.168.99.1/24"], str((move_result, KERNEL.addr)))
check("network.status is a read-class method", micrond.rpc_method_class("network.status") == "read")
check("network.apply is destructive-class (operators only)", micrond.rpc_method_class("network.apply") == "destructive")

if failures:
    print(f"\nFAILED: {len(failures)} check(s): " + "; ".join(failures))
    sys.exit(1)
print("\nALL NETWORK ENGINE CHECKS PASSED")

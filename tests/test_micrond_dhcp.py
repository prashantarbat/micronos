#!/usr/bin/env python3
"""
MicronOS micrond DHCP server (Kea) RPC verification
==================================================

Standalone harness for the DHCP server handlers (`dhcp.apply`, `dhcp.restart`,
`dhcp.status`). `kea-dhcp4`, `systemctl` and the config path are stubbed/temp,
so the validate -> write -> restart contract is checked on a real filesystem.

  python3 tests/test_micrond_dhcp.py
"""
import asyncio
import importlib.util
import json
import os
import subprocess
import sys
import tempfile

from _support.strictdecode import strict_json_loads

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

spec = importlib.util.spec_from_file_location("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))
micrond = importlib.util.module_from_spec(spec)
spec.loader.exec_module(micrond)

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


CONF_DIR = tempfile.mkdtemp(prefix="micron-kea-")
micrond.KEA_DHCP4_CONF = os.path.join(CONF_DIR, "kea-dhcp4.conf")

loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)

calls = []
# Force `kea-dhcp4 -t` to fail on the next apply; systemctl always succeeds.
kea_rc = {"value": 0}


def fake_run(argv):
    calls.append(list(argv))
    if list(argv[:1]) == [micrond.KEA_DHCP4_BIN]:
        return subprocess.CompletedProcess(argv, kea_rc["value"], "kea test output", "")
    if list(argv[:2]) == ["systemctl", "is-active"]:
        return subprocess.CompletedProcess(argv, 0, "active", "")
    return subprocess.CompletedProcess(argv, 0, "", "")


handler._run = fake_run


def dispatch(method, params):
    return loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "method": method, "params": params, "id": 1}
    ))["result"]


def reset():
    calls.clear()
    kea_rc["value"] = 0


def read_conf():
    with open(micrond.KEA_DHCP4_CONF, encoding="utf-8") as handle:
        return handle.read()


def good_config():
    return json.dumps({
        "Dhcp4": {
            "interfaces-config": {"interfaces": ["eth1"]},
            "lease-database": {"type": "memfile", "persist": True, "name": "/var/lib/kea/kea-leases4.csv"},
            "subnet4": [
                {
                    "id": 1,
                    "subnet": "192.168.1.0/24",
                    "interface": "eth1",
                    "pools": [{"pool": "192.168.1.10 - 192.168.1.245"}],
                    "option-data": [{"name": "routers", "data": "192.168.1.1"}],
                    "reservations": [
                        {"hw-address": "00:11:22:33:44:55", "ip-address": "192.168.1.50", "hostname": "printer"},
                    ],
                }
            ],
        }
    })


print("=========================================================")
print("   MicronOS micrond dhcp.* (Kea server) RPC Verification")
print("=========================================================\n")

# 0. The harness parses configs strictly: it must not accept a document Kea
#    itself would refuse. json.loads already rejects trailing bytes, but it
#    silently accepts the non-standard NaN / Infinity constants; the strict
#    helper must reject both.
try:
    strict_json_loads('{"n": NaN}')
    nan_rejected = False
except ValueError:
    nan_rejected = True
check("the strict parser rejects the non-standard NaN constant", nan_rejected)

try:
    strict_json_loads('{"a": 1} trailing')
    trailing_rejected = False
except json.JSONDecodeError:
    trailing_rejected = True
check("the strict parser rejects trailing data after the document", trailing_rejected)

# 1. Apply a valid config: kea -t then write then restart, file holds the JSON.
reset()
if os.path.exists(micrond.KEA_DHCP4_CONF):
    os.remove(micrond.KEA_DHCP4_CONF)
res = dispatch("dhcp.apply", {"config": good_config()})
check("apply valid config -> success", res.get("success") is True, json.dumps(res)[:200])
check("apply reports status applied", res.get("status") == "applied")
check("apply ran kea-dhcp4 -t", any(c[:1] == [micrond.KEA_DHCP4_BIN] and "-t" in c for c in calls))
check("apply restarted kea unit",
      any(c == ["systemctl", "restart", micrond.KEA_DHCP4_UNIT] for c in calls))
check("apply wrote the config file", os.path.exists(micrond.KEA_DHCP4_CONF))
written = strict_json_loads(read_conf())
check("written config preserves subnet", written["Dhcp4"]["subnet4"][0]["subnet"] == "192.168.1.0/24")
check("written config preserves reservation",
      written["Dhcp4"]["subnet4"][0]["reservations"][0]["ip-address"] == "192.168.1.50")
check("apply reports service running", res.get("service") == "running")

# 2. A config Kea rejects must not be written and must not restart the unit.
reset()
before = read_conf()
kea_rc["value"] = 1
res = dispatch("dhcp.apply", {"config": good_config()})
check("rejected config -> success false", res.get("success") is False)
check("rejected config -> status error", res.get("status") == "error")
check("rejected config mentions kea", "kea" in (res.get("message") or "").lower(), str(res.get("message")))
check("rejected config leaves file untouched", read_conf() == before)
check("rejected config does NOT restart unit",
      not any(c[:2] == ["systemctl", "restart"] for c in calls))

# 3. Invalid JSON string is refused before any command runs.
reset()
res = dispatch("dhcp.apply", {"config": "{not json"})
check("invalid JSON -> success false", res.get("success") is False)
check("invalid JSON runs no command", len([c for c in calls if c[:1] == [micrond.KEA_DHCP4_BIN]]) == 0)

# 4. Missing / empty config is refused.
reset()
check("missing config -> error", dispatch("dhcp.apply", {}).get("success") is False)
check("empty config -> error", dispatch("dhcp.apply", {"config": "  "}).get("success") is False)

# 5. Structurally invalid configs (bad CIDR, bad MAC, bad pool) are refused.
reset()
bad = strict_json_loads(good_config())
bad["Dhcp4"]["subnet4"][0]["subnet"] = "not-a-subnet"
r = dispatch("dhcp.apply", {"config": json.dumps(bad)})
check("bad subnet CIDR -> error", r.get("success") is False and "subnet" in (r.get("message") or ""))

bad = strict_json_loads(good_config())
bad["Dhcp4"]["subnet4"][0]["reservations"][0]["hw-address"] = "ZZ:ZZ"
r = dispatch("dhcp.apply", {"config": json.dumps(bad)})
check("bad reservation MAC -> error", r.get("success") is False and "hw-address" in (r.get("message") or ""))

bad = strict_json_loads(good_config())
bad["Dhcp4"]["subnet4"][0]["pools"][0]["pool"] = "192.168.1.10-192.168.1.245"
r = dispatch("dhcp.apply", {"config": json.dumps(bad)})
check("bad pool range -> error", r.get("success") is False)

# 6. Too many subnets is refused.
reset()
big = {"Dhcp4": {"subnet4": [{"id": i, "subnet": "10.0.0.0/24"} for i in range(micrond.KEA_MAX_SUBNETS + 1)]}}
r = dispatch("dhcp.apply", {"config": json.dumps(big)})
check("too many subnets -> error", r.get("success") is False and "subnet" in (r.get("message") or ""))

# 7. dhcp.restart without a config restarts only (no write, no kea -t).
reset()
before = read_conf()
res = dispatch("dhcp.restart", {})
check("restart (no config) -> success", res.get("success") is True)
check("restart (no config) restarted unit",
      any(c == ["systemctl", "restart", micrond.KEA_DHCP4_UNIT] for c in calls))
check("restart (no config) did not run kea -t",
      not any(c[:1] == [micrond.KEA_DHCP4_BIN] for c in calls))
check("restart (no config) left config untouched", read_conf() == before)

# 8. dhcp.restart *with* a config takes the full apply path.
reset()
res = dispatch("dhcp.restart", {"config": good_config()})
check("restart with config -> applied", res.get("success") is True and res.get("status") == "applied")
check("restart with config ran kea -t", any(c[:1] == [micrond.KEA_DHCP4_BIN] for c in calls))

# 9. dhcp.status reports the service state and config presence.
reset()
res = dispatch("dhcp.status", {})
check("status -> ok", res.get("status") == "ok")
check("status -> service running", res.get("service") == "running")
check("status -> config present", res.get("config") is True)

print()
if failures:
    print(f"FAILED ({len(failures)}): " + ", ".join(failures))
    sys.exit(1)
print("ALL DHCP SERVER (KEA) RPC CHECKS PASSED")

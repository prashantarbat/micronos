#!/usr/bin/env python3
"""
MicronOS micrond Multicast / IGMP RPC verification
==================================================

Standalone harness for the multicast.apply and multicast.status handlers.
External commands and file writes are stubbed, so this runs anywhere Python 3
does without igmpproxy / FRR / sysfs present.

  python3 tests/test_micrond_multicast.py
"""
import asyncio
import importlib.util
import os
import subprocess
import sys

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

calls = []


def fake_run(argv):
    calls.append(list(argv))
    return subprocess.CompletedProcess(argv, 0, "active", "")


handler._run = fake_run

# Capture file writes instead of touching the real filesystem.
writes = []


def fake_write(path, content):
    writes.append((path, content))
    return True


handler._write_text = fake_write

print("=========================================================")
print("   MicronOS micrond Multicast / IGMP RPC Verification")
print("=========================================================\n")

# ---- Disabled: everything stopped, nothing written ------------------------
calls.clear()
writes.clear()
res = loop.run_until_complete(handler.rpc_multicast_apply({"enabled": False}))
check("disabled apply succeeds", res.get("success") is True and res.get("status") == "disabled", str(res))
check("apply reports daemon run-state",
      isinstance(res.get("services"), dict)
      and {"igmpproxy", "mdns-repeater"}.issubset(res["services"].keys()),
      str(res.get("services")))
check("disabled apply stops igmpproxy", ["systemctl", "stop", "igmpproxy"] in calls, str(calls))
check("disabled apply stops mdns-repeater", ["systemctl", "stop", "mdns-repeater"] in calls, str(calls))
check("disabled apply writes nothing", writes == [], str(writes))

# ---- IGMP proxy + PIM + sysfs + mDNS -------------------------------------
calls.clear()
writes.clear()
payload = {
    "enabled": True,
    "igmp_proxy": {"enabled": True, "config": "quickleave\nphyint wan0 upstream\n"},
    "pim": {"enabled": True, "commands": "configure terminal\nrouter pim\nend\n"},
    "snooping": {"bridge": "br-lan", "sysfs": {"multicast_snooping": "1", "multicast_querier": "1", "multicast_query_interval": "12500"}},
    "mdns": {"enabled": True, "interfaces": ["br-lan", "br-iot"]},
    "warnings": [],
}
res = loop.run_until_complete(handler.rpc_multicast_apply(payload))
check("enabled apply succeeds", res.get("success") is True and res.get("status") == "applied", str(res))

written_paths = [p for p, _ in writes]
check("writes igmpproxy config", micrond.IGMPPROXY_CONF in written_paths, str(written_paths))
check("writes PIM command script", micrond.MULTICAST_PIM_FILE in written_paths, str(written_paths))
check("writes mDNS config", micrond.MDNS_REPEATER_CONF in written_paths, str(written_paths))
check("restarts igmpproxy", ["systemctl", "restart", "igmpproxy"] in calls, str(calls))
check("runs vtysh -f", any(c[:2] == ["vtysh", "-f"] for c in calls), str(calls))
check("restarts mdns-repeater", ["systemctl", "restart", "mdns-repeater"] in calls, str(calls))

sysfs_writes = {p: v for p, v in writes if p.startswith("/sys/")}
check(
    "writes whitelisted sysfs attributes",
    sysfs_writes.get("/sys/class/net/br-lan/bridge/multicast_snooping") == "1"
    and sysfs_writes.get("/sys/class/net/br-lan/bridge/multicast_query_interval") == "12500",
    str(sysfs_writes),
)

# ---- Rejects a malformed bridge and non-whitelisted sysfs -----------------
calls.clear()
writes.clear()
res = loop.run_until_complete(handler.rpc_multicast_apply({
    "enabled": True,
    "snooping": {"bridge": "br lan; rm -rf /", "sysfs": {"multicast_snooping": "1"}},
}))
check("rejects invalid bridge name",
      any("invalid bridge" in w for w in res.get("warnings", [])), str(res))
check("no sysfs write for invalid bridge",
      not any(p.startswith("/sys/") for p, _ in writes), str(writes))

res = loop.run_until_complete(handler.rpc_multicast_apply({
    "enabled": True,
    "snooping": {"bridge": "br-lan", "sysfs": {"multicast_snooping": "1", "..escape": "1", "multicast_querier": "x"}},
}))
check("rejects non-whitelisted sysfs attribute",
      any("refusing sysfs write: ..escape" in w for w in res.get("warnings", [])), str(res))
check("rejects non-numeric sysfs value",
      any("refusing sysfs write: multicast_querier" in w for w in res.get("warnings", [])), str(res))

# ---- mDNS needs two interfaces -------------------------------------------
res = loop.run_until_complete(handler.rpc_multicast_apply({
    "enabled": True,
    "mdns": {"enabled": True, "interfaces": ["br-lan"]},
}))
check("single-interface mDNS does not write config",
      not any(p == micrond.MDNS_REPEATER_CONF for p, _ in writes), str(writes))

# ---- status shape ---------------------------------------------------------
status = loop.run_until_complete(handler.rpc_multicast_status({}))
check("status reports availability",
      status.get("available") is True and {"igmpproxy", "pim", "mdns"}.issubset(status.keys()), str(status))

# ---- dispatch -------------------------------------------------------------
for method in ("multicast.apply", "multicast.status"):
    resp = loop.run_until_complete(handler.handle_request({"jsonrpc": "2.0", "id": 1, "method": method, "params": {"enabled": False}}))
    check(f"dispatch {method}", "result" in resp, str(resp))

print()
if failures:
    print(f"{len(failures)} FAILURE(S): {failures}")
    sys.exit(1)
print("ALL MICROND MULTICAST CHECKS PASSED")

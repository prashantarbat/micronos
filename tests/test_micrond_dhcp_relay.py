#!/usr/bin/env python3
"""
MicronOS micrond dhcp.relay_* RPC verification
=============================================

Standalone harness for the DHCP relay handlers (`dhcp.relay_apply` /
`dhcp.relay_status`). systemctl is stubbed; the unit directory is a temp dir, so
the generated unit files are checked on a real filesystem.

  python3 tests/test_micrond_dhcp_relay.py
"""
import asyncio
import importlib.util
import os
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


UNIT_DIR = tempfile.mkdtemp(prefix="micron-units-")
micrond.DHCP_HELPER_UNIT_DIR = UNIT_DIR

loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)

calls = []


def fake_run(argv):
    calls.append(list(argv))
    if list(argv[:2]) == ["systemctl", "is-active"]:
        return subprocess.CompletedProcess(argv, 0, "active", "")
    if argv and argv[0] == "journalctl":
        return subprocess.CompletedProcess(argv, 0, "line-one\nline-two", "")
    return subprocess.CompletedProcess(argv, 0, "", "")


handler._run = fake_run


def dispatch(method, params):
    return loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "method": method, "params": params, "id": 1}
    ))["result"]


def reset():
    calls.clear()


def unit_path(slug):
    return os.path.join(UNIT_DIR, f"dhcp-helper@{slug}.service")


def clear_units():
    for name in os.listdir(UNIT_DIR):
        os.remove(os.path.join(UNIT_DIR, name))


print("=========================================================")
print("   MicronOS micrond dhcp.relay_* RPC Verification")
print("=========================================================\n")


# 1. Apply two instances: unit files written, services restarted.
reset()
clear_units()
res = dispatch("dhcp.relay_apply", {"instances": [
    {"name": "branch", "interfaces": ["dmz"], "servers": ["10.0.0.10", "10.0.0.11"]},
    {"name": "guest", "interfaces": ["opt1", "opt2"], "servers": ["10.0.1.5"]},
]})
check("apply succeeds", res["success"] is True, str(res.get("message")))
check("one unit file per instance", os.path.isfile(unit_path("branch")) and os.path.isfile(unit_path("guest")))
check("daemon-reload is run", ["systemctl", "daemon-reload"] in calls)
check("each instance is restarted",
      ["systemctl", "restart", "dhcp-helper@branch.service"] in calls
      and ["systemctl", "restart", "dhcp-helper@guest.service"] in calls)

with open(unit_path("branch"), encoding="utf-8") as fh:
    unit = fh.read()
check("unit ExecStart carries the interfaces and servers",
      "ExecStart=/usr/sbin/dhcp-helper" in unit and "-i dmz" in unit and "-s 10.0.0.10" in unit)
check("unit is a template with the right name", "dhcp-helper@branch" in unit)

# 2. An invalid server is refused before anything is written.
reset()
clear_units()
res = dispatch("dhcp.relay_apply", {"instances": [
    {"name": "bad", "interfaces": ["dmz"], "servers": ["999.1.1.1"]},
]})
check("invalid server is refused", res["success"] is False, str(res.get("message")))
check("no unit files written on a refused apply", os.listdir(UNIT_DIR) == [])
check("no systemctl calls on a refused apply", calls == [])

# 3. An invalid interface name is refused.
reset()
res = dispatch("dhcp.relay_apply", {"instances": [
    {"name": "bad2", "interfaces": ["dmz; rm -rf /"], "servers": ["10.0.0.10"]},
]})
check("invalid interface is refused", res["success"] is False, str(res.get("message")))

# 4. An invalid instance slug is refused.
reset()
res = dispatch("dhcp.relay_apply", {"instances": [
    {"name": "../../etc/passwd", "interfaces": ["dmz"], "servers": ["10.0.0.10"]},
]})
check("invalid instance name is refused", res["success"] is False, str(res.get("message")))

# 5. Applying a new set retires instances that are no longer configured.
reset()
clear_units()
dispatch("dhcp.relay_apply", {"instances": [
    {"name": "old", "interfaces": ["dmz"], "servers": ["10.0.0.10"]},
    {"name": "keep", "interfaces": ["opt1"], "servers": ["10.0.0.20"]},
]})
reset()
res = dispatch("dhcp.relay_apply", {"instances": [
    {"name": "keep", "interfaces": ["opt1"], "servers": ["10.0.0.20"]},
]})
check("re-apply succeeds", res["success"] is True)
check("stale unit is stopped and disabled",
      ["systemctl", "stop", "dhcp-helper@old.service"] in calls
      and ["systemctl", "disable", "dhcp-helper@old.service"] in calls)
check("stale unit file is removed", not os.path.isfile(unit_path("old")))
check("kept unit file remains", os.path.isfile(unit_path("keep")))

# 6. Applying an empty set disables all relays.
reset()
res = dispatch("dhcp.relay_apply", {"instances": []})
check("empty apply succeeds", res["success"] is True)
check("all unit files removed", os.listdir(UNIT_DIR) == [])

# 7. Status reports the configured instances.
reset()
dispatch("dhcp.relay_apply", {"instances": [
    {"name": "statuscheck", "interfaces": ["dmz"], "servers": ["10.0.0.10"]},
]})
res = dispatch("dhcp.relay_status", {})
check("status lists the instance", res["success"] is True
      and any(i["name"] == "statuscheck" for i in res["instances"]), str(res.get("instances")))
check("status reports running state",
      any(i["state"] == "running" for i in res["instances"]))

# 8. Service control of a single applied instance (dhcp.relay_service).
reset()
res = dispatch("dhcp.relay_service", {"slug": "statuscheck", "action": "restart"})
check("restart instance succeeds", res["success"] is True, str(res.get("message")))
check("restart targets the instance unit",
      ["systemctl", "restart", "dhcp-helper@statuscheck.service"] in calls)
check("restart reports the run-state", res.get("state") == "running")

reset()
res = dispatch("dhcp.relay_service", {"slug": "statuscheck", "action": "stop"})
check("stop instance succeeds", res["success"] is True, str(res.get("message")))
check("stop targets the instance unit",
      ["systemctl", "stop", "dhcp-helper@statuscheck.service"] in calls)

reset()
res = dispatch("dhcp.relay_service", {"slug": "statuscheck", "action": "start"})
check("start instance succeeds", res["success"] is True, str(res.get("message")))
check("start targets the instance unit",
      ["systemctl", "start", "dhcp-helper@statuscheck.service"] in calls)

# 9. Service control refuses bad input and unknown instances.
reset()
res = dispatch("dhcp.relay_service", {"slug": "../../etc/passwd", "action": "restart"})
check("invalid slug is refused", res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("dhcp.relay_service", {"slug": "statuscheck", "action": "poweroff"})
check("unsupported action is refused", res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("dhcp.relay_service", {"slug": "neverapplied", "action": "restart"})
check("unknown instance is refused", res["success"] is False and calls == [], str(res.get("message")))

# 10. Journal retrieval for the widget's LED (dhcp.relay_logs).
reset()
res = dispatch("dhcp.relay_logs", {"slug": "statuscheck", "lines": 10})
check("relay logs succeeds for an applied instance", res["success"] is True, str(res.get("message")))
check("relay logs reads the instance unit",
      ["journalctl", "-u", "dhcp-helper@statuscheck.service", "-n", "10", "--no-pager"] in calls)
check("relay logs returns journal output", "line-one" in (res.get("logs") or ""))

reset()
res = dispatch("dhcp.relay_logs", {"slug": "../../etc/passwd"})
check("relay logs refuses an invalid slug", res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("dhcp.relay_logs", {"slug": "neverapplied"})
check("relay logs refuses an unknown instance", res["success"] is False and calls == [], str(res.get("message")))

print()
if failures:
    print(f"FAILED: {len(failures)} check(s): " + ", ".join(failures))
    sys.exit(1)

print("ALL MICROND DHCP RELAY CHECKS PASSED")

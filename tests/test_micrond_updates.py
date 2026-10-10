#!/usr/bin/env python3
"""
MicronOS micrond system update / upgrade RPC verification
=========================================================

Standalone harness for the appliance-wide update surface
(`system.updates_check` / `system.upgrade`).

apt-get is stubbed. The point of the harness is that the check is *read-only*
(a simulated upgrade only) and that the upgrade uses a fixed argv with no
caller-supplied package names.

  python3 tests/test_micrond_updates.py
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
behaviour = {}


def fake_run(argv):
    calls.append(list(argv))
    for prefix, (rc, out, err) in behaviour.items():
        if list(argv[: len(prefix)]) == list(prefix):
            return subprocess.CompletedProcess(argv, rc, out, err)
    return subprocess.CompletedProcess(argv, 0, "", "")


handler._run = fake_run


def dispatch(method, params):
    return loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "method": method, "params": params, "id": 1}
    ))["result"]


def reset():
    calls.clear()
    behaviour.clear()


PLAN = (
    "Inst libc6 [2.41-3] (2.41-4 Debian:stable [amd64])\n"
    "Inst openssl [3.5.0-2] (3.5.1-1 Debian:stable [amd64])\n"
    "Conf libc6 (2.41-4 Debian:stable [amd64])\n"
)

print("=========================================================")
print("   MicronOS micrond system.updates_* RPC Verification")
print("=========================================================\n")

# 1. A check refreshes metadata and parses the pending upgrades.
reset()
behaviour[("apt-get", "update")] = (0, "", "")
behaviour[("apt-get", "-s")] = (0, PLAN, "")
res = dispatch("system.updates_check", {})
check("check succeeds", res["success"] is True, str(res.get("message")))
check("check parses two upgradable packages", res["count"] == 2, str(res.get("packages")))
names = [p["name"] for p in res["packages"]]
check("check reports the package names", names == ["libc6", "openssl"], str(names))
check("check captures current and candidate versions",
      res["packages"][0]["current"] == "2.41-3" and res["packages"][0]["candidate"] == "2.41-4",
      str(res["packages"][0]))
check("check runs apt-get update", ["apt-get", "update", "-q"] in calls)
check("check is read-only (simulated upgrade, never a real one)",
      ["apt-get", "-s", "-y", "upgrade"] in calls and ["apt-get", "-y", "upgrade"] not in calls)

# 2. A failed simulation is reported as an error, without a real upgrade.
reset()
behaviour[("apt-get", "-s")] = (100, "", "E: Could not open lock file")
res = dispatch("system.updates_check", {})
check("a failed check reports an error", res["success"] is False and res["status"] == "error")
check("a failed check leaves the package list empty", res["packages"] == [])

# 3. Upgrade refreshes metadata then runs a real apt-get upgrade.
reset()
behaviour[("apt-get", "-y")] = (0, "Reading state information...\n", "")
res = dispatch("system.upgrade", {})
check("upgrade succeeds", res["success"] is True and res["status"] == "applied", str(res.get("message")))
check("upgrade refreshes metadata first", ["apt-get", "update", "-q"] in calls)
check("upgrade runs apt-get -y upgrade", ["apt-get", "-y", "upgrade"] in calls)
check("upgrade passes no packages on the command line",
      all(c[:3] != ["apt-get", "-y", "upgrade"] or len(c) == 3 for c in calls))

# 4. A failing upgrade reports the reason and warns.
reset()
behaviour[("apt-get", "-y")] = (100, "", "dpkg: error processing package")
res = dispatch("system.upgrade", {})
check("a failing upgrade reports an error", res["success"] is False and res["status"] == "error")
check("a failing upgrade carries a warning", len(res["warnings"]) >= 1, str(res.get("warnings")))

print()
if failures:
    print(f"FAILED: {len(failures)} check(s): " + ", ".join(failures))
    sys.exit(1)

print("ALL MICROND SYSTEM UPDATE CHECKS PASSED")

#!/usr/bin/env python3
"""
End-to-end: microctl → UNIX socket → real micrond → result.

Starts the actual daemon (system/micrond/micrond.py, unmodified handlers) on a
temporary socket, then drives `bin/microctl` against it with MICRON_SOCKET.
Proves the framing, the JSON contract, --json output, exit codes, usage errors
and the daemon-unreachable path. Commands that would change the host
(service stop/start) are only exercised on their refusal paths.

Run: python3 tests/test_cli_daemon_integration.py
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _support.strictdecode import strict_json_loads  # noqa: E402  (NaN/Infinity rejected)

CLI = os.path.join(ROOT, "bin", "microctl")
failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


import asyncio

if shutil.which("php") is None:
    print("SKIP php is not installed")
    sys.exit(0)

if not hasattr(asyncio, "start_unix_server") or os.name == "nt":
    print("SKIP AF_UNIX domain socket daemon requires POSIX / Linux environment")
    sys.exit(0)

tmp = tempfile.mkdtemp(prefix="micron-cli-it-")
sock = os.path.join(tmp, "micrond.sock")
launcher = os.path.join(tmp, "run_daemon.py")
with open(launcher, "w") as fh:
    fh.write(
        "import asyncio, importlib.util, sys\n"
        f"spec = importlib.util.spec_from_file_location('micrond', {os.path.join(ROOT, 'system', 'micrond', 'micrond.py')!r})\n"
        "m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)\n"
        f"m.SOCKET_PATH = {sock!r}\n"
        f"m.MASTER_KEY_PATH = {os.path.join(tmp, 'secrets.key')!r}\n"
        "asyncio.run(m.main())\n"
    )
daemon = subprocess.Popen([sys.executable, "-I", launcher], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def cli(*args, sock_path=sock):
    env = dict(os.environ, MICRON_SOCKET=sock_path)
    p = subprocess.run(["php", CLI, *args], capture_output=True, text=True, env=env, timeout=60, stdin=subprocess.DEVNULL)
    return p.returncode, p.stdout, p.stderr


try:
    for _ in range(100):
        if os.path.exists(sock):
            break
        time.sleep(0.1)
    check("the daemon listens on the temporary socket", os.path.exists(sock))

    rc, out, err = cli("health", "--json")
    data = strict_json_loads(out) if out.strip().startswith("{") else {}
    check("health --json exits 0", rc == 0, err)
    check("health reports real cpu_count", isinstance(data.get("result", {}).get("cpu_count"), int), out[:200])
    check("health reports interfaces from /sys", any(i["name"] == "lo" for i in data.get("result", {}).get("interfaces", [])))

    rc, out, err = cli("health")
    check("health human output names the load average", rc == 0 and "Load average" in out, out[:200])

    rc, out, err = cli("rpc", "methods", "--json")
    methods = strict_json_loads(out).get("result", {}).get("methods", []) if rc == 0 else []
    check("rpc methods lists the daemon catalogue", len(methods) >= 150, str(len(methods)))
    check("rpc methods carries capability classes",
          {"handler": "system_reboot", "class": "destructive"} in methods)

    rc, out, err = cli("services", "--json")
    check("services --json returns one row per managed unit",
          rc == 0 and "nftables" in strict_json_loads(out)["result"]["services"], err or out[:200])

    rc, out, err = cli("logs", "../../etc/shadow")
    check("logs refuses an unknown unit with exit 3", rc == 3 and "refusing unknown service" in err, f"rc={rc} {err}")

    rc, out, err = cli("service", "nftables", "stop")
    check("service stop without --yes is refused non-interactively", rc == 1 and "--yes" in err, f"rc={rc} {err}")

    rc, out, err = cli("service", "nftables", "explode")
    check("service with an unknown action is a usage error", rc == 1 and "Usage" in err)

    rc, out, err = cli("vpn", "wireguard", "status", "--interface", "wg0;id")
    check("vpn rejects an injected interface name", rc == 1)

    rc, out, err = cli("plugins", "--json")
    check("plugins --json returns the catalogue", rc == 0 and len(strict_json_loads(out)["result"]["plugins"]) >= 8, err)

    rc, out, err = cli("doctor", "--json")
    doc = strict_json_loads(out) if out.strip().startswith("{") else {}
    names = {c["check"]: c["level"] for c in doc.get("checks", [])}
    check("doctor finds the socket and a responding daemon",
          names.get("daemon socket") == "ok" and names.get("daemon responds") == "ok", str(names))

    rc, out, err = cli("health", sock_path=os.path.join(tmp, "absent.sock"))
    check("an unreachable daemon exits 2 with a reason", rc == 2 and "cannot reach micrond" in err, f"rc={rc} {err}")

    rc, out, err = cli("no-such-command")
    check("an unknown command exits 1", rc == 1 and "Unknown command" in err)

    rc, out, err = cli("version", "--json")
    check("version --json reads VERSION", rc == 0 and strict_json_loads(out)["version"] != "unknown")
finally:
    daemon.terminate()
    try:
        daemon.wait(timeout=5)
    except subprocess.TimeoutExpired:
        daemon.kill()
    shutil.rmtree(tmp, ignore_errors=True)

if failures:
    print(f"\nFAILED: {len(failures)} check(s): " + "; ".join(failures))
    sys.exit(1)
print("\nALL CLI ↔ DAEMON INTEGRATION CHECKS PASSED")

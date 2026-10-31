#!/usr/bin/env python3
"""RPC contract inventory for MicronOS.

Lists every JSON-RPC method the daemon implements (`rpc_*` in micrond.py), every
method string the management plane / CLIs call, and every key IpcClient's mock
mode answers. Prints drift so it is a build failure, not a support ticket.

Usage: python3 tools/rpc_inventory.py [--json]
Exit status: 0 = no unexplained drift, 1 = drift found.
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def read(p):
    with open(os.path.join(ROOT, p), encoding="utf-8", errors="replace") as fh:
        return fh.read()

def daemon_methods():
    src = read("system/micrond/micrond.py")
    names = re.findall(r"^\s+async def rpc_([a-z0-9_]+)\(", src, re.M)
    return set(names)

def to_handler(method):
    return method.replace(".", "_")

CALL_RES = [
    re.compile(r"""->(?:call|callOrFail|callRaw)\(\s*['"]([a-z0-9_.]+)['"]"""),
    re.compile(r"""\bcall\(\s*['"]([a-z0-9]+\.[a-z0-9_.]+)['"]"""),
    re.compile(r"""rpc[_ ]call\s+['"]?([a-z0-9]+\.[a-z0-9_.]+)"""),
    re.compile(r"""["']method["']\s*[:=]>?\s*["']([a-z0-9]+\.[a-z0-9_.]+)["']"""),
]

def callers():
    found = {}
    roots = ["app", "bin", "system/micrond/client.py", "installer", "system"]
    for r in roots:
        base = os.path.join(ROOT, r)
        files = []
        if os.path.isfile(base):
            files = [base]
        else:
            for d, _, fs in os.walk(base):
                if "Views" in d.split(os.sep) and False:
                    continue
                for f in fs:
                    files.append(os.path.join(d, f))
        for f in files:
            rel = os.path.relpath(f, ROOT)
            if rel.endswith("micrond.py") or rel.endswith((".png", ".ico", ".woff2")):
                continue
            try:
                txt = open(f, encoding="utf-8", errors="replace").read()
            except OSError:
                continue
            for rx in CALL_RES:
                for m in rx.findall(txt):
                    found.setdefault(m, set()).add(rel)
    return found

def mock_keys():
    src = read("app/Libraries/IpcClient.php")
    body = src[src.find("function mockDispatch"):]
    # mockDispatch() is a match(true) of `$method === 'x.y'` arms
    return set(re.findall(r"""\$method\s*===\s*['"]([a-z0-9_.]+)['"]""", body))

def main():
    daemon = daemon_methods()
    calls = callers()
    mocks = mock_keys()
    missing = {m: sorted(v) for m, v in calls.items() if to_handler(m) not in daemon}
    report = {
        "daemon_methods": len(daemon),
        "called_methods": len(calls),
        "mock_keys": len(mocks),
        "called_but_missing": missing,
        "called_without_mock": sorted(m for m in calls if m not in mocks and to_handler(m) in daemon),
    }
    if "--json" in sys.argv:
        print(json.dumps(report, indent=2, sort_keys=True))
    else:
        print(f"daemon handlers: {len(daemon)}  called methods: {len(calls)}  mock keys: {len(mocks)}")
        print(f"called but not implemented: {len(missing)}")
        for m, files in sorted(missing.items()):
            print(f"  {m:40s} {', '.join(files)}")
        print(f"called without a mock response: {len(report['called_without_mock'])}")
    return 1 if missing else 0

if __name__ == "__main__":
    sys.exit(main())

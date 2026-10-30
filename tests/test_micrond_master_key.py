#!/usr/bin/env python3
"""
micrond appliance master key
============================

The ISO ships no .env, so the web application derives its encryption key from
/etc/micron/secrets.key. micrond creates that key on first start and must never
replace an existing one (that would make every stored secret unreadable).
"""
import importlib.util
import os
import re
import stat
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


def main():
    tmpdir = tempfile.mkdtemp(prefix="micron-master-key-")
    path = os.path.join(tmpdir, "etc", "micron", "secrets.key")

    check("default path is /etc/micron/secrets.key", micrond.MASTER_KEY_PATH == "/etc/micron/secrets.key")

    created = micrond.ensure_master_key(path)
    check("creates the key when absent", created is True)
    with open(path) as f:
        first = f.read()
    check("key is 64 hex characters", re.fullmatch(r"[0-9a-f]{64}\n", first) is not None, repr(first[:8]))
    mode = stat.S_IMODE(os.stat(path).st_mode)
    check("key is mode 0640", mode == 0o640, oct(mode))

    again = micrond.ensure_master_key(path)
    with open(path) as f:
        second = f.read()
    check("an existing key is never replaced", again is False and second == first)

    other = os.path.join(tmpdir, "other.key")
    micrond.ensure_master_key(other)
    with open(other) as f:
        check("each appliance gets its own key", f.read() != first)

    if failures:
        print(f"\n{len(failures)} check(s) failed")
        sys.exit(1)
    print("\nall master key checks passed")


if __name__ == "__main__":
    main()

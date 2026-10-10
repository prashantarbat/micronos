#!/usr/bin/env python3
"""
Meta-test: nothing may call a lenient decoder or a raw crypto/shell primitive.

One rule, enforced across the harnesses (`tests/`) and the micrond data plane
(`system/micrond/`), so safety-critical primitives live in the one place that
owns them and cannot quietly reappear in a harness:

  * Lenient decoding — `json.loads`/`json.load` without `parse_constant=`,
    `b64decode` without `validate=True`, `raw_decode`, `binascii.a2b_base64`,
    `json.JSONDecoder` without `parse_constant=`. Harnesses must route through
    `tests/_support/strictdecode.py`; micrond carries its own strict helper.
  * Raw crypto — any `hashlib.*` or `hmac.*` call. A harness that needs a digest
    should move it into the shared helper (or production), not grow its own.
    One exception is recorded explicitly, not silently: the OpenPGP v4
    fingerprint is SHA-1 *by specification* (RFC 4880 §12.2), so the shared
    helper's `pgp_primary_fingerprints` is allowed to call `hashlib.sha1` — and
    only there.
  * Shell execution — `subprocess.*(..., shell=True)` and the shell-helpers
    `os.system`, `os.popen`, `subprocess.getoutput`/`getstatusoutput`.

Regexes cannot tell `json.loads(x)` from `json.loads(x, parse_constant=f)`, so
this guard parses each file with `ast` and inspects the calls themselves, with
alias resolution (`import json as j`, `from base64 import b64decode as b64`) and
function-scope tracking for the one allowance.

  python3 tests/test_strict_decoding_guard.py
"""

import ast
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]

# Harnesses and the data-plane daemon/client are held to the same rule.
SCAN_ROOTS = (REPO_ROOT / "tests", REPO_ROOT / "system" / "micrond")

# The single legitimate raw crypto primitive, keyed by
# (path, dotted function scope, canonical symbol). Kept here, in the open, so the
# exception is reviewable rather than hidden behind a broad file-level skip.
ALLOWED_PRIMITIVES = {
    ("tests/_support/strictdecode.py", "pgp_primary_fingerprints", "hashlib.sha1"):
        "OpenPGP v4 fingerprints are SHA-1 by specification (RFC 4880 §12.2)",
}

# The logical modules whose direct use is tracked, and the imported names that
# map onto them when used unqualified.
_TRACKED_MODULES = ("json", "base64", "binascii", "hashlib", "hmac", "os", "subprocess")
_IMPORTED = {
    ("json", "loads"): "json.loads",
    ("json", "load"): "json.load",
    ("json", "JSONDecoder"): "json.JSONDecoder",
    ("base64", "b64decode"): "base64.b64decode",
    ("base64", "standard_b64decode"): "base64.standard_b64decode",
    ("base64", "urlsafe_b64decode"): "base64.urlsafe_b64decode",
    ("binascii", "a2b_base64"): "binascii.a2b_base64",
    ("hashlib", "md5"): "hashlib.md5",
    ("hashlib", "sha1"): "hashlib.sha1",
    ("hashlib", "new"): "hashlib.new",
    ("hmac", "new"): "hmac.new",
    ("hmac", "HMAC"): "hmac.HMAC",
    ("hmac", "digest"): "hmac.digest",
}

_LENIENT_DECODERS = {
    "json.loads", "json.load",
    "base64.b64decode", "base64.standard_b64decode", "base64.urlsafe_b64decode",
    "binascii.a2b_base64",
}

failures = []


def check(name, condition, detail=""):
    print(("PASS " if condition else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not condition:
        failures.append(name)


def _aliases(tree: ast.AST):
    """Maps every local alias back to `(logical module, attribute)`."""
    aliases = {}  # local module name -> logical module name
    direct = {}   # imported local name -> canonical dotted symbol

    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for alias in node.names:
                if alias.name in _TRACKED_MODULES:
                    aliases[alias.asname or alias.name] = alias.name
        elif isinstance(node, ast.ImportFrom):
            for alias in node.names:
                symbol = _IMPORTED.get((node.module, alias.name))
                if symbol is not None:
                    direct[alias.asname or alias.name] = symbol

    return aliases, direct


def _scoped_calls(tree: ast.AST):
    """Yields `(call_node, enclosing_names)` for every call, tracking scope."""
    def walk(node, scope):
        for child in ast.iter_child_nodes(node):
            if isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
                yield from walk(child, scope + (child.name,))
            else:
                if isinstance(child, ast.Call):
                    yield child, scope
                yield from walk(child, scope)

    yield from walk(tree, ())


def _canonical(func, aliases, direct):
    """The canonical dotted symbol for a call target, or None if untracked."""
    if isinstance(func, ast.Attribute):
        base = func.value
        if isinstance(base, ast.Name) and base.id in aliases:
            return f"{aliases[base.id]}.{func.attr}"
        return None
    if isinstance(func, ast.Name):
        return direct.get(func.id)
    return None


def _keywords(node: ast.Call):
    return {kw.arg: kw.value for kw in node.keywords if kw.arg is not None}


def _is_true(node) -> bool:
    return isinstance(node, ast.Constant) and node.value is True


def find_violations(tree: ast.AST, rel_path: str):
    """Every lenient-decoding or raw-primitive call in a parsed module."""
    aliases, direct = _aliases(tree)
    hits = []

    def record(line, message):
        hits.append((line, message))

    for node, scope in _scoped_calls(tree):
        func = node.func
        canonical = _canonical(func, aliases, direct)
        name = func.attr if isinstance(func, ast.Attribute) else (func.id if isinstance(func, ast.Name) else "")
        kwargs = _keywords(node)

        # --- lenient decoding -------------------------------------------------
        if canonical in ("json.loads", "json.load") and "parse_constant" not in kwargs:
            record(node.lineno, f"{canonical}() without parse_constant=")

        if canonical in _LENIENT_DECODERS - {"json.loads", "json.load"} and not _is_true(kwargs.get("validate")):
            record(node.lineno, f"{canonical}() without validate=True")

        if name == "raw_decode":
            record(node.lineno, "raw_decode() ignores trailing data")

        if canonical == "json.JSONDecoder" and "parse_constant" not in kwargs:
            record(node.lineno, "json.JSONDecoder() without parse_constant=")

        # --- raw crypto -------------------------------------------------------
        if canonical is not None and canonical.split(".", 1)[0] in ("hashlib", "hmac"):
            scope_name = ".".join(scope)
            if (rel_path, scope_name, canonical) not in ALLOWED_PRIMITIVES:
                record(node.lineno, f"raw crypto primitive {canonical}()")

        # --- shell execution --------------------------------------------------
        if canonical in ("os.system", "os.popen", "subprocess.getoutput", "subprocess.getstatusoutput"):
            record(node.lineno, f"shell execution via {canonical}()")

        if canonical in ("subprocess.run", "subprocess.call", "subprocess.check_call",
                         "subprocess.check_output", "subprocess.Popen") and _is_true(kwargs.get("shell")):
            record(node.lineno, f"{canonical}() with shell=True")

    return hits


def scan_source(source: str, rel_path: str):
    try:
        tree = ast.parse(source, filename=rel_path)
    except SyntaxError as exc:
        return [(exc.lineno or 0, f"syntax error: {exc}")]
    return find_violations(tree, rel_path)


def main() -> int:
    for root in SCAN_ROOTS:
        check(f"scan root {root.relative_to(REPO_ROOT)} exists", root.is_dir())

    files = sorted({path for root in SCAN_ROOTS if root.is_dir() for path in root.rglob("*.py")})
    check("the guard found files to scan", len(files) > 0, f"{len(files)} file(s)")

    # The production modules are named explicitly: a path change that silently
    # dropped them from the scan must fail, not quietly shrink the coverage.
    scanned = {path.resolve() for path in files}
    for expected in ("system/micrond/micrond.py", "system/micrond/client.py"):
        check(f"the guard scans {expected}", (REPO_ROOT / expected).resolve() in scanned)

    for path in files:
        rel = path.relative_to(REPO_ROOT).as_posix()
        hits = scan_source(path.read_text(encoding="utf-8"), rel)
        check(
            f"no lenient decoding or raw primitive in {rel}",
            not hits,
            "; ".join(f"line {line}: {message}" for line, message in hits),
        )

    # The guard must actually be able to fail. Aliased and direct lenient calls
    # are detected (lines 7-13; line 13 yields two hits)...
    lenient = [
        "import json",                       # 1
        "import json as j",                  # 2
        "from json import loads",            # 3
        "from base64 import b64decode as b64",  # 4
        "import base64",                     # 5
        "import binascii",                   # 6
        "json.loads('{}')",                  # 7
        "j.load('f')",                       # 8
        "loads('{}')",                       # 9
        "base64.b64decode('QUJD')",          # 10
        "b64('QUJD', validate=False)",       # 11
        "binascii.a2b_base64('QUJD')",       # 12
        "json.JSONDecoder().raw_decode('{}')",  # 13
    ]
    flagged = sorted({line for line, _ in scan_source("\n".join(lenient), "<lenient>")})
    check("the guard detects aliased and direct lenient calls",
          flagged == list(range(7, 14)), f"flagged lines {flagged}")

    # ...raw crypto and shell primitives are detected...
    raw = [
        "import hashlib",                         # 1
        "import hmac",                            # 2
        "import subprocess",                      # 3
        "import os",                              # 4
        "hashlib.md5(b'x')",                      # 5
        "hmac.new(b'k', b'm', hashlib.sha256)",   # 6
        "subprocess.run(['ls'], shell=True)",     # 7
        "os.system('ls')",                        # 8
        "subprocess.getoutput('ls')",             # 9
    ]
    flagged = sorted({line for line, _ in scan_source("\n".join(raw), "<raw>")})
    check("the guard detects raw crypto and shell primitives",
          flagged == list(range(5, 10)), f"flagged lines {flagged}")

    # ...the recorded allowance is honoured in its exact location, and only there.
    allowed = [
        "import hashlib",
        "def pgp_primary_fingerprints(blob):",
        "    return hashlib.sha1(blob).hexdigest()",
    ]
    source = "\n".join(allowed)
    check("the recorded SHA-1 allowance is honoured where it is declared",
          scan_source(source, "tests/_support/strictdecode.py") == [],
          str(scan_source(source, "tests/_support/strictdecode.py")))
    elsewhere = scan_source(source, "tests/some_other_harness.py")
    check("the same SHA-1 call is refused anywhere else",
          [line for line, _ in elsewhere] == [3], str(elsewhere))

    # ...and properly strict calls are left alone.
    strict = [
        "import json",
        "import base64",
        "json.loads('{}', parse_constant=_reject)",
        "json.load(fp, parse_constant=_reject)",
        "json.JSONDecoder(parse_constant=_reject).decode('{}')",
        "base64.b64decode('QUJD', validate=True)",
    ]
    check("the guard leaves strict calls alone",
          scan_source("\n".join(strict), "<strict>") == [],
          str(scan_source("\n".join(strict), "<strict>")))

    print()
    print("FAILED: " + ", ".join(failures) if failures else "ALL STRICT-PRIMITIVE GUARD CHECKS PASSED")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())

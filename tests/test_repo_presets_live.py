#!/usr/bin/env python3
"""
Live verification of the shipped preset repositories (Plugin Spec §5.2, P3).

`tests/test_repo_presets.py` proves, offline, that each preset's declared
fingerprint matches the key material the image ships. It cannot prove the other
half of the claim: that the *vendor* actually signs its metadata with that key.
Only the vendor's own `InRelease` can answer that, and only over the network.

This is that half — the one harness that reaches the network, and therefore
opt-in by design. It fetches each shipped preset's `InRelease`, verifies the
clearsigned metadata against the preset's embedded key in a private keyring, and
requires gpg's machine-readable `VALIDSIG` signer to equal the preset's declared
fingerprint. Because the keyring holds only the key the image ships, a
successful verify is proof of all three things at once: the metadata is
reachable, it is signed, and it is signed by *that* fingerprint.

Nothing is written outside a temporary directory; nothing is installed; no apt
state is touched. It is the same read-only shape as the daemon's
`plugin.repo_test`, run here without an appliance.

    python3 tests/test_repo_presets_live.py --live
    python3 tests/test_repo_presets_live.py --live --preset zerotier
    python3 tests/test_repo_presets_live.py --list     # offline: presets + URLs

Without `--live` it fetches nothing and says so, so the default test run never
depends on a vendor being up. `gpg` must be on PATH; a missing gpg is a hard
failure when `--live` is given, never a silent pass.
"""

import argparse
import os
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path

from _support.strictdecode import strict_json_loads

ROOT = Path(__file__).resolve().parents[1]
REGISTRY_PHP = "app/Libraries/RepoPresets.php"

# A Release/InRelease file is metadata, not an index: the largest shipped
# vendor's is a couple of hundred kilobytes. The same 2 MiB ceiling the daemon
# enforces (`PLUGIN_REPO_RELEASE_MAX`) keeps a hostile or broken endpoint from
# being read without bound.
RELEASE_MAX = 2 * 1024 * 1024

USER_AGENT = "MicronOS-repo-preset-live-check/1.0"

failures = []


def check(name, condition, detail=""):
    print(("PASS " if condition else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not condition:
        failures.append(name)


def load_presets():
    """The shipped preset registry, as PHP resolves it.

    Raises `RuntimeError` when the registry cannot be read — a check that could
    not run must never look like one that passed.
    """
    php = shutil.which("php")
    if php is None:
        raise RuntimeError("php is not on PATH; the preset registry cannot be read")

    script = (
        f'require "{REGISTRY_PHP}"; '
        r"echo json_encode(array_values((new \App\Libraries\RepoPresets())->all()));"
    )

    proc = subprocess.run([php, "-r", script], cwd=str(ROOT), capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(f"php exited {proc.returncode}: {proc.stderr.strip()[:200]}")

    presets = strict_json_loads(proc.stdout)
    if not isinstance(presets, list):
        raise RuntimeError(f"the registry did not decode to a list: {type(presets).__name__}")

    return presets


def release_candidates(preset: dict):
    """The release metadata URLs to try, in order — mirroring `RepoRegistry`.

    Modern repositories publish a clearsigned `InRelease` under
    `dists/<suite>/`; a flat repository (`suite ./`) keeps it at the root, and an
    older archive publishes a detached `Release` + `Release.gpg` instead.

    Returns a list of `(mode, data_url, signature_url|None)`.
    """
    base = str(preset.get("url", "")).rstrip("/")
    suite = str(preset.get("suite", ""))
    directory = base if suite == "./" else f"{base}/dists/{suite}" if suite else base

    return [
        ("inrelease", f"{directory}/InRelease", None),
        ("release", f"{directory}/Release", f"{directory}/Release.gpg"),
    ]


def fetch(url: str, timeout: float) -> bytes:
    """Fetch a release artifact, capped at `RELEASE_MAX`. Raises on failure."""
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "*/*"})

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = getattr(response, "status", 200)
            body = response.read(RELEASE_MAX + 1)
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"HTTP {exc.code}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"unreachable ({exc.reason})") from exc

    if status != 200:
        raise RuntimeError(f"HTTP {status}")
    if len(body) > RELEASE_MAX:
        raise RuntimeError(f"exceeds the {RELEASE_MAX // 1024} KiB limit")

    return body


def gpg_env_unset():
    """A copy of the environment with any user GPG home/options removed.

    gpg is always pointed at an explicit `--homedir` and `--no-options`; this
    only stops an inherited `GNUPGHOME` from being consulted first.
    """
    env = dict(os.environ)
    env.pop("GNUPGHOME", None)
    return env


def gpg_base(home: str):
    """A gpg argv prefix pinned to a private, empty homedir.

    The homedir is the keyring: because it starts empty and is removed when the
    check finishes, the only key a verify can use is the shipped one — and gpg's
    own default keybox is used rather than an explicit `--keyring` path, which is
    what makes this the same invocation on the appliance and on a dev box.
    """
    return ["gpg", "--batch", "--no-tty", "--homedir", home, "--no-options"]


def gpg_import(home: str, armor: str, env) -> tuple:
    """Import the shipped key into the private homedir. Returns `(ok, detail)`."""
    proc = subprocess.run(
        gpg_base(home) + ["--import"],
        input=armor.encode("utf-8"), capture_output=True, env=env,
    )
    if proc.returncode != 0:
        return False, (proc.stderr.decode("utf-8", "replace").strip()[:200] or "import failed")
    return True, ""


def gpg_validsig_signer(home: str, argv_tail, env) -> tuple:
    """Run `gpg --verify` and return `(signer_fingerprint|None, error|None)`.

    `--status-fd` is used so the signer comes from gpg's machine-readable
    `VALIDSIG` record rather than being scraped out of human-readable output.
    """
    proc = subprocess.run(
        gpg_base(home) + ["--status-fd", "1", "--verify"] + argv_tail,
        capture_output=True, env=env,
    )
    stdout = proc.stdout.decode("utf-8", "replace")

    if proc.returncode != 0:
        detail = " ".join(line for line in stdout.splitlines() if line.startswith("[GNUPG:] "))
        return None, "the release metadata is not validly signed by the shipped key" + (
            f" ({detail[:160]})" if detail else ""
        )

    for line in stdout.splitlines():
        if line.startswith("[GNUPG:] VALIDSIG "):
            return line.split()[2].upper(), None

    return None, "the signature verified but gpg reported no VALIDSIG record"


def verify_preset(preset: dict, timeout: float) -> None:
    """Fetch and signature-verify one preset. Records pass/fail per check."""
    preset_id = str(preset.get("id", "?"))
    label = f"preset '{preset_id}'"
    declared = str(preset.get("fingerprint", "")).upper()

    if not declared:
        check(f"{label}: declares a fingerprint", False, "none")
        return

    armor = preset.get("key_armor")
    if not isinstance(armor, str) or not armor.strip():
        check(f"{label}: ships armored key material", False)
        return

    # 1. Fetch the release metadata, trying InRelease first.
    fetched = None
    errors = []
    for mode, data_url, signature_url in release_candidates(preset):
        try:
            body = fetch(data_url, timeout)
            signature = fetch(signature_url, timeout) if signature_url else None
        except RuntimeError as exc:
            errors.append(f"{data_url}: {exc}")
            continue
        fetched = (mode, data_url, body, signature)
        break

    check(f"{label}: the vendor serves release metadata",
          fetched is not None,
          "; ".join(errors) if fetched is None else fetched[1])
    if fetched is None:
        return

    mode, data_url, body, signature = fetched
    print(f"note: {label} served {mode} at {data_url} ({len(body)} bytes)")

    # 2. Verify the signature against the shipped key in a private keyring.
    home = tempfile.mkdtemp(prefix="micron-preset-live-")
    previous_dir = os.getcwd()
    env = gpg_env_unset()

    try:
        # The working directory is moved into the private, empty homedir so the
        # `--homedir` argument and every file gpg is handed are relative names.
        # That keeps the invocation identical on the appliance and on a dev box
        # whose `gpg` does not parse a Windows-style absolute path.
        os.chdir(home)

        ok, detail = gpg_import(".", armor, env)
        check(f"{label}: the shipped key imports into a private keyring", ok, detail)
        if not ok:
            return

        Path("Release").write_bytes(body)

        if signature is None:
            argv_tail = ["Release"]
        else:
            Path("Release.gpg").write_bytes(signature)
            # `gpg --verify <signature> <data>`.
            argv_tail = ["Release.gpg", "Release"]

        signer, error = gpg_validsig_signer(".", argv_tail, env)
        check(f"{label}: {mode} verifies against the shipped key", error is None, error or (signer or ""))
        if error is not None:
            return

        # 3. The proof the whole harness exists for: the declared fingerprint is
        #    the signer. A key that merely happens to be in the file would fail
        #    here, which is why the keyring is not shared with anything else.
        check(f"{label}: the declared fingerprint signed the metadata",
              signer == declared, f"declared {declared} vs signer {signer}")
    finally:
        os.chdir(previous_dir)
        shutil.rmtree(home, ignore_errors=True)


def list_presets(presets) -> None:
    """Offline: print each preset's declared fingerprint and release URLs."""
    for preset in presets:
        declared = str(preset.get("fingerprint", "")).upper()
        print(f"{preset.get('id')}: {declared}  suite={preset.get('suite')}")
        for mode, data_url, signature_url in release_candidates(preset):
            print(f"  {mode}: {data_url}" + (f" (+ {signature_url})" if signature_url else ""))


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Live verification of the shipped preset repositories (opt-in).",
    )
    parser.add_argument("--live", action="store_true",
                        help="Fetch and verify each preset's release metadata over the network.")
    parser.add_argument("--list", action="store_true",
                        help="Offline: print each preset's fingerprint and release URLs, then exit.")
    parser.add_argument("--preset", action="append", default=[],
                        help="Limit to a preset id (repeatable). Default: every preset.")
    parser.add_argument("--timeout", type=float, default=30.0,
                        help="Per-request network timeout in seconds (default: 30).")
    args = parser.parse_args()

    try:
        presets = load_presets()
    except RuntimeError as exc:
        check("the shipped preset registry could be read", False, str(exc))
        print()
        print("FAILED: " + ", ".join(failures))
        return 1

    wanted = {w.lower() for w in args.preset}
    if wanted:
        unknown = wanted - {str(p.get("id", "")).lower() for p in presets}
        if unknown:
            check("every requested preset exists", False, ", ".join(sorted(unknown)))
            print()
            print("FAILED: " + ", ".join(failures))
            return 1
        presets = [p for p in presets if str(p.get("id", "")).lower() in wanted]

    if args.list:
        list_presets(presets)
        return 0

    if not args.live:
        print("SKIPPED (opt-in): live preset verification fetches each vendor's InRelease "
              "over the network. Pass --live to run it, or --list for the offline view.")
        return 0

    if shutil.which("gpg") is None:
        check("gpg is available to verify signatures", False, "gpg is not on PATH")
        print()
        print("FAILED: " + ", ".join(failures))
        return 1

    check("the registry ships at least one preset", len(presets) > 0, f"{len(presets)} preset(s)")

    for preset in presets:
        verify_preset(preset, args.timeout)

    print()
    print("FAILED: " + ", ".join(failures) if failures
          else "ALL LIVE PRESET SIGNATURE CHECKS PASSED")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())

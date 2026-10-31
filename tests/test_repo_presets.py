#!/usr/bin/env python3
"""
Preset repository trust data (Plugin Spec §5.2, P3) — every preset.

A preset is the one place MicronOS *ships* a signing-key fingerprint instead of
asking an operator to type one. That makes the shipped data load-bearing: if the
fingerprint and the key material ever disagree, every appliance that adopts the
preset is trusting a key nobody verified — and the failure mode is silent,
because the fingerprint is compared against the key at apply time and a mismatch
simply refuses the repository.

So this harness recomputes **every** shipped preset's fingerprint from its
embedded key, the way gpg does (`SHA-1(0x99 || len || public-key packet)` for a
v4 key), with no gpg, no network and no vendor involved, and refuses a mismatch.
It reads the registry itself rather than parsing the PHP source, so a preset
added later is covered automatically and no source reformat can hide one.

It also verifies each preset's ASCII-armour CRC24 against the decoded key, so a
key block that was corrupted or truncated-and-re-padded — yet still base64-
decodes cleanly — is caught rather than silently accepted as "a different key".

All decoding (strict JSON, strict base64, the armour/fingerprint helpers) comes
from `tests/_support/strictdecode.py`, the single place the harnesses share.

What it cannot prove — that a key really belongs to the vendor, and that it
really signs the vendor's metadata — was established once, out of band, and is
recorded in the preset's `verified_at` line. See the spec's §5.2 for how.

The preset registry is read through PHP (the project's own runtime), so `php`
must be on PATH; if it is not, the harness fails loudly rather than passing
without checking anything.

  python3 tests/test_repo_presets.py
"""

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

from _support.strictdecode import (
    armor_payload,
    crc24_armor_line,
    pgp_primary_fingerprints,
    strict_json_loads,
)

ROOT = Path(__file__).resolve().parents[1]
REGISTRY_PHP = "app/Libraries/RepoPresets.php"

BEGIN = "-----BEGIN PGP PUBLIC KEY BLOCK-----"
END = "-----END PGP PUBLIC KEY BLOCK-----"

failures = []


def check(name, condition, detail=""):
    print(("PASS " if condition else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not condition:
        failures.append(name)


def load_presets():
    """The shipped preset registry, as PHP resolves it.

    Returns a list of preset arrays. Raises `RuntimeError` when the registry
    cannot be read — a check that could not run must never look like one that
    passed.
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

    try:
        presets = strict_json_loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"php did not return JSON: {proc.stdout[:200]!r} ({exc})") from exc

    if not isinstance(presets, list):
        raise RuntimeError(f"the registry did not decode to a list: {type(presets).__name__}")

    return presets


def verify_preset(preset: dict) -> None:
    """Every trust-data check for one preset, prefixed so failures name it."""
    preset_id = str(preset.get("id", "?"))
    label = f"preset '{preset_id}'"

    declared = str(preset.get("fingerprint", "")).upper()
    check(f"{label}: declares a well-formed fingerprint",
          re.fullmatch(r"[0-9A-F]{40}|[0-9A-F]{64}", declared) is not None,
          declared or "none")
    if not declared:
        return

    armour = preset.get("key_armor")
    complete = (
        isinstance(armour, str)
        and armour.startswith(BEGIN)
        and armour.rstrip().endswith(END)
    )
    check(f"{label}: ships a complete armored public key block", complete)
    if not complete:
        return

    blob, checksum = armor_payload(armour)

    # The armour's own integrity guard: recomputed over the decoded key it
    # catches a block that was corrupted (or truncated and re-padded) yet still
    # base64-decodes cleanly, which the fingerprint check alone cannot — the
    # packet would merely be a different, unverified key.
    check(f"{label}: the armour carries a CRC24 checksum line",
          checksum is not None and re.fullmatch(r"=[A-Za-z0-9+/]{4}", checksum) is not None,
          str(checksum))
    if checksum is not None:
        expected_crc = crc24_armor_line(blob)
        check(f"{label}: the armour CRC24 matches the decoded key material",
              expected_crc == checksum,
              f"armour {checksum} vs computed {expected_crc}")

        # Prove the guard can actually fail: one flipped payload byte must
        # produce a different checksum, so a corrupted block cannot pass.
        corrupted = bytes([blob[0] ^ 0x01]) + blob[1:] if blob else b""
        check(f"{label}: a corrupted key block is rejected by the CRC24 check",
              crc24_armor_line(corrupted) != checksum,
              f"flipped byte produced {crc24_armor_line(corrupted)}")

    fingerprints = pgp_primary_fingerprints(blob)
    check(f"{label}: key material parses into primary key packets",
          len(fingerprints) > 0, f"{len(fingerprints)} key(s)")

    # The declared fingerprint must be the *signer*, never merely "a key that
    # happens to be in the file": an encryption subkey's fingerprint (or a
    # second primary key a vendor bundled) would be accepted by a weaker check
    # and could never verify a signature.
    check(f"{label}: the declared fingerprint is the first (signing) primary key",
          bool(fingerprints) and fingerprints[0] == declared,
          f"declared {declared} vs key {fingerprints[0] if fingerprints else 'none'}")
    check(f"{label}: the declared fingerprint exists in the shipped key material",
          declared in fingerprints,
          ", ".join(fingerprints))

    others = [f for f in fingerprints if f != declared]
    if others:
        print(f"note: preset '{preset_id}' also carries the primary key(s) {', '.join(others)}")

    # Provenance must be present: a preset without a verified-at date is an
    # unverified claim shipped to every appliance.
    check(f"{label}: records when the fingerprint was verified",
          re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(preset.get("verified_at", ""))) is not None,
          str(preset.get("verified_at", "")))
    check(f"{label}: names where the key came from",
          bool(str(preset.get("key_source_url", ""))))


def main() -> int:
    try:
        presets = load_presets()
    except RuntimeError as exc:
        check("the shipped preset registry could be read", False, str(exc))
        print()
        print("FAILED: " + ", ".join(failures))
        return 1

    check("the registry ships at least one preset", len(presets) > 0, f"{len(presets)} preset(s)")

    ids = [str(p.get("id", "")) for p in presets]
    check("every preset has a unique id",
          len(ids) == len(set(ids)) and all(ids),
          ", ".join(ids))

    for preset in presets:
        verify_preset(preset)

    print(f"covered {len(presets)} preset(s): {', '.join(ids)}")

    print()
    print("FAILED: " + ", ".join(failures) if failures else "ALL REPO PRESET TRUST-DATA CHECKS PASSED")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())

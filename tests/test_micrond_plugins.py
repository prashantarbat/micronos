#!/usr/bin/env python3
"""
MicronOS micrond plugin.* RPC verification
==========================================

Standalone harness for the Class B plugin & package manager handlers
(`plugin.catalog` / `plugin.install` / `plugin.remove` / `plugin.upgrade` /
`plugin.service` / `plugin.status` / `plugin.repo.refresh`), and for the curated
plugin repositories (`plugin.repo.apply` / `plugin.repo_status`).

apt-get, systemctl and gpg are stubbed, and the apt source/preference/keyring
directories are temp dirs, so the generated files are checked on a real
filesystem. The point of the harness is the enforcement surface: the daemon
resolves a plugin *id* against a fixed catalogue, refuses the protected core
(packages and units), and never lets a caller name a package or unit directly —
and for repositories it generates every file itself, verifies the signing key's
fingerprint, and pins the repository below the distribution.

  python3 tests/test_micrond_plugins.py
"""
import asyncio
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile

from _support.strictdecode import strict_json_dumps

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
    return subprocess.CompletedProcess(argv, 0, "active", "")


handler._run = fake_run
handler._which = staticmethod(lambda b: "/usr/bin/" + b)
micrond.OpsMixin._which = staticmethod(lambda b: "/usr/bin/" + b)


def dispatch(method, params):
    return loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "method": method, "params": params, "id": 1}
    ))["result"]


def reset():
    calls.clear()
    behaviour.clear()


def posix_modes_are_honoured():
    """Probe whether this host's filesystem carries POSIX permission bits.

    The daemon stages secrets with `os.open(..., 0o600)`, which the appliance's
    ext4 root enforces. A checkout on a filesystem without mode bits (a Windows-
    backed tree, a mounted share) accepts the call but reports 0o666 back, so the
    mode assertion can only be made where the host can actually observe it.
    """
    probe = tempfile.mkdtemp(prefix="micron-mode-probe-")
    try:
        path = os.path.join(probe, "probe")
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        os.close(fd)
        return (os.stat(path).st_mode & 0o777) == 0o600
    finally:
        shutil.rmtree(probe, ignore_errors=True)


POSIX_MODES = posix_modes_are_honoured()
if not POSIX_MODES:
    print("NOTE: this host's filesystem does not carry POSIX mode bits;")
    print("      0600 checks are asserted in the daemon's source and skipped here.\n")


print("=========================================================")
print("   MicronOS micrond plugin.* RPC Verification")
print("=========================================================\n")


# 1. Catalogue exposes the Class B plugins by id, with packages resolved.
reset()
cat = dispatch("plugin.catalog", {})
ids = sorted(p["id"] for p in cat["plugins"])
check("catalog lists the shipped plugins",
      ids == ["darkstat", "iperf3", "ipv6-tunnel", "lightsquid", "lldpd", "nat64", "netbird", "zerotier"],
      str(ids))
check("the Class C entry names the third-party repository it needs",
      micrond.PLUGIN_CATALOG["netbird"].get("repos") == ["netbird"],
      str(micrond.PLUGIN_CATALOG["netbird"].get("repos")))
check("the NAT64 plugin resolves to the distro translator with no repository",
      micrond.PLUGIN_CATALOG["nat64"].get("packages") == ["tayga"]
      and not micrond.PLUGIN_CATALOG["nat64"].get("repos"),
      str(micrond.PLUGIN_CATALOG["nat64"]))
check("the 6in4 plugin is configuration-only: no package, no repository",
      micrond.PLUGIN_CATALOG["ipv6-tunnel"].get("packages") == []
      and not micrond.PLUGIN_CATALOG["ipv6-tunnel"].get("repos"),
      str(micrond.PLUGIN_CATALOG["ipv6-tunnel"]))

# 2. Install resolves the id to packages and runs apt + systemctl.
reset()
res = dispatch("plugin.install", {"id": "lldpd", "grants": ["system.service:lldpd"]})
check("install lldpd succeeds", res["success"] is True, str(res.get("message")))
check("install runs a dry-run then a real apt-get install",
      any(c[:3] == ["apt-get", "-s", "-y"] for c in calls) and any(c[:3] == ["apt-get", "-y", "install"] for c in calls))
check("install enables and starts the plugin unit",
      ["systemctl", "enable", "lldpd"] in calls and ["systemctl", "start", "lldpd"] in calls)

# 3. Unknown plugin id is refused.
reset()
res = dispatch("plugin.install", {"id": "not-a-plugin"})
check("install of an unknown id is refused", res["success"] is False and "unknown plugin" in res["message"])
check("unknown id runs no commands", calls == [])

# 4. A grant the manifest never declared is refused (no silent escalation).
reset()
res = dispatch("plugin.install", {"id": "iperf3", "grants": ["system.service:nftables"]})
check("undeclared capability is refused", res["success"] is False, str(res.get("message")))
check("no apt command runs on a refused grant", not any(c and c[0] == "apt-get" for c in calls))

# 5. A catalogue entry that names a protected package is blocked.
reset()
saved = dict(micrond.PLUGIN_CATALOG)
micrond.PLUGIN_CATALOG["evil"] = {
    "packages": ["nftables"], "units": {}, "capabilities": [],
}
res = dispatch("plugin.install", {"id": "evil", "grants": []})
check("protected package is refused", res["success"] is False and res["status"] == "blocked", str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved)

# 6. A catalogue entry that names a protected unit is blocked.
reset()
saved = dict(micrond.PLUGIN_CATALOG)
micrond.PLUGIN_CATALOG["evil2"] = {
    "packages": ["somepkg"], "units": {"x": "nftables"}, "capabilities": [],
}
res = dispatch("plugin.install", {"id": "evil2", "grants": []})
check("protected unit is refused", res["success"] is False and res["status"] == "blocked", str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved)

# 7. Service control resolves the plugin's own unit.
reset()
res = dispatch("plugin.service", {"id": "darkstat", "action": "restart"})
check("plugin.service restarts the plugin unit", res["success"] is True and res["unit"] == "darkstat")
check("plugin.service runs the right systemctl", ["systemctl", "restart", "darkstat"] in calls)

# 7b. The full start / stop / restart set is supported (the UI used to offer only Restart).
reset()
res = dispatch("plugin.service", {"id": "iperf3", "action": "start"})
check("plugin.service starts the plugin unit", res["success"] is True and ["systemctl", "start", "iperf3"] in calls)

reset()
res = dispatch("plugin.service", {"id": "iperf3", "action": "stop"})
check("plugin.service stops the plugin unit", res["success"] is True and ["systemctl", "stop", "iperf3"] in calls)

reset()
res = dispatch("plugin.service", {"id": "iperf3", "action": "poweroff"})
check("plugin.service refuses an unsupported action", res["success"] is False and calls == [], str(res.get("message")))

# 8. Service control for a plugin with no unit is refused.
reset()
res = dispatch("plugin.service", {"id": "lightsquid", "action": "start"})
check("plugin.service with no unit is refused", res["success"] is False, str(res.get("message")))

# 9. Remove stops + removes the plugin's own packages.
reset()
res = dispatch("plugin.remove", {"id": "darkstat", "purge": True})
check("remove succeeds", res["success"] is True)
check("remove stops and disables the unit",
      ["systemctl", "stop", "darkstat"] in calls and ["systemctl", "disable", "darkstat"] in calls)
check("remove runs apt-get remove on the plugin packages", ["apt-get", "-y", "remove", "darkstat"] in calls)

# 10. Upgrade uses --only-upgrade on the plugin's own packages.
reset()
res = dispatch("plugin.upgrade", {"id": "iperf3"})
check("upgrade succeeds", res["success"] is True)
check("upgrade uses --only-upgrade", ["apt-get", "-y", "install", "--only-upgrade", "iperf3"] in calls)

# 11. Status reports the plugin's run state.
reset()
res = dispatch("plugin.status", {"id": "lldpd"})
check("status reports running state", res["success"] is True and res["state"] == "running")

# 12. Repo refresh runs apt-get update.
reset()
res = dispatch("plugin.repo.refresh", {})
check("repo refresh runs apt-get update", res["success"] is True and ["apt-get", "update"] in calls)

# 13. Journal fetch for the daemon control widget (plugin.logs).
reset()
res = dispatch("plugin.logs", {"id": "lldpd", "lines": 10})
check("plugin.logs succeeds for an installed plugin", res["success"] is True, str(res.get("message")))
check("plugin.logs reads the plugin unit",
      ["journalctl", "-u", "lldpd", "-n", "10", "--no-pager"] in calls)

reset()
res = dispatch("plugin.logs", {"id": "lightsquid"})
check("plugin.logs with no unit is refused", res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("plugin.logs", {"id": "not-a-plugin"})
check("plugin.logs for an unknown id is refused", res["success"] is False and calls == [], str(res.get("message")))

# 14. iperf3 runner: client mode builds a validated argv.
reset()
res = dispatch("plugin.iperf3.run", {"mode": "client", "target": "203.0.113.10", "port": 5201, "duration": 5, "streams": 4})
check("iperf3 client run succeeds", res["success"] is True, str(res.get("message")))
check("iperf3 client argv is well-formed",
      ["/usr/bin/iperf3", "-c", "203.0.113.10", "-p", "5201", "-t", "5", "-P", "4", "--json"] in calls, str(calls))
check("iperf3 client echoes the parameters", res["target"] == "203.0.113.10" and res["streams"] == 4)

# protocol / direction map onto flags, not free-form arguments.
reset()
res = dispatch("plugin.iperf3.run", {"mode": "client", "target": "10.0.0.1", "protocol": "udp", "direction": "download"})
check("iperf3 udp + download adds the right flags",
      any("-u" in c and "-R" in c for c in calls), str(calls))

# The runner refuses anything that is not a well-formed host/port/duration.
reset()
res = dispatch("plugin.iperf3.run", {"mode": "client", "target": "10.0.0.1; rm -rf /"})
check("iperf3 refuses a shell-ish target", res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("plugin.iperf3.run", {"mode": "client", "target": "--flag"})
check("iperf3 refuses a flag-like target", res["success"] is False and calls == [])

reset()
res = dispatch("plugin.iperf3.run", {"mode": "client", "target": "10.0.0.1", "port": 70000})
check("iperf3 refuses an out-of-range port", res["success"] is False and calls == [])

reset()
res = dispatch("plugin.iperf3.run", {"mode": "client", "target": "10.0.0.1", "duration": 999})
check("iperf3 refuses an out-of-range duration", res["success"] is False and calls == [])

# Server mode binds a time-bounded listener (one client, then exit).
reset()
res = dispatch("plugin.iperf3.run", {"mode": "server", "port": 5301})
check("iperf3 server binds a time-bounded listener",
      ["/usr/bin/iperf3", "-s", "-p", "5301", "-1"] in calls, str(calls))
check("iperf3 server run succeeds", res["success"] is True and res["mode"] == "server")

# ======================================================================
# Curated plugin repositories (plugin.repo_apply / plugin.repo_status)
# ======================================================================

ARMOR = ("-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n"
         "bWljcm9uIHRlc3Qga2V5\n"
         "-----END PGP PUBLIC KEY BLOCK-----\n")
FINGERPRINT = "ABCDEF0123456789ABCDEF0123456789ABCDEF01"
# 40 hex characters, like the real thing — a short one would be refused by the
# validator before any crypto ran, which is a different (also tested) path.
OTHER_FINGERPRINT = ("0123456789ABCDEF" * 3)[:40]

SOURCES_DIR = tempfile.mkdtemp(prefix="micron-apt-sources-")
PREF_DIR = tempfile.mkdtemp(prefix="micron-apt-pref-")
KEYRING_DIR = tempfile.mkdtemp(prefix="micron-apt-keyrings-")
micrond.PLUGIN_REPO_SOURCES_DIR = SOURCES_DIR
micrond.PLUGIN_REPO_PREFERENCES_DIR = PREF_DIR
micrond.PLUGIN_REPO_KEYRING_DIR = KEYRING_DIR
micrond.PLUGIN_REPO_CORE_PIN = os.path.join(PREF_DIR, "micron-plugin-core.pref")


def repo_payload(**overrides):
    payload = {
        "id": "vendor", "name": "Vendor APT",
        "url": "https://download.example.com/debian", "suite": "stable",
        "components": ["main"], "architectures": ["amd64"],
        "fingerprint": FINGERPRINT, "key_url": "", "key_armor": ARMOR, "enabled": True,
    }
    payload.update(overrides)
    return payload


# Flipped by the verification tests below to drive gpg's failure modes.
SIGNATURE = {"ok": True, "signer": FINGERPRINT}

# The colon listing's key dates. gpg puts creation at field 5 and expiry at
# field 6 of a `pub:`/`sub:` record, and the `fpr:` record that follows belongs
# to it — which is what lets the daemon tell a repository whose *metadata* is
# old from one whose *signing key* is about to expire. Set to None for "never".
KEY_DATES = {
    "created": "1632595729",
    "expires": None,
    # An encryption subkey with a far-future expiry: if the parser mixed up the
    # owner of an `fpr:` record, this is the date it would wrongly report.
    "sub_fpr": "5FA4DC0963D58E3AF4CC687CD3763BC13139C64F",
    "sub_created": "1632595729",
    "sub_expires": "2052595729",
}


#: The dates the daemon must derive from the listing above — in UTC, so the
#: result does not depend on the time zone the suite runs in
#: (1632595729 is 2021-09-25T18:48:49Z).
PUB_CREATED_ISO = "2021-09-25"
SUBKEY_EXPIRES_ISO = "2035-01-17"


def colons_listing():
    """A `gpg --with-colons --fingerprint` listing in gpg's real field order."""
    def record(kind, keyid, created, expires):
        return ":".join([kind, "-", "2048", "1", keyid,
                         created or "", expires or "", "", "", "", "", "", ""])

    lines = [record("pub", "KEYID", KEY_DATES["created"], KEY_DATES["expires"]),
             "fpr:" + ":" * 8 + FINGERPRINT + ":"]

    if KEY_DATES["sub_fpr"]:
        lines.append(record("sub", "SUBKEYID", KEY_DATES["sub_created"], KEY_DATES["sub_expires"]))
        lines.append("fpr:" + ":" * 8 + KEY_DATES["sub_fpr"] + ":")

    return "\n".join(lines) + "\n"


def gpg_run(argv):
    """
    Stub gpg: report the declared fingerprint for a colon listing, and a
    machine-readable VALIDSIG record for a signature verification.
    """
    calls.append(list(argv))
    if argv and argv[0] == "gpg":
        if "--verify" in argv:
            if not SIGNATURE["ok"]:
                return subprocess.CompletedProcess(
                    argv, 1,
                    "[GNUPG:] ERRSIG KEYID 1 8 00 1 0\n[GNUPG:] FAILURE sign 33554433\n",
                    "gpg: BAD signature\n",
                )
            signer = SIGNATURE["signer"]
            return subprocess.CompletedProcess(
                argv, 0,
                "[GNUPG:] NEWSIG\n"
                f"[GNUPG:] VALIDSIG {signer} 2026-01-01 0 4 0 1 10 00 {signer}\n"
                "[GNUPG:] GOODSIG KEYID Vendor Repository\n",
                "",
            )
        if "--with-colons" in argv:
            return subprocess.CompletedProcess(argv, 0, colons_listing(), "")
        return subprocess.CompletedProcess(argv, 0, "imported", "")
    return subprocess.CompletedProcess(argv, 0, "", "")


def fake_export(staging, fingerprint, target):
    """Stub the binary-safe key export (the only step that needs a real gpg)."""
    with open(target, "wb") as handle:
        handle.write(b"\x99\x01micron-test-keyring")
    os.chmod(target, 0o644)
    return None


def set_distro(origin, codename):
    micrond.RpcHandler._plugin_repo_distro = staticmethod(lambda: (origin, codename))


def files(directory):
    try:
        return sorted(n for n in os.listdir(directory) if n.startswith(micrond.PLUGIN_REPO_PREFIX))
    except OSError:
        return []


def read(path):
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def clear_repo_files():
    for directory in (SOURCES_DIR, PREF_DIR, KEYRING_DIR):
        for name in os.listdir(directory):
            os.remove(os.path.join(directory, name))


handler._run = gpg_run
handler._plugin_repo_export_key = fake_export

# 15. Without a readable distribution identity the core pin cannot be written,
#     and the apply says so instead of quietly applying a weaker pin.
reset()
clear_repo_files()
set_distro("", "")
res = dispatch("plugin.repo.apply", {"repos": [repo_payload()]})
check("apply without a distribution identity fails closed",
      res["success"] is False and any("os-release" in w for w in res["warnings"]), str(res.get("warnings")))
check("no core pin is written without a distribution identity",
      not os.path.isfile(micrond.PLUGIN_REPO_CORE_PIN))

# 16. A full apply: key verified, source + pin generated, core pin written.
reset()
clear_repo_files()
set_distro("Debian", "bookworm")
res = dispatch("plugin.repo.apply", {"repos": [repo_payload()]})
check("repository apply succeeds", res["success"] is True, str(res.get("warnings")))
check("the signing key is imported and its fingerprint checked",
      any(c[:1] == ["gpg"] and "--import" in c for c in calls)
      and any(c[:1] == ["gpg"] and "--with-colons" in c for c in calls))
check("apt metadata is refreshed", ["apt-get", "update"] in calls)
check("a source stanza is written per repository", files(SOURCES_DIR) == ["micron-vendor.sources"], str(files(SOURCES_DIR)))
check("a pin is written per repository", files(PREF_DIR) == ["micron-plugin-core.pref", "micron-vendor.pref"], str(files(PREF_DIR)))
check("a keyring is written per repository", files(KEYRING_DIR) == ["micron-vendor.gpg"])

source = read(os.path.join(SOURCES_DIR, "micron-vendor.sources"))
check("the stanza is deb822 and deb-only",
      "Types: deb\n" in source and "deb-src" not in source, source)
check("the stanza points at the generated keyring",
      "Signed-By: " + os.path.join(KEYRING_DIR, "micron-vendor.gpg") in source, source)
check("the stanza carries the validated fields",
      "URIs: https://download.example.com/debian" in source
      and "Suites: stable" in source and "Components: main" in source
      and "Architectures: amd64" in source, source)
check("no apt option syntax can ride along", "[" not in source)

pin = read(os.path.join(PREF_DIR, "micron-vendor.pref"))
check("the repository is pinned below the distribution",
      "Pin-Priority: 100" in pin and "Pin: origin \"download.example.com\"" in pin, pin)

core = read(micrond.PLUGIN_REPO_CORE_PIN)
check("the protected core is pinned to the distribution's own release",
      "Package: nftables" in core and "Pin: release o=Debian, n=bookworm" in core
      and "Pin-Priority: 990" in core, core)
check("the core pin covers every protected package",
      all(f"Package: {p}" in core for p in sorted(micrond.PROTECTED_PACKAGES)),
      str([p for p in sorted(micrond.PROTECTED_PACKAGES) if f"Package: {p}" not in core]))

# 17. A key whose fingerprint does not match the declared one is refused.
reset()
clear_repo_files()
res = dispatch("plugin.repo.apply", {"repos": [repo_payload(fingerprint=OTHER_FINGERPRINT)]})
check("a fingerprint mismatch is refused", res["success"] is False, str(res.get("warnings")))
check("a refused key writes no source", "micron-vendor.sources" not in files(SOURCES_DIR))
check("a refused key writes no keyring", "micron-vendor.gpg" not in files(KEYRING_DIR))

# 18. Key material is required inline: the daemon never fetches a key URL itself.
reset()
res = dispatch("plugin.repo.apply", {"repos": [repo_payload(key_armor="", key_url="https://download.example.com/key.asc")]})
check("a bare key URL is refused", res["success"] is False and "inline signing key" in res["message"], str(res.get("message")))
check("a refused repository runs no apt or gpg command", not any(c and c[0] in ("apt-get", "gpg") for c in calls))

reset()
res = dispatch("plugin.repo.apply", {"repos": [repo_payload(key_armor="")]})
check("a repository with no key at all is refused", res["success"] is False, str(res.get("message")))

# 19. Every field is re-validated: nothing the caller sends reaches a file.
for label, payload in [
    ("plain http url", {"url": "http://download.example.com/debian"}),
    ("apt option injection", {"url": "https://download.example.com/debian [trusted=yes]"}),
    ("traversal in the url", {"url": "https://download.example.com/../../etc"}),
    ("an id that is not a slug", {"id": "../../etc/passwd"}),
    ("an id with a slash", {"id": "vendor/evil"}),
    ("the appliance's own distro host", {"url": "https://deb.debian.org/debian"}),
    ("an option in the suite", {"suite": "stable; rm -rf /"}),
    ("an invalid component", {"components": ["main$"]}),
    ("an unsupported architecture", {"architectures": ["sparc64"]}),
    ("a short fingerprint", {"fingerprint": "ABCDEF01"}),
    ("armor that is not armor", {"key_armor": "just text"}),
]:
    reset()
    res = dispatch("plugin.repo.apply", {"repos": [repo_payload(**payload)]})
    check(f"apply refuses {label}", res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("plugin.repo.apply", {"repos": "not-a-list"})
check("apply refuses a non-list payload", res["success"] is False, str(res.get("message")))

reset()
res = dispatch("plugin.repo.apply", {"repos": [repo_payload(), repo_payload()]})
check("apply refuses a duplicated repository id", res["success"] is False and "twice" in res["message"], str(res.get("message")))

# 20. Status reports what is actually applied.
reset()
clear_repo_files()
dispatch("plugin.repo.apply", {"repos": [repo_payload()]})
res = dispatch("plugin.repo_status", {})
check("status lists the applied repository",
      res["success"] is True and [r["id"] for r in res["repos"]] == ["vendor"], str(res.get("repos")))
check("status reports the applied source",
      res["repos"][0]["url"] == "https://download.example.com/debian" and res["repos"][0]["suite"] == "stable")
check("status reports the pin and keyring as present",
      res["repos"][0]["pinned"] is True and res["repos"][0]["keyed"] is True)
check("status reports the core pin", res["core_pinned"] is True and res["core_pin"] == micrond.PLUGIN_REPO_CORE_PIN)

# 21. Re-applying a smaller set retires what is no longer declared.
reset()
dispatch("plugin.repo.apply", {"repos": [repo_payload(id="keep"), repo_payload(id="drop", url="https://drop.example.com/debian")]})
check("both repositories are applied",
      files(SOURCES_DIR) == ["micron-drop.sources", "micron-keep.sources"], str(files(SOURCES_DIR)))
reset()
res = dispatch("plugin.repo.apply", {"repos": [repo_payload(id="keep")]})
check("re-apply succeeds", res["success"] is True, str(res.get("warnings")))
check("the retired source is removed", files(SOURCES_DIR) == ["micron-keep.sources"], str(files(SOURCES_DIR)))
check("the retired pin and keyring are removed",
      "micron-drop.pref" not in files(PREF_DIR) and "micron-drop.gpg" not in files(KEYRING_DIR))
check("an empty apply still refreshes nothing but retires everything",
      dispatch("plugin.repo.apply", {"repos": []})["success"] is True
      and files(SOURCES_DIR) == [] and files(KEYRING_DIR) == [])

# 22. Only MicronOS-owned files are ever touched.
check("the core pin survives an empty apply", os.path.isfile(micrond.PLUGIN_REPO_CORE_PIN))
stray = os.path.join(SOURCES_DIR, "debian.list")
with open(stray, "w", encoding="utf-8") as handle:
    handle.write("deb https://deb.debian.org/debian stable main\n")
reset()
dispatch("plugin.repo.apply", {"repos": [repo_payload()]})
check("a foreign sources file is left alone", os.path.isfile(stray))
check("a foreign file is not reported as applied",
      [r["id"] for r in dispatch("plugin.repo_status", {})["repos"]] == ["vendor"])
os.remove(stray)

# ======================================================================
# Read-only repository verification (plugin.repo_test)
# ======================================================================


def release_body(suite="stable", codename="vendorbook",
                 valid="Thu, 01 Jan 2100 00:00:00 UTC",
                 architectures="amd64 arm64", components="main"):
    """A clearsigned InRelease, as a vendor would publish it."""
    return ("-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n"
            "Origin: Vendor Repository\nLabel: Vendor\n"
            f"Suite: {suite}\nCodename: {codename}\n"
            "Date: Mon, 05 Oct 2026 00:00:00 UTC\n"
            f"Architectures: {architectures}\nComponents: {components}\n"
            f"Valid-Until: {valid}\n"
            "-----BEGIN PGP SIGNATURE-----\n\nc2ln\n-----END PGP SIGNATURE-----\n")


# 23. A good repository verifies: key matched, signature checked, fields read.
reset()
clear_repo_files()
SIGNATURE.update({"ok": True, "signer": FINGERPRINT})
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body()})
check("verification succeeds for a well-formed repository", res["success"] is True, str(res.get("message")))
check("the declared key is the one the keyring held",
      res["key"]["matched"] is True and res["key"]["declared"] == FINGERPRINT
      and res["key"]["found"] == [FINGERPRINT], str(res.get("key")))
check("the release signature is verified and attributed",
      res["signature"] == {"verified": True, "signer": FINGERPRINT, "mode": "inrelease"}, str(res.get("signature")))
check("the release fields are reported",
      res["release"]["origin"] == "Vendor Repository"
      and res["release"]["suite"] == "stable"
      and res["release"]["codename"] == "vendorbook"
      and res["release"]["architectures"] == ["amd64", "arm64"]
      and res["release"]["components"] == ["main"], str(res.get("release")))
check("a clean repository reports no warnings", res["warnings"] == [], str(res.get("warnings")))
check("the metadata is verified with a private keyring",
      any(c[:1] == ["gpg"] and "--verify" in c for c in calls), str(calls))

# 23b. The signing key's *own* dates. A repository whose metadata has gone stale
#      and a repository whose signing key is about to expire are different
#      problems, and only gpg knows the second one.
check("the signing key's own creation date is reported",
      res["key"].get("created_at") == PUB_CREATED_ISO, str(res.get("key")))
check("a key that never expires reports no expiry",
      res["key"].get("expires_at") == "", str(res.get("key")))
check("an encryption subkey's expiry is not reported as the signing key's",
      res["key"].get("expires_at") != SUBKEY_EXPIRES_ISO, str(res.get("key")))

reset()
KEY_DATES["expires"] = "1767225600"  # 2026-01-01
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body()})
check("an expiring signing key reports its expiry date",
      res["key"].get("expires_at") == "2026-01-01", str(res.get("key")))
KEY_DATES["expires"] = None

reset()
KEY_DATES["created"] = "not-an-epoch"
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body()})
check("an unparsable key date degrades to empty rather than failing",
      res["success"] is True and res["key"].get("created_at") == "", str(res.get("key")))
KEY_DATES["created"] = "1632595729"

# 23c. A subkey's fingerprint must not satisfy the trust anchor. An encryption
#      subkey does not sign release metadata, so accepting one as the declared
#      key would accept a declaration that can never verify a signature.
reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(fingerprint=KEY_DATES["sub_fpr"]), "release": release_body()})
check("a subkey fingerprint is refused as the declared trust anchor",
      res["success"] is False and "does not match the declared" in res["message"],
      str(res.get("message")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body()})
check("only primary keys are reported as found",
      res["success"] is True and res["key"]["found"] == [FINGERPRINT]
      and KEY_DATES["sub_fpr"] not in res["key"]["found"], str(res.get("key")))
SIGNATURE.update({"ok": True, "signer": FINGERPRINT})

# Read-only by construction: no file, no apt state, no export.
check("no source, keyring or pin is written by a test",
      files(SOURCES_DIR) == [] and files(KEYRING_DIR) == [] and files(PREF_DIR) == [],
      str([files(SOURCES_DIR), files(KEYRING_DIR), files(PREF_DIR)]))
check("a test runs no package operation", not any(c and c[0] == "apt-get" for c in calls), str(calls))
check("a test never exports a key into the apt keyring",
      not any(c[:1] == ["gpg"] and "--export" in c for c in calls))

# 24. A key whose fingerprint does not match the declared one is refused, and no
#     signature is even attempted.
reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(fingerprint=OTHER_FINGERPRINT), "release": release_body()})
check("a fingerprint mismatch is refused", res["success"] is False, str(res.get("message")))
check("a fingerprint mismatch is classified as such",
      res.get("reason") == "fingerprint_mismatch", str(res.get("reason")))
check("a mismatched key is compared before any signature check",
      any(c[:1] == ["gpg"] and "--with-colons" in c for c in calls)
      and not any(c[:1] == ["gpg"] and "--verify" in c for c in calls), str(calls))

# 25. A release signed by a different key is refused even though it is signed.
reset()
SIGNATURE.update({"ok": True, "signer": OTHER_FINGERPRINT})
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body()})
check("a release signed by another key is refused", res["success"] is False, str(res.get("message")))
check("the mismatched signer is named",
      OTHER_FINGERPRINT in res["message"] and FINGERPRINT in res["message"], str(res.get("message")))
# This is the key-rotation signal: the vendor signs with a key other than the one
# the operator declared. Drift alerting has to distinguish it from a network
# failure, and prose is not a contract — the reason code is.
check("a signer mismatch is classified as a rotation",
      res.get("reason") == "signer_mismatch", str(res.get("reason")))

# 26. A bad signature is refused.
reset()
SIGNATURE.update({"ok": False, "signer": FINGERPRINT})
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body()})
check("an invalid signature is refused",
      res["success"] is False and "not validly signed" in res["message"], str(res.get("message")))
check("a bad signature is classified apart from a rotation",
      res.get("reason") == "signature_invalid", str(res.get("reason")))
SIGNATURE.update({"ok": True, "signer": FINGERPRINT})

# 27. The older detached Release + Release.gpg form is verified too.
reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body(),
                                    "signature": "-----BEGIN PGP SIGNATURE-----\n\nZGV0YWNoZWQ=\n-----END PGP SIGNATURE-----\n"})
check("a detached signature verifies", res["success"] is True and res["signature"]["mode"] == "release", str(res.get("message")))
check("both files are passed to gpg",
      any(c[:1] == ["gpg"] and "--verify" in c and c[-1].endswith("Release.gpg") for c in calls), str(calls))

# 28. The release is compared to what was declared — the misconfigurations a bare
#     reachability probe would miss.
reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body(suite="testing", codename="trixtwo")})
check("verification still succeeds for a different suite", res["success"] is True, str(res.get("message")))
check("a different suite is flagged",
      any("configured for" in w for w in res["warnings"]), str(res.get("warnings")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body(components="contrib")})
check("a component the release does not publish is flagged",
      any("component main" in w for w in res["warnings"]), str(res.get("warnings")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body(architectures="arm64")})
check("an unpublished architecture is flagged",
      any("no amd64" in w for w in res["warnings"]), str(res.get("warnings")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": release_body(valid="Mon, 01 Jan 2024 00:00:00 UTC")})
check("expired release metadata is flagged",
      any("expired on" in w for w in res["warnings"]), str(res.get("warnings")))

# 29. Everything the apply path refuses, the test path refuses too.
reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": "   "})
check("verification with no metadata is refused",
      res["success"] is False and "no release metadata" in res["message"], str(res.get("message")))
check("missing metadata carries its own reason code",
      res.get("reason") == "release_missing", str(res.get("reason")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(), "release": "A" * (micrond.PLUGIN_REPO_RELEASE_MAX + 1)})
check("an oversized release is refused",
      res["success"] is False and "KiB limit" in res["message"], str(res.get("message")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(key_armor="", key_url="https://download.example.com/key.asc"), "release": release_body()})
check("a bare key url is refused by the test path",
      res["success"] is False and "inline signing key" in res["message"], str(res.get("message")))

reset()
res = dispatch("plugin.repo_test", {"repo": repo_payload(url="http://download.example.com/debian"), "release": release_body()})
check("an invalid repository url is refused by the test path",
      res["success"] is False and calls == [], str(res.get("message")))

reset()
res = dispatch("plugin.repo_test", {"release": release_body()})
check("verification without a repository is refused", res["success"] is False, str(res.get("message")))

check("no verification test left files behind",
      files(SOURCES_DIR) == [] and files(KEYRING_DIR) == [],
      str([files(SOURCES_DIR), files(KEYRING_DIR)]))

# ---------------------------------------------------------------------------
# Class C: the NetBird overlay
# ---------------------------------------------------------------------------

# The agent binary is stubbed by an empty executable file, and the plugin's own
# staging directory is redirected so a real 0600 key file can be inspected.
NETBIRD_DIR = tempfile.mkdtemp(prefix="micron-netbird-")
NETBIRD_STUB = os.path.join(NETBIRD_DIR, "netbird")
with open(NETBIRD_STUB, "w", encoding="utf-8") as handle:
    handle.write("#!/bin/sh\n")
micrond.NETBIRD_BIN = NETBIRD_STUB

NETBIRD_STATUS_JSON = strict_json_dumps({
    "fqdn": "micron-appliance.netbird.cloud",
    "netbirdIp": "100.64.0.7",
    "interfaceName": "wt0",
    "publicKey": "xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg=",
    "daemonVersion": "0.75.0",
    "managementState": {"URL": "https://api.netbird.io:443", "connected": True, "error": ""},
    "signalState": {"URL": "https://signal.netbird.io:443", "connected": True},
    "peers": {"total": 4, "connected": 3},
})

# The repository section above replaced the process runner with its gpg stub,
# which answers every non-gpg command with empty output; the overlay checks need
# the plain stub, whose `systemctl is-active` answers "active".
handler._run = fake_run

# 31. A Class C package comes from a third-party repository, and the daemon
#     refuses the install until that repository is APPLIED on this appliance —
#     not merely enabled in the management plane's database.
reset()
res = dispatch("plugin.install", {"id": "netbird", "grants": ["system.service:netbird"]})
check("a Class C install is refused while its repository is not applied",
      res["success"] is False and res["status"] == "blocked" and "not applied" in res["message"],
      str(res.get("message")))
check("a refused Class C install runs no apt command",
      not any(c[:1] == ["apt-get"] for c in calls), str(calls))

# 32. With the repository applied (the sources file the daemon itself writes),
#     the same install proceeds.
reset()
with open(os.path.join(SOURCES_DIR, micrond.PLUGIN_REPO_PREFIX + "netbird.sources"), "w", encoding="utf-8") as handle:
    handle.write("Types: deb\n")
res = dispatch("plugin.install", {"id": "netbird", "grants": ["system.service:netbird"]})
check("an applied repository unblocks the Class C install", res["success"] is True, str(res.get("message")))
check("the Class C install resolves the vendor package and enables its unit",
      any(c[:4] == ["apt-get", "-s", "-y", "install"] for c in calls)
      and ["systemctl", "start", "netbird"] in calls, str(calls))

# 33. Status flattens the agent's JSON into the fields the page renders.
reset()
behaviour[(NETBIRD_STUB, "status", "--json")] = (0, NETBIRD_STATUS_JSON, "")
res = dispatch("plugin.netbird.status", {})
check("overlay status reports the daemon's fields",
      res["success"] is True and res["data"]["fqdn"] == "micron-appliance.netbird.cloud"
      and res["data"]["management_connected"] is True
      and res["data"]["peers_connected"] == 3 and res["data"]["peers_total"] == 4, str(res.get("data")))
check("the status call is read-only",
      all(c[:1] == [NETBIRD_STUB] or c == ["systemctl", "is-active", micrond.NETBIRD_UNIT]
          for c in calls), str(calls))

# 34. An unparsable status is surfaced, never rendered as a healthy peer.
reset()
behaviour[(NETBIRD_STUB, "status", "--json")] = (0, "not json at all", "")
res = dispatch("plugin.netbird.status", {})
check("unparsable status is warned about",
      res["data"] == {} and any("no parsable JSON" in w for w in res["warnings"]), str(res.get("warnings")))

# 35. The overlay RPCs refuse to guess when the agent is not usable.
reset()
behaviour[("systemctl", "is-active", "netbird")] = (3, "inactive", "")
res = dispatch("plugin.netbird.status", {})
check("a stopped agent is refused instead of reported",
      res["success"] is False and "not running" in res["message"], str(res.get("message")))
check("nothing is executed when the agent is stopped",
      not any(c[:1] == [NETBIRD_STUB] for c in calls), str(calls))

reset()
saved_bin, micrond.NETBIRD_BIN = micrond.NETBIRD_BIN, os.path.join(NETBIRD_DIR, "absent")
res = dispatch("plugin.netbird.status", {})
check("a missing agent binary is refused",
      res["success"] is False and "not installed" in res["message"], str(res.get("message")))
micrond.NETBIRD_BIN = saved_bin

# 36. Enrolling hands the setup key over through a 0600 file, then removes it.
reset()
SECRET = "SETUPKEY1234567890"
observed = {}

def observing_run(argv):
    if "--setup-key-file" in argv:
        path = argv[argv.index("--setup-key-file") + 1]
        observed["path"] = path
        try:
            observed["mode"] = oct(os.stat(path).st_mode & 0o777)
            with open(path, encoding="utf-8") as handle:
                observed["body"] = handle.read().strip()
        except OSError as exc:
            observed["error"] = str(exc)
    return fake_run(argv)

handler._run = observing_run
behaviour[(NETBIRD_STUB, "up")] = (0, NETBIRD_STATUS_JSON, "")
res = dispatch("plugin.netbird.enroll", {"setup_key": SECRET})
handler._run = fake_run

check("enrolment runs the agent's up command",
      any(c[:2] == [NETBIRD_STUB, "up"] for c in calls), str(calls))
check("the setup key never appears in the command line",
      not any(SECRET in " ".join(str(a) for a in c) for c in calls), str(calls))
check("the setup key is staged in a daemon-private file",
      observed.get("body") == SECRET
      and os.path.basename(os.path.dirname(observed.get("path") or "")).startswith("micron-netbird-"),
      str(observed))
if POSIX_MODES:
    check("the staged setup key file is 0600", observed.get("mode") == "0o600", str(observed))
else:
    print("SKIP the staged setup key file is 0600  [mode bits unobservable on this host]")
check("the staged key file is removed afterwards",
      observed.get("path") is not None and not os.path.exists(observed["path"]), str(observed.get("path")))
check("the setup key is never echoed back in the response",
      SECRET not in strict_json_dumps(res), str(res.get("steps")))
check("no step names the setup key",
      not any(SECRET in step for step in res.get("steps", [])), str(res.get("steps")))

# 37. A malformed setup key is refused before anything runs, and a management
#     URL has to be https.
reset()
res = dispatch("plugin.netbird.enroll", {"setup_key": "short key with spaces"})
check("a malformed setup key is refused",
      res["success"] is False and "setup key" in res["message"], str(res.get("message")))
check("a refused setup key runs no command", calls == [], str(calls))

reset()
res = dispatch("plugin.netbird.enroll", {"setup_key": SECRET, "management_url": "http://api.internal"})
check("a non-https management URL is refused",
      res["success"] is False and "https" in res["message"], str(res.get("message")))
check("a refused management URL runs no command", calls == [], str(calls))

# 38. up / down drive the agent, and up validates the management URL too.
reset()
behaviour[(NETBIRD_STUB, "up")] = (0, NETBIRD_STATUS_JSON, "")
res = dispatch("plugin.netbird.up", {"management_url": "https://api.self-hosted.example:443"})
check("up connects with the self-hosted management URL",
      res["success"] is True
      and [NETBIRD_STUB, "up", "--management-url", "https://api.self-hosted.example:443"] in calls,
      str(calls))

reset()
behaviour[(NETBIRD_STUB, "down")] = (0, NETBIRD_STATUS_JSON, "")
res = dispatch("plugin.netbird.down", {})
check("down disconnects the peer",
      res["success"] is True and [NETBIRD_STUB, "down"] in calls, str(calls))

# 39. The overlay RPCs are unknown to a daemon that has no such plugin entry,
#     so a caller cannot reach them by inventing an id.
reset()
saved_catalog = dict(micrond.PLUGIN_CATALOG)
del micrond.PLUGIN_CATALOG["netbird"]
res = dispatch("plugin.netbird.status", {})
check("the overlay refuses when the plugin is not in the catalogue",
      res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved_catalog)

# Clean up the class C fixtures so the final state assertions stay honest.
os.unlink(os.path.join(SOURCES_DIR, micrond.PLUGIN_REPO_PREFIX + "netbird.sources"))

# ---------------------------------------------------------------------------
# Class C: the ZeroTier overlay
# ---------------------------------------------------------------------------

ZT_DIR = tempfile.mkdtemp(prefix="micron-zerotier-")
ZT_STUB = os.path.join(ZT_DIR, "zerotier-cli")
with open(ZT_STUB, "w", encoding="utf-8") as handle:
    handle.write("#!/bin/sh\n")
micrond.ZEROTIER_CLI_BIN = ZT_STUB

ZT_INFO = "200 info a0c8f1e2b3 1.14.0 ONLINE"
ZT_NETWORKS = strict_json_dumps([
    {
        "nwid": "8056c2e21c000001", "name": "micron-lab", "status": "OK", "type": "PRIVATE",
        "portDeviceName": "ztabcdef12",
        "assignedAddresses": ["10.147.17.5/24", "fc00:1234::1/64"],
    },
])

# 40. The catalogue entry is Class C for the same reason netbird's is: the
#     package comes from a vendor repository that has to be applied first, and
#     the unit it controls is the package's own.
check("the ZeroTier entry names the third-party repository it needs",
      micrond.PLUGIN_CATALOG["zerotier"].get("repos") == ["zerotier"],
      str(micrond.PLUGIN_CATALOG["zerotier"].get("repos")))
check("the ZeroTier entry resolves to the vendor package and its own unit",
      micrond.PLUGIN_CATALOG["zerotier"].get("packages") == ["zerotier-one"]
      and micrond.PLUGIN_CATALOG["zerotier"].get("units") == {"zerotier-one": "zerotier-one"},
      str(micrond.PLUGIN_CATALOG["zerotier"]))
check("the ZeroTier entry never claims a protected core unit",
      not [c for c in micrond.PLUGIN_CATALOG["zerotier"]["capabilities"]
           if c.startswith("system.service:") and c.split(":", 1)[1] in micrond.PROTECTED_UNITS],
      str(micrond.PLUGIN_CATALOG["zerotier"]["capabilities"]))

# 41. Status reports the node's identity and its memberships.
reset()
behaviour[(ZT_STUB, "info")] = (0, ZT_INFO, "")
behaviour[(ZT_STUB, "-j", "listnetworks")] = (0, ZT_NETWORKS, "")
res = dispatch("plugin.zerotier.status", {})
check("overlay status reports the node's identity",
      res["success"] is True and res["data"]["address"] == "a0c8f1e2b3"
      and res["data"]["version"] == "1.14.0" and res["data"]["online"] is True,
      str(res.get("data")))
check("overlay status reports the joined networks",
      res["data"]["networks_joined"] == 1
      and res["data"]["networks"][0]["nwid"] == "8056c2e21c000001"
      and res["data"]["networks"][0]["device"] == "ztabcdef12"
      and res["data"]["networks"][0]["assigned"] == "10.147.17.5/24 fc00:1234::1/64",
      str(res.get("data")))
check("the status call is read-only",
      all(c[:1] == [ZT_STUB] or c == ["systemctl", "is-active", micrond.ZEROTIER_UNIT]
          for c in calls), str(calls))

# 42. An unreadable membership list is reported, never silently emptied: an
#     empty list means "joined nothing" and must not be invented.
reset()
behaviour[(ZT_STUB, "info")] = (0, ZT_INFO, "")
behaviour[(ZT_STUB, "-j", "listnetworks")] = (0, "not json at all", "")
res = dispatch("plugin.zerotier.status", {})
check("an unreadable network list is warned about, not passed off as none",
      res["success"] is True and res["data"]["networks"] == []
      and any("could not be read as JSON" in w for w in res["warnings"]),
      str(res.get("warnings")))

# 43. An unparsable node identity is reported as unknown, not as a healthy node.
reset()
behaviour[(ZT_STUB, "info")] = (0, "200 something-else entirely", "")
behaviour[(ZT_STUB, "-j", "listnetworks")] = (0, "[]", "")
res = dispatch("plugin.zerotier.status", {})
check("an unparsable node identity leaves the node unknown",
      "address" not in res["data"]
      and any("no parsable node identity" in w for w in res["warnings"]),
      str(res.get("warnings")))

# 44. zerotier-cli reports its own failures on stdout under a zero exit status,
#     so a refused join must not be read as a success.
reset()
behaviour[(ZT_STUB, "join", "8056c2e21c000001")] = (0, "500 join cannot join", "")
res = dispatch("plugin.zerotier.join", {"nwid": "8056c2e21c000001"})
check("a CLI-level refusal is not reported as a successful join",
      res["success"] is False and "join failed" in res["message"], str(res.get("message")))

# 45. A successful join runs the fixed argv and reports the refreshed
#     memberships. The id is lowercased, because the CLI is case-sensitive
#     about network ids and the operator's clipboard is not.
reset()
behaviour[(ZT_STUB, "join", "8056c2e21c000001")] = (0, "200 join OK", "")
behaviour[(ZT_STUB, "info")] = (0, ZT_INFO, "")
behaviour[(ZT_STUB, "-j", "listnetworks")] = (0, ZT_NETWORKS, "")
res = dispatch("plugin.zerotier.join", {"nwid": "8056C2E21C000001"})
check("join runs the CLI with the normalised network id",
      [ZT_STUB, "join", "8056c2e21c000001"] in calls, str(calls))
check("join reports the resulting memberships",
      res["success"] is True and res["data"]["networks_joined"] == 1, str(res.get("data")))

reset()
behaviour[(ZT_STUB, "leave", "8056c2e21c000001")] = (0, "200 leave OK", "")
behaviour[(ZT_STUB, "info")] = (0, ZT_INFO, "")
behaviour[(ZT_STUB, "-j", "listnetworks")] = (0, "[]", "")
res = dispatch("plugin.zerotier.leave", {"nwid": "8056c2e21c000001"})
check("leave runs the CLI and reports the network gone",
      [ZT_STUB, "leave", "8056c2e21c000001"] in calls
      and res["success"] is True and res["data"]["networks_joined"] == 0, str(res.get("data")))

# 46. A malformed network id is refused before anything runs — including the
#     readiness probe, which itself shells out.
for bad in ["", "not-a-network", "8056c2e21c00000", "8056c2e21c0000011",
            "8056c2e21c00000z", "../etc/passwd", "8056c2e21c000001;id"]:
    reset()
    res = dispatch("plugin.zerotier.join", {"nwid": bad})
    check(f"a malformed network id is refused ({bad or 'empty'})",
          res["success"] is False and "16 hexadecimal" in res["message"] and calls == [],
          str(res.get("message")) + " | " + str(calls))

# 47. The overlay RPCs refuse to guess when the agent is not usable.
reset()
behaviour[("systemctl", "is-active", micrond.ZEROTIER_UNIT)] = (3, "inactive", "")
res = dispatch("plugin.zerotier.status", {})
check("a stopped ZeroTier agent is refused instead of reported",
      res["success"] is False and "not running" in res["message"], str(res.get("message")))
check("nothing is executed when the ZeroTier agent is stopped",
      not any(c[:1] == [ZT_STUB] for c in calls), str(calls))

reset()
saved_bin, micrond.ZEROTIER_CLI_BIN = micrond.ZEROTIER_CLI_BIN, os.path.join(ZT_DIR, "absent")
res = dispatch("plugin.zerotier.status", {})
check("a missing zerotier-cli is refused",
      res["success"] is False and "not installed" in res["message"], str(res.get("message")))
micrond.ZEROTIER_CLI_BIN = saved_bin

# 48. The overlay RPCs are unknown to a daemon with no such plugin entry, so a
#     caller cannot reach them by inventing an id.
reset()
saved_catalog = dict(micrond.PLUGIN_CATALOG)
del micrond.PLUGIN_CATALOG["zerotier"]
res = dispatch("plugin.zerotier.status", {})
check("the ZeroTier overlay refuses when the plugin is not in the catalogue",
      res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved_catalog)

set_distro("Debian", "bookworm")

# ---------------------------------------------------------------------------
# Class B: NAT64 / DNS64 (tayga + an Unbound fragment) and the 6in4 tunnel
# ---------------------------------------------------------------------------

# Every path the two plugins write is redirected into temp dirs, so the real
# filesystem is checked without touching /etc.
NAT64_DIR = tempfile.mkdtemp(prefix="micron-nat64-")
micrond.TAYGA_CONF = os.path.join(NAT64_DIR, "tayga.conf")
micrond.NAT64_UNIT_FILE = os.path.join(NAT64_DIR, "micron-nat64-net.service")
micrond.NAT64_DNS64_FRAGMENT = os.path.join(NAT64_DIR, "micron-nat64.conf")
micrond.NAT64_STATE_DIR = os.path.join(NAT64_DIR, "state")

# 41. NAT64 parameters are validated before a single command runs: a bad
#     prefix length is refused without touching systemctl or writing a file.
reset()
res = dispatch("plugin.nat64.apply", {"prefix": "64:ff9b::/44", "pool": "192.168.255.0/24"})
check("nat64 refuses a prefix length RFC 6052 forbids",
      res["success"] is False and "RFC 6052" in res["message"], str(res.get("message")))
check("a bad nat64 prefix runs nothing and writes nothing",
      calls == [] and not os.path.exists(micrond.TAYGA_CONF), str(calls))

reset()
res = dispatch("plugin.nat64.apply", {"prefix": "64:ff9b::/96", "pool": "10.0.0.0/8"})
check("the Well-Known Prefix refuses a private pool (RFC 6052 §5)",
      res["success"] is False and "RFC 6052" in res["message"], str(res.get("message")))
check("the refused WKP config runs nothing",
      calls == [] and not os.path.exists(micrond.TAYGA_CONF), str(calls))

reset()
res = dispatch("plugin.nat64.apply", {"prefix": "64:ff9b::/96"})
check("nat64 without a pool is refused",
      res["success"] is False and "pool" in res["message"], str(res.get("message")))

# 42. A valid apply renders both files, starts the translator and brings the
#     host-side network up behind it.
reset()
res = dispatch("plugin.nat64.apply", {
    "prefix": "2001:db8:1:ffff::/96", "pool": "192.168.255.0/24", "dns64": True,
})
check("a valid nat64 apply succeeds", res["success"] is True,
      str(res.get("message")) + " | " + str(res.get("warnings")))
check("tayga.conf is rendered from the validated CIDRs",
      os.path.exists(micrond.TAYGA_CONF)
      and "prefix 2001:db8:1:ffff::/96" in open(micrond.TAYGA_CONF, encoding="utf-8").read()
      and "dynamic-pool 192.168.255.0/24" in open(micrond.TAYGA_CONF, encoding="utf-8").read(),
      str(open(micrond.TAYGA_CONF, encoding="utf-8").read() if os.path.exists(micrond.TAYGA_CONF) else "missing"))
conf_text = open(micrond.TAYGA_CONF, encoding="utf-8").read() if os.path.exists(micrond.TAYGA_CONF) else ""
check("tayga's own address is the first usable pool address",
      "ipv4-addr 192.168.255.1" in conf_text, conf_text)
# The host-side unit's argv must be the absolute binary as well: it is generated
# the same way, from the same constant, and must not silently depend on PATH.
nat64_unit = (open(micrond.NAT64_UNIT_FILE, encoding="utf-8").read()
              if os.path.exists(micrond.NAT64_UNIT_FILE) else "")
check("the host-side unit routes the NAT64 prefix and the pool into the tun",
      os.path.exists(micrond.NAT64_UNIT_FILE)
      and f"{micrond.IPROUTE_BIN} -6 route replace 2001:db8:1:ffff::/96 dev nat64" in nat64_unit
      and f"{micrond.IPROUTE_BIN} route replace 192.168.255.0/24 dev nat64" in nat64_unit
      and "PartOf=tayga.service" in nat64_unit,
      nat64_unit or "missing")
check("the DNS64 fragment carries the dns64 module chain and the prefix",
      os.path.exists(micrond.NAT64_DNS64_FRAGMENT)
      and 'module-config: "dns64 validator iterator"' in open(micrond.NAT64_DNS64_FRAGMENT, encoding="utf-8").read()
      and "dns64-prefix: 2001:db8:1:ffff::/96" in open(micrond.NAT64_DNS64_FRAGMENT, encoding="utf-8").read(),
      str(open(micrond.NAT64_DNS64_FRAGMENT, encoding="utf-8").read() if os.path.exists(micrond.NAT64_DNS64_FRAGMENT) else "missing"))
check("apply gates the fragment through unbound-checkconf before reloading",
      ["unbound-checkconf"] in calls
      and ["unbound-control", "reload"] in calls, str(calls))
check("the translator is enabled and restarted, then the host network behind it",
      ["systemctl", "enable", "tayga"] in calls and ["systemctl", "restart", "tayga"] in calls
      and ["systemctl", "enable", micrond.NAT64_UNIT] in calls
      and ["systemctl", "restart", micrond.NAT64_UNIT] in calls, str(calls))
check("the NAT64 RPC never controls the protected resolver unit",
      not any(c[:3] == ["systemctl", "restart", "unbound"] or c[:3] == ["systemctl", "stop", "unbound"]
              for c in calls), str(calls))

# 43. A fragment unbound-checkconf rejects is rolled back, not left in place.
#     Start from no fragment at all, so the rollback target is "absent".
if os.path.exists(micrond.NAT64_DNS64_FRAGMENT):
    os.unlink(micrond.NAT64_DNS64_FRAGMENT)
reset()
behaviour[("unbound-checkconf",)] = (1, "", "syntax error in fragment")
res = dispatch("plugin.nat64.apply", {
    "prefix": "2001:db8:1:ffff::/96", "pool": "192.168.255.0/24", "dns64": True,
})
check("a rejected DNS64 fragment fails the apply",
      res["success"] is False and "checkconf" in res["message"], str(res.get("message")))
check("the rejected fragment is rolled back to the previous state",
      not os.path.exists(micrond.NAT64_DNS64_FRAGMENT),
      str(open(micrond.NAT64_DNS64_FRAGMENT, encoding="utf-8").read() if os.path.exists(micrond.NAT64_DNS64_FRAGMENT) else "absent"))

# 44. Disabling tears everything down: fragment, host network, translator.
reset()
res = dispatch("plugin.nat64.apply", {"enabled": False})
check("disabling nat64 succeeds", res["success"] is True, str(res.get("message")))
check("disabling stops and disables the translator and its host network",
      ["systemctl", "stop", "tayga"] in calls and ["systemctl", "disable", "tayga"] in calls
      and ["systemctl", "stop", micrond.NAT64_UNIT] in calls, str(calls))
check("disabling removes the generated unit file",
      not os.path.exists(micrond.NAT64_UNIT_FILE), str(calls))

# 45. Status is read-only and reports both halves.
reset()
res = dispatch("plugin.nat64.status", {})
check("nat64 status is read-only",
      all(c[:2] == ["systemctl", "is-active"] for c in calls), str(calls))
check("nat64 status reports translator and fragment state",
      res["success"] is True and "tayga_state" in res["data"]
      and "dns64_fragment_present" in res["data"], str(res.get("data")))

# ---------------------------------------------------------------------------
# 6in4 tunnel broker (configuration-only: no package at all)
# ---------------------------------------------------------------------------

TUNNEL_DIR = tempfile.mkdtemp(prefix="micron-tunnel-")
micrond.TUNNEL_UNIT_FILE = os.path.join(TUNNEL_DIR, "micron-6in4.service")
micrond.TUNNEL_STATE_DIR = os.path.join(TUNNEL_DIR, "state")

# 46. Install of the configuration-only plugin runs no apt at all.
reset()
res = dispatch("plugin.install", {"id": "ipv6-tunnel", "grants": ["kernel.module:sit"]})
check("installing the tunnel plugin succeeds with no packages",
      res["success"] is True and res["packages"] == [], str(res.get("message")))
check("the configuration-only install runs no apt command",
      not any(c[:1] == ["apt-get"] for c in calls), str(calls))

# 47. Endpoint validation happens before anything is written.
reset()
res = dispatch("plugin.tunnel.apply", {
    "endpoint_v4": "not-an-ip", "client_v6": "2001:470:1f08:a2a::2/64",
    "server_v6": "2001:470:1f09:a2a::1",
})
check("a non-IPv4 broker endpoint is refused",
      res["success"] is False and "endpoint_v4" in res["message"], str(res.get("message")))
check("the refused endpoint writes nothing and runs nothing",
      calls == [] and not os.path.exists(micrond.TUNNEL_UNIT_FILE), str(calls))

reset()
res = dispatch("plugin.tunnel.apply", {
    "endpoint_v4": "216.66.80.30", "local_v4": "auto",
    "client_v6": "2001:470:1f08:a2a::2", "server_v6": "2001:470:1f09:a2a::1",
})
check("a client address without its prefix length is refused",
      res["success"] is False and "prefix" in res["message"], str(res.get("message")))

reset()
res = dispatch("plugin.tunnel.apply", {
    "endpoint_v4": "216.66.80.30", "local_v4": "198.51.100.7",
    "client_v6": "2001:470:1f08:a2a::2/64", "server_v6": "2001:470:1f09:a2a::1",
    "update_key": "short",
})
check("a malformed update key is refused before anything is staged",
      res["success"] is False and "update_key" in res["message"], str(res.get("message")))
check("the refused key leaves no key file behind",
      calls == [] and not os.path.exists(os.path.join(micrond.TUNNEL_STATE_DIR, "update.key")), str(calls))

reset()
res = dispatch("plugin.tunnel.apply", {
    "endpoint_v4": "216.66.80.30", "local_v4": "198.51.100.7",
    "client_v6": "2001:470:1f08:a2a::2/64", "server_v6": "2001:470:1f09:a2a::1",
    "update_key": "a1b2c3d4e5f6",
})
check("a key without the update identity is refused (badauth prevention)",
      res["success"] is False and "together" in res["message"], str(res.get("message")))

# 48. A valid apply renders the unit with fixed argv and stages the key 0600.
reset()
res = dispatch("plugin.tunnel.apply", {
    "endpoint_v4": "216.66.80.30", "local_v4": "198.51.100.7",
    "client_v6": "2001:470:1f08:a2a::2/64", "server_v6": "2001:470:1f09:a2a::1",
    "routed_prefix": "2001:470:1f0b:a2a::/64", "mtu": 1480,
    "update_user": "micron", "update_tunnel": "12056",
    "update_key": "a1b2c3d4e5f6",
})
check("a valid tunnel apply succeeds", res["success"] is True,
      str(res.get("message")) + " | " + str(res.get("warnings")))
unit_text = open(micrond.TUNNEL_UNIT_FILE, encoding="utf-8").read() if os.path.exists(micrond.TUNNEL_UNIT_FILE) else ""
# The unit's argv must be the absolute binary, so this harness reads the daemon's
# own constant instead of repeating the path — the two cannot drift apart again.
# They were once both named IP_BIN, and the later module-level definition won, so
# every generated unit used a PATH lookup while these checks contradicted each
# other: one asserted a bare `ip`, the next `/usr/sbin/ip`, and no single unit could
# satisfy both.
ip_bin = micrond.IPROUTE_BIN
check("the unit generator's ip binary is an absolute path, never a PATH lookup",
      ip_bin.startswith("/") and ip_bin.endswith("/ip"), ip_bin)
check("the generated unit builds the SIT tunnel with fixed ip argv",
      f"{ip_bin} tunnel add sit1 mode sit remote 216.66.80.30 local 198.51.100.7 ttl 64" in unit_text,
      unit_text)
check("the unit carries the addresses, MTU and the broker's routed prefix",
      f"{ip_bin} addr replace 2001:470:1f08:a2a::2/64 dev sit1" in unit_text
      and f"{ip_bin} link set sit1 up mtu 1480" in unit_text
      and f"{ip_bin} -6 route replace 2001:470:1f0b:a2a::/64 dev sit1" in unit_text
      and f"{ip_bin} -6 route replace 2001:470:1f09:a2a::1/128 dev sit1" in unit_text, unit_text)
check("the unit never installs a default route — that stays on the Routing page",
      f"{ip_bin} route replace default" not in unit_text, unit_text)
check("teardown is an explicit ExecStop of the same fixed argv",
      f"ExecStop=-{ip_bin} tunnel del sit1" in unit_text, unit_text)
# Every ip invocation, not only the ones named above: a regression to a bare `ip`
# anywhere in the unit is the defect this whole block exists to catch.
ip_lines = [line for line in unit_text.splitlines()
            if line.startswith("Exec") and "ip " in line]
check("every ip invocation in the unit is the absolute binary",
      len(ip_lines) >= 6
      and all(f"={ip_bin} " in line or f"=-{ip_bin} " in line for line in ip_lines),
      repr(ip_lines))
key_path = os.path.join(micrond.TUNNEL_STATE_DIR, "update.key")
check("the update key is staged as a 0600 file in the daemon's own directory",
      os.path.exists(key_path), str(os.listdir(micrond.TUNNEL_STATE_DIR)))
if POSIX_MODES:
    check("the staged key file really is 0600",
          (os.stat(key_path).st_mode & 0o777) == 0o600,
          oct(os.stat(key_path).st_mode & 0o777))
check("the apply response never echoes the update key",
      "a1b2c3d4e5f6" not in strict_json_dumps(res), str(res))
check("the stored state never contains the update key",
      "a1b2c3d4e5f6" not in open(micrond.TUNNEL_STATE_DIR + "/state.json", encoding="utf-8").read(),
      open(micrond.TUNNEL_STATE_DIR + "/state.json", encoding="utf-8").read())
check("the unit is enabled and restarted",
      ["systemctl", "daemon-reload"] in calls
      and ["systemctl", "enable", "micron-6in4"] in calls
      and ["systemctl", "restart", "micron-6in4"] in calls, str(calls))

# 49. Status and update: read-only status, and the broker call carries the key
#     only inside the request — never in steps, never in the response.
reset()
res = dispatch("plugin.tunnel.status", {})
check("tunnel status is read-only and reports stored config without the key",
      res["success"] is True and res["data"].get("update_key_stored") is True
      and "update_key" not in res["data"]
      and all(c[:2] == ["systemctl", "is-active"] for c in calls), str(res))
check("tunnel status never exposes the key",
      "a1b2c3d4e5f6" not in strict_json_dumps(res), str(res))

# The update call is intercepted: a fake opener answers dyndns2-style, so no
# request leaves the harness (and no real broker is ever contacted).
fake_urls = []
real_build_opener = micrond.urllib.request.build_opener

class _FakeResponse:
    def __init__(self, body):
        self._body = body.encode("utf-8")

    def read(self, size=-1):
        return self._body if size is None or size < 0 else self._body[:size]

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

class _FakeOpener:
    def __init__(self, body, sink):
        self._body, self._sink = body, sink

    def open(self, url, timeout=None):
        self._sink.append(url)
        return _FakeResponse(self._body)

micrond.urllib.request.build_opener = lambda *a, **k: _FakeOpener("good 198.51.100.7", fake_urls)

reset()
res = dispatch("plugin.tunnel.update", {})
micrond.urllib.request.build_opener = real_build_opener
check("the broker endpoint update succeeds on 'good'",
      res["success"] is True and "good" in res["message"], str(res.get("message")))
check("the update goes to the constant broker host with key-authenticated query",
      len(fake_urls) == 1 and fake_urls[0].startswith("https://ipv4.tunnelbroker.net/nic/update?")
      and "username=micron" in fake_urls[0] and "hostname=12056" in fake_urls[0]
      and "password=a1b2c3d4e5f6" in fake_urls[0], str(fake_urls))
check("the update steps redact the credential",
      all("a1b2c3d4e5f6" not in s for s in res.get("steps", [])), str(res.get("steps")))
check("the update response never echoes the key",
      "a1b2c3d4e5f6" not in strict_json_dumps(res), str(res))

# 50. Without an update key stored, the update refuses before any request.
reset()
os.unlink(key_path)
fake_urls.clear()
res = dispatch("plugin.tunnel.update", {})
check("update without a stored key is refused and contacts nothing",
      res["success"] is False and "update key" in res["message"] and fake_urls == [],
      str(res.get("message")))

# 51. Disabling removes the unit; a cleared key really is gone.
reset()
res = dispatch("plugin.tunnel.apply", {
    "enabled": False, "endpoint_v4": "216.66.80.30", "local_v4": "198.51.100.7",
    "client_v6": "2001:470:1f08:a2a::2/64", "server_v6": "2001:470:1f09:a2a::1",
    "update_key": "",
})
check("disabling the tunnel succeeds and stops the unit",
      res["success"] is True and ["systemctl", "stop", "micron-6in4"] in calls, str(calls))
check("disabling removes the generated unit file",
      not os.path.exists(micrond.TUNNEL_UNIT_FILE), str(calls))
check("an emptied update key really is cleared",
      not os.path.exists(key_path), str(os.listdir(micrond.TUNNEL_STATE_DIR)))

# 52. Both overlay-style RPC families refuse ids that are not in the catalogue.
reset()
saved_catalog = dict(micrond.PLUGIN_CATALOG)
del micrond.PLUGIN_CATALOG["nat64"]
del micrond.PLUGIN_CATALOG["ipv6-tunnel"]
res = dispatch("plugin.nat64.status", {})
check("nat64 status refuses when the plugin is not in the catalogue",
      res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
res = dispatch("plugin.tunnel.status", {})
check("tunnel status refuses when the plugin is not in the catalogue",
      res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved_catalog)

# ---------------------------------------------------------------------------
# Class B: darkstat (settings rendered into a systemd drop-in)
# ---------------------------------------------------------------------------

DARKSTAT_DIR = tempfile.mkdtemp(prefix="micron-darkstat-")
micrond.DARKSTAT_DROPIN_DIR = os.path.join(DARKSTAT_DIR, "dropin")
micrond.DARKSTAT_DROPIN = os.path.join(DARKSTAT_DIR, "dropin", "micron.conf")
micrond.DARKSTAT_STATE_DIR = os.path.join(DARKSTAT_DIR, "state")
darkstat_conf = micrond.DARKSTAT_DROPIN

# 53. Every malformed field is refused before a single command runs or a byte is
#     written — the same "validate first" rule as netbird/nat64/tunnel.
for label, bad in (
    ("a missing interface", {"interface": ""}),
    ("a port out of range", {"interface": "eth0", "port": 0}),
    ("a non-interface name", {"interface": "eth$0"}),
    ("a non-CIDR local network", {"interface": "eth0", "local_nets": "192.168.0.0/33"}),
    ("an IPv6 local network", {"interface": "eth0", "local_nets": "2001:db8::/32"}),
    ("hosts-keep not below hosts-max", {"interface": "eth0", "hosts_max": 50, "hosts_keep": 50}),
    ("a chroot that climbs out", {"interface": "eth0", "chroot_dir": "/var/lib/../../etc"}),
    ("a database file with a slash", {"interface": "eth0", "database_file": "../../etc/passwd"}),
):
    reset()
    res = dispatch("plugin.darkstat.apply", bad)
    check(f"darkstat refuses {label}",
          res["success"] is False, str(res.get("message")))
    check(f"  …and {label} runs nothing and writes nothing",
          calls == [] and not os.path.exists(darkstat_conf), str(calls))

# 54. A valid apply renders the drop-in, restarts the package's own unit. The
#     interface list is de-duplicated; local networks become darkstat's
#     network/netmask form; a filter with spaces is quoted as one argument.
reset()
res = dispatch("plugin.darkstat.apply", {
    "enabled": True,
    "interface": "eth0, eth0, eth1",
    "port": 8080,
    "local_nets": "192.168.0.0/16, 10.0.0.0/8",
    "filter": "not (src net 192.168.0.0/16 and dst net 192.168.0.0/16)",
    "resolve_dns": False,
    "local_only": True,
    "chroot_dir": "/var/lib/darkstat",
    "database_file": "darkstat.db",
    "daylog": "darkstat-day.log",
    "hosts_max": 2000,
    "hosts_keep": 20,
})
check("a valid darkstat apply succeeds", res["success"] is True,
      str(res.get("message")) + " | " + str(res.get("warnings")))
check("the drop-in is written", os.path.exists(darkstat_conf), str(os.listdir(DARKSTAT_DIR)))
ds_text = open(darkstat_conf, encoding="utf-8").read() if os.path.exists(darkstat_conf) else ""
check("the drop-in clears and replaces ExecStart",
      "[Service]" in ds_text and "ExecStart=\n" in ds_text and ds_text.count("ExecStart=") == 2, ds_text)
check("the interface list is de-duplicated and repeated with -i",
      "-i eth0 -i eth1" in ds_text and "-i eth0 -i eth0" not in ds_text, ds_text)
check("the port is passed through", "-p 8080" in ds_text, ds_text)
check("local networks become network/netmask",
      "-l 192.168.0.0/255.255.0.0" in ds_text and "-l 10.0.0.0/255.0.0.0" in ds_text, ds_text)
check("a spaced BPF filter is one quoted argument",
      '-f "not (src net 192.168.0.0/16 and dst net 192.168.0.0/16)"' in ds_text, ds_text)
check("DNS resolution off and local-only on are rendered",
      "--no-dns" in ds_text and "--local-only" in ds_text, ds_text)
check("the chroot, database and daylog are relative-to-chroot bare names",
      "--chroot /var/lib/darkstat" in ds_text
      and "--import darkstat.db --export darkstat.db" in ds_text
      and "--daylog darkstat-day.log" in ds_text, ds_text)
check("the hosts caps are rendered",
      "--hosts-max 2000 --hosts-keep 20" in ds_text, ds_text)
check("systemd runs it in the foreground and logs to the journal",
      "--syslog --no-daemon" in ds_text, ds_text)
check("the drop-in replaces Debian's SysV forking wrapper (Type=simple, no RemainAfterExit)",
      "Type=simple" in ds_text and "RemainAfterExit=no" in ds_text
      and "\nExecStop=\n" in ds_text and "Restart=on-failure" in ds_text, ds_text)
check("darkstat drops to an account that owns the chroot",
      "--user nobody" in ds_text
      and "ExecStartPre=/usr/bin/install -d -m 0750 -o nobody -g nogroup /var/lib/darkstat" in ds_text, ds_text)
check("a capture interface that does not exist is reported, not hidden",
      any("not present" in w for w in res.get("warnings", [])) or os.path.exists("/sys/class/net/eth1"),
      str(res.get("warnings")))
check("the package's own unit is reloaded, enabled and restarted",
      ["systemctl", "daemon-reload"] in calls
      and ["systemctl", "enable", "darkstat"] in calls
      and ["systemctl", "restart", "darkstat"] in calls, str(calls))
check("the applied settings are persisted for the status page",
      os.path.exists(os.path.join(micrond.DARKSTAT_STATE_DIR, "state.json"))
      and '"port": 8080' in open(os.path.join(micrond.DARKSTAT_STATE_DIR, "state.json"), encoding="utf-8").read(),
      str(os.listdir(micrond.DARKSTAT_STATE_DIR)))

# 55. systemd's own parser: `%` is a specifier prefix and is doubled.
reset()
res = dispatch("plugin.darkstat.apply", {"interface": "eth0", "port": 8080, "filter": "ip[2:2] % 2 == 0"})
check("a percent sign in a filter is doubled for systemd",
      res["success"] is True
      and "%%" in open(darkstat_conf, encoding="utf-8").read(),
      open(darkstat_conf, encoding="utf-8").read() if os.path.exists(darkstat_conf) else "missing")

# 56. Status is read-only and reports the applied settings and live state.
reset()
res = dispatch("plugin.darkstat.status", {})
check("darkstat status succeeds and reports the service state",
      res["success"] is True and res["data"]["service_state"] == "running", str(res))
check("status reports the applied port and that the drop-in is present",
      res["data"].get("port") == 8080 and res["data"].get("dropin_present") is True, str(res["data"]))
check("status is read-only", [c for c in calls if c[:2] == ["systemctl", "restart"]] == [], str(calls))
check("status probes whether the web UI port actually answers",
      "listening" in res["data"] and isinstance(res["data"]["listening"], bool), str(res["data"]))

# 57. Disabling removes the drop-in and stops the unit.
reset()
res = dispatch("plugin.darkstat.apply", {"enabled": False})
check("disabling darkstat succeeds and stops the unit",
      res["success"] is True and ["systemctl", "stop", "darkstat"] in calls, str(calls))
check("disabling removes the generated drop-in", not os.path.exists(darkstat_conf), str(calls))

# 58. The darkstat RPCs refuse an id that is not in the catalogue.
reset()
saved_catalog = dict(micrond.PLUGIN_CATALOG)
del micrond.PLUGIN_CATALOG["darkstat"]
res = dispatch("plugin.darkstat.apply", {"interface": "eth0"})
check("darkstat apply refuses when the plugin is not in the catalogue",
      res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
res = dispatch("plugin.darkstat.status", {})
check("darkstat status refuses when the plugin is not in the catalogue",
      res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved_catalog)

# ---------------------------------------------------------------------------
# Class B: lightsquid (settings rendered into a config file + a cron drop-in)
# ---------------------------------------------------------------------------

LIGHTSQUID_DIR = tempfile.mkdtemp(prefix="micron-lightsquid-")
micrond.LIGHTSQUID_CONFIG_DIR = os.path.join(LIGHTSQUID_DIR, "etc")
micrond.LIGHTSQUID_CONFIG = os.path.join(LIGHTSQUID_DIR, "etc", "lightsquid.conf")
micrond.LIGHTSQUID_CRON = os.path.join(LIGHTSQUID_DIR, "cron.d", "lightsquid")
micrond.LIGHTSQUID_STATE_DIR = os.path.join(LIGHTSQUID_DIR, "state")
LS_CONFIG = micrond.LIGHTSQUID_CONFIG
LS_CRON = micrond.LIGHTSQUID_CRON
# POSIX absolute paths, as the appliance uses them. Applying settings writes only
# the two files the plugin owns (both redirected above), so nothing is created
# on this host's filesystem.
LS_REPORT = "/var/lightsquid/report"
LS_LOGS = "/var/log/squid"

# 59. Every malformed field is refused before a single command runs or a byte is
#     written — the same "validate first" rule as darkstat.
for label, bad in (
    ("an unknown language", {"lang": "klingon"}),
    ("an unknown template", {"template": "fancy"}),
    ("an unknown bar colour", {"barcolor": "chartreuse"}),
    ("an unknown IP resolve method", {"ip2name": "magic"}),
    ("an unknown schedule", {"schedule": "5m"}),
    ("a relative log directory", {"log_dir": "logs"}),
    ("a log directory that climbs out", {"log_dir": "/var/../etc"}),
    ("a relative report directory", {"report_dir": "out"}),
    ("a skip entry that is not a host", {"skipurl": "exa mple.com"}),
    ("a multi-line skip list", {"skipurl": "a.com\nb.com"}),
):
    reset()
    res = dispatch("plugin.lightsquid.apply", bad)
    check(f"lightsquid refuses {label}", res["success"] is False, str(res.get("message")))
    check(f"  …and {label} runs nothing and writes nothing",
          calls == [] and not os.path.exists(LS_CONFIG) and not os.path.exists(LS_CRON), str(calls))

# 60. A valid apply writes the config file and the cron drop-in. The skip list is
#     de-duplicated and its dots escaped; the schedule becomes a cron line.
reset()
res = dispatch("plugin.lightsquid.apply", {
    "enabled": True,
    "lang": "fr",
    "template": "novopf",
    "barcolor": "blue",
    "ip2name": "simple",
    "skipurl": "example.com|example.net|example.com",
    "log_dir": LS_LOGS,
    "report_dir": LS_REPORT,
    "schedule": "30m",
})
check("a valid lightsquid apply succeeds", res["success"] is True,
      str(res.get("message")) + " | " + str(res.get("warnings")))
check("the config file is written", os.path.exists(LS_CONFIG), str(os.listdir(os.path.join(LIGHTSQUID_DIR, "etc")) if os.path.isdir(os.path.join(LIGHTSQUID_DIR, "etc")) else "no etc dir"))
ls_text = open(LS_CONFIG, encoding="utf-8").read() if os.path.exists(LS_CONFIG) else ""
check("the config carries the chosen report/report page settings",
      '$lang = "fr";' in ls_text and '$templatename = "novopf";' in ls_text
      and '$barcolor = "blue";' in ls_text and '$ip2name = "simple";' in ls_text, ls_text)
check("the config points at the chosen directories",
      f'$logpath = "{LS_LOGS}";' in ls_text and f'$reportpath = "{LS_REPORT}";' in ls_text, ls_text)
check("the skip list is de-duplicated and its dots escaped",
      "$skipurl = 'example\\.com|example\\.net';" in ls_text, ls_text)
check("the fixed squidlogtype is rendered", "$squidlogtype = 0;" in ls_text, ls_text)
check("the cron drop-in is written", os.path.exists(LS_CRON), str(os.listdir(os.path.join(LIGHTSQUID_DIR, "cron.d")) if os.path.isdir(os.path.join(LIGHTSQUID_DIR, "cron.d")) else "no cron.d dir"))
ls_cron = open(LS_CRON, encoding="utf-8").read() if os.path.exists(LS_CRON) else ""
check("the schedule becomes the interval cron line",
      "*/30 * * * *  root  /usr/bin/perl /usr/lib/lightsquid/lightparser.pl today" in ls_cron, ls_cron)
check("the midnight roll-over job is added too",
      "15 0 * * *  root  /usr/bin/perl /usr/lib/lightsquid/lightparser.pl yesterday" in ls_cron, ls_cron)
check("the applied settings are persisted for the status page",
      os.path.exists(os.path.join(micrond.LIGHTSQUID_STATE_DIR, "state.json"))
      and '"schedule": "30m"' in open(os.path.join(micrond.LIGHTSQUID_STATE_DIR, "state.json"), encoding="utf-8").read(),
      str(os.listdir(micrond.LIGHTSQUID_STATE_DIR)))

# 61. A schedule of "none" keeps the settings but removes the cron drop-in.
reset()
res = dispatch("plugin.lightsquid.apply", {"enabled": True, "lang": "eng", "schedule": "none",
                                            "log_dir": LS_LOGS, "report_dir": LS_REPORT})
check("an on-demand-only apply succeeds", res["success"] is True, str(res.get("message")))
check("a 'none' schedule leaves the config but removes the cron drop-in",
      os.path.exists(LS_CONFIG) and not os.path.exists(LS_CRON), str(res.get("steps")))

# 62. An empty skip list becomes the shipped 'zzz.zzz' sentinel, never an empty
#     pattern (which the reporter would read as "skip everything").
reset()
res = dispatch("plugin.lightsquid.apply", {"enabled": True, "skipurl": "",
                                            "log_dir": LS_LOGS, "report_dir": LS_REPORT, "schedule": "60m"})
check("an empty skip list is rendered as the sentinel, not an empty pattern",
      res["success"] is True and "$skipurl = 'zzz\\.zzz';" in open(LS_CONFIG, encoding="utf-8").read(),
      open(LS_CONFIG, encoding="utf-8").read() if os.path.exists(LS_CONFIG) else "missing")

# 63. Status is read-only and reflects what is on disk.
reset()
res = dispatch("plugin.lightsquid.status", {})
check("lightsquid status succeeds and reports the config as present",
      res["success"] is True and res["data"]["config_present"] is True, str(res))
check("status reports the cron drop-in and the report directory",
      res["data"]["cron_present"] is True and res["data"]["report_dir"] == LS_REPORT, str(res["data"]))
check("status is read-only", calls == [], str(calls))

# 64. Refresh runs the parser: 'today' once, 'full' over the current and the
#     rotated logs. An unknown mode is refused without running anything. The
#     refresh reads the log directory from the applied state, so the state is
#     staged with a real temp directory holding a current and two rotated logs.
reset()
refresh_logs = os.path.join(LIGHTSQUID_DIR, "squid-logs")
os.makedirs(refresh_logs, exist_ok=True)
for name in ("access.log", "access.log.0", "access.log.1"):
    open(os.path.join(refresh_logs, name), "w", encoding="utf-8").write("")
os.makedirs(micrond.LIGHTSQUID_STATE_DIR, exist_ok=True)
with open(os.path.join(micrond.LIGHTSQUID_STATE_DIR, "state.json"), "w", encoding="utf-8") as handle:
    handle.write(json.dumps({"log_dir": refresh_logs}))
res = dispatch("plugin.lightsquid.refresh", {"mode": "today"})
check("refresh 'today' parses the current log",
      res["success"] is True
      and ["/usr/bin/perl", micrond.LIGHTSQUID_PARSER, "today"] in calls, str(calls))
reset()
res = dispatch("plugin.lightsquid.refresh", {"mode": "full"})
check("refresh 'full' parses the current and every rotated log",
      res["success"] is True and res["message"] == "parsed 3 Squid log file(s)"
      and ["/usr/bin/perl", micrond.LIGHTSQUID_PARSER, "access.log.1"] in calls, str(calls))
reset()
res = dispatch("plugin.lightsquid.refresh", {"mode": "yesterday"})
check("refresh refuses an unknown mode and runs nothing",
      res["success"] is False and calls == [], str(calls))

# 65. Disabling removes the config file and the cron drop-in.
reset()
res = dispatch("plugin.lightsquid.apply", {"enabled": False})
check("disabling lightsquid succeeds", res["success"] is True, str(res.get("message")))
check("disabling removes the generated config and cron drop-in",
      not os.path.exists(LS_CONFIG) and not os.path.exists(LS_CRON), str(res.get("steps")))

# 66. The lightsquid RPCs refuse an id that is not in the catalogue.
saved_catalog = dict(micrond.PLUGIN_CATALOG)
del micrond.PLUGIN_CATALOG["lightsquid"]
for method, params in (("plugin.lightsquid.apply", {"lang": "eng"}),
                       ("plugin.lightsquid.status", {}),
                       ("plugin.lightsquid.refresh", {"mode": "today"})):
    res = dispatch(method, params)
    check(f"{method} refuses when the plugin is not in the catalogue",
          res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved_catalog)

# ---------------------------------------------------------------------------
# Class B: lldpd (settings into a systemd drop-in + /etc/lldpd.conf, plus the
# neighbour table)
# ---------------------------------------------------------------------------

LLDPD_DIR = tempfile.mkdtemp(prefix="micron-lldpd-")
micrond.LLDPD_DROPIN_DIR = os.path.join(LLDPD_DIR, "dropin")
micrond.LLDPD_DROPIN = os.path.join(LLDPD_DIR, "dropin", "micron.conf")
micrond.LLDPD_CONFIG = os.path.join(LLDPD_DIR, "etc", "lldpd.conf")
micrond.LLDPD_STATE_DIR = os.path.join(LLDPD_DIR, "state")
LD_DROPIN = micrond.LLDPD_DROPIN
LD_CONFIG = micrond.LLDPD_CONFIG

# 67. Every malformed field is refused before a single command runs or a byte is
#     written — the same "validate first" rule as darkstat/lightsquid.
for label, bad in (
    ("an interface pattern with a space", {"interfaces": "eth 0"}),
    ("an interface pattern that climbs out", {"interfaces": "eth0/../etc"}),
    ("a management pattern with a shell metacharacter", {"management_addresses": "10.0.0.1;rm -rf /"}),
    ("a multi-line system description", {"system_description": "edge\nfirewall"}),
    ("an over-long system description", {"system_description": "x" * 256}),
    ("a platform that is not one line", {"platform": "a\rb"}),
    ("a system name that is not a hostname", {"system_name": "bad host"}),
    ("a transmit interval out of range", {"tx_interval": 0}),
    ("a transmit hold out of range", {"tx_hold": 101}),
):
    reset()
    res = dispatch("plugin.lldpd.apply", bad)
    check(f"lldpd refuses {label}", res["success"] is False, str(res.get("message")))
    check(f"  …and {label} runs nothing and writes nothing",
          calls == [] and not os.path.exists(LD_DROPIN) and not os.path.exists(LD_CONFIG), str(calls))

# 68. A valid apply renders both files. The interface pattern keeps lldpd's own
#     glob/!-syntax, the free-form description is quoted as one argument, and the
#     transmit timing lands in /etc/lldpd.conf.
EXPECTED_EXEC = (
    'ExecStart=/usr/sbin/lldpd -d -I "eth*,!eth1" -c -f -x -k '
    '-S "MicronOS edge firewall" -P MicronOS -m "!*:*"'
)
reset()
res = dispatch("plugin.lldpd.apply", {
    "enabled": True,
    "interfaces": "eth*,!eth1",
    "management_addresses": "!*:*",
    "system_name": "edge.example.com",
    "system_description": "MicronOS edge firewall",
    "platform": "MicronOS",
    "tx_interval": 45,
    "tx_hold": 2,
    "receive_only": False,
    "cdp": True,
    "fdp": True,
    "sonmp": False,
    "edp": False,
    "snmp_subagent": True,
    "suppress_kernel_version": True,
})
check("a valid lldpd apply succeeds", res["success"] is True,
      str(res.get("message")) + " | " + str(res.get("warnings")))
check("the drop-in is written", os.path.exists(LD_DROPIN), str(sorted(os.listdir(LLDPD_DIR))))
ld_text = open(LD_DROPIN, encoding="utf-8").read() if os.path.exists(LD_DROPIN) else ""
check("the drop-in clears and replaces ExecStart",
      "[Service]" in ld_text and "ExecStart=\n" in ld_text and ld_text.count("ExecStart=") == 2, ld_text)
check("the daemon runs in the foreground and every chosen flag is rendered",
      EXPECTED_EXEC in ld_text, ld_text)
check("flags that were not chosen are absent",
      " -e" not in ld_text and " -s" not in ld_text and " -r" not in ld_text, ld_text)
check("the daemon config file is written", os.path.exists(LD_CONFIG), str(sorted(os.listdir(LLDPD_DIR))))
ld_conf = open(LD_CONFIG, encoding="utf-8").read() if os.path.exists(LD_CONFIG) else ""
check("the transmit timing is written to the daemon config",
      "configure lldp tx-interval 45" in ld_conf and "configure lldp tx-hold 2" in ld_conf, ld_conf)
check("the hostname override is written quoted",
      'configure system hostname "edge.example.com"' in ld_conf, ld_conf)
check("the package's own unit is reloaded, enabled and restarted",
      ["systemctl", "daemon-reload"] in calls
      and ["systemctl", "enable", "lldpd"] in calls
      and ["systemctl", "restart", "lldpd"] in calls, str(calls))
check("the applied settings are persisted for the status page",
      '"tx_interval": 45' in open(os.path.join(micrond.LLDPD_STATE_DIR, "state.json"), encoding="utf-8").read(),
      str(sorted(os.listdir(micrond.LLDPD_STATE_DIR))))

# 69. An empty interface pattern means every physical interface, so `-I` is not
#     emitted at all.
reset()
res = dispatch("plugin.lldpd.apply", {"enabled": True})
check("an empty interface pattern omits -I",
      res["success"] is True
      and "ExecStart=/usr/sbin/lldpd -d\n" in open(LD_DROPIN, encoding="utf-8").read(),
      open(LD_DROPIN, encoding="utf-8").read() if os.path.exists(LD_DROPIN) else "missing")

# 70. The neighbour table is the daemon's own lldpcli output, passed through.
reset()
behaviour[("/usr/sbin/lldpcli", "show", "neighbors")] = (0, "LLDP neighbors:\n  eth0 -> switch1 Gi0/1\n", "")
res = dispatch("plugin.lldpd.neighbours", {})
check("the neighbour table is read through lldpcli",
      res["success"] is True and ["/usr/sbin/lldpcli", "show", "neighbors"] in calls, str(calls))
check("the listing is passed through verbatim",
      res["data"]["neighbours"] == "LLDP neighbors:\n  eth0 -> switch1 Gi0/1\n", str(res["data"]))
reset()
behaviour[("/usr/sbin/lldpcli", "show", "neighbors")] = (0, "", "")
res = dispatch("plugin.lldpd.neighbours", {})
check("an empty neighbour table is a success with a warning",
      res["success"] is True and res["warnings"]
      and "no LLDP neighbours" in res["warnings"][0], str(res))
reset()
behaviour[("/usr/sbin/lldpcli", "show", "neighbors")] = (1, "", "cannot connect to lldpd")
res = dispatch("plugin.lldpd.neighbours", {})
check("a failing lldpcli is reported as an error, not empty output",
      res["success"] is False and "cannot connect" in res["warnings"][0], str(res))

# 70b. `format=json` is what the interface inventory reads: one structured row
#      per neighbour, keyed by the *local* interface, so the page never has to
#      parse the human-readable table. json0 keeps every collection an array.
LLDP0 = json.dumps({"lldp": {"interface": [
    {
        "name": "eth1", "via": "LLDP", "rid": "1", "age": "0 day, 00:00:07",
        "chassis": [{
            "id": {"type": "local", "value": "aa:bb:cc:dd:ee:ff"},
            "name": "core-sw1", "descr": "Cisco IOS Software",
            "mgmt-ip": ["10.0.0.2", "10.0.0.3"],
            "capability": [{"type": "Bridge", "enabled": True}],
        }],
        "port": [{
            "id": {"type": "local", "value": "Gi0/12"},
            "descr": "GigabitEthernet0/12", "ttl": "120",
        }],
    },
    {
        "name": "eth2", "via": "LLDP",
        "chassis": [{"id": {"type": "local", "value": "11:22:33:44:55:66"}, "name": "edge-sw2"}],
        "port": [{"id": {"type": "local", "value": "Te1/0/1"}}],
    },
]}})
reset()
behaviour[("/usr/sbin/lldpcli", "-f", "json0", "show", "neighbors")] = (0, LLDP0, "")
res = dispatch("plugin.lldpd.neighbours", {"format": "json"})
check("format=json reads the table with json0 rather than the text listing",
      res["success"] is True
      and ["/usr/sbin/lldpcli", "-f", "json0", "show", "neighbors"] in calls, str(calls))
rows = {row["interface"]: row for row in res["data"]["ports"]} if res["success"] else {}
check("each neighbour becomes one row keyed by its local interface",
      sorted(rows) == ["eth1", "eth2"], str(rows))
check("the remote system name, chassis id and description are extracted",
      rows.get("eth1", {}).get("chassis") == "core-sw1"
      and rows["eth1"]["chassis_id"] == "aa:bb:cc:dd:ee:ff"
      and rows["eth1"]["chassis_descr"] == "Cisco IOS Software", str(rows.get("eth1")))
check("the remote port, its description and the TTL are extracted",
      rows["eth1"]["port"] == "Gi0/12"
      and rows["eth1"]["port_descr"] == "GigabitEthernet0/12"
      and rows["eth1"]["ttl"] == "120", str(rows["eth1"]))
check("the first management address is taken and the age/via kept",
      rows["eth1"]["mgmt_ip"] == "10.0.0.2"
      and rows["eth1"]["via"] == "LLDP"
      and rows["eth1"]["age"] == "0 day, 00:00:07", str(rows["eth1"]))
check("a neighbour that advertises no description yields nulls, not errors",
      rows["eth2"]["chassis"] == "edge-sw2"
      and rows["eth2"]["port"] == "Te1/0/1"
      and rows["eth2"]["port_descr"] is None
      and rows["eth2"]["mgmt_ip"] is None, str(rows["eth2"]))

# 70c. A malformed or unknown format is refused before lldpcli is ever run.
reset()
res = dispatch("plugin.lldpd.neighbours", {"format": "yaml"})
check("an unknown neighbour format is refused without running lldpcli",
      res["success"] is False and "format must be" in res["message"] and calls == [],
      str(res.get("message")) + " | " + str(calls))

# 70d. The parser also tolerates plain `json` output, which collapses a one-member
#      array and re-wraps an object under its `name` — a shape someone may paste
#      in by hand. It must never raise; the fields it cannot recover stay null.
plain = json.dumps({"lldp": {"interface": [
    {"eth1": {
        "via": "LLDP", "age": "0 day, 00:00:07",
        "chassis": {"core-sw1": {
            "id": "aa:bb:cc:dd:ee:ff",
            "descr": "Cisco IOS Software",
            "mgmt-ip": "10.0.0.2",
        }},
        "port": {"id": "Gi0/12", "descr": "GigabitEthernet0/12", "ttl": "120"},
    }},
]}})
rows, error = micrond.RpcHandler._lldp_parse_neighbours(plain)
check("the plain-json shape is accepted and still names the interface",
      error is None and len(rows) == 1 and rows[0]["interface"] == "eth1", str(rows))
check("the plain-json name-wrapper is unwrapped for the chassis",
      rows and rows[0]["chassis"] == "core-sw1" and rows[0]["chassis_id"] == "aa:bb:cc:dd:ee:ff",
      str(rows))
check("a collapsed single port id is read from the bare string",
      rows and rows[0]["port"] == "Gi0/12" and rows[0]["ttl"] == "120", str(rows))

# 70e. Anything that is not the neighbour structure fails closed with a message,
#      and a well-formed but empty table is a success without neighbours.
rows, error = micrond.RpcHandler._lldp_parse_neighbours("not json at all")
check("non-JSON neighbour output is an error, not an empty success",
      rows == [] and error is not None and "not valid JSON" in error, str(error))
rows, error = micrond.RpcHandler._lldp_parse_neighbours(json.dumps(["lldp"]))
check("a JSON array instead of an object is an error",
      rows == [] and error is not None and "not a JSON object" in error, str(error))
rows, error = micrond.RpcHandler._lldp_parse_neighbours(json.dumps({"lldp": {"interface": []}}))
check("a well-formed table with no neighbours is not an error",
      rows == [] and error is None, str(rows) + " | " + str(error))
reset()
behaviour[("/usr/sbin/lldpcli", "-f", "json0", "show", "neighbors")] = (0, '{"lldp":{"interface":[]}}', "")
res = dispatch("plugin.lldpd.neighbours", {"format": "json"})
check("an empty json table is a success with a warning and no rows",
      res["success"] is True and res["data"]["ports"] == [] and res["warnings"]
      and "no LLDP neighbours" in res["warnings"][0], str(res))
reset()
behaviour[("/usr/sbin/lldpcli", "-f", "json0", "show", "neighbors")] = (0, "<not json>", "")
res = dispatch("plugin.lldpd.neighbours", {"format": "json"})
check("unparseable lldpcli output is reported as an error",
      res["success"] is False and "could not parse" in res["message"], str(res.get("message")))

# 71. Status is read-only and reports the live state; disabling removes both
#     generated files and stops the unit.
reset()
res = dispatch("plugin.lldpd.status", {})
check("lldpd status succeeds and reports the service state",
      res["success"] is True and res["data"]["service_state"] == "running", str(res))
check("status reports the drop-in and config presence",
      res["data"]["dropin_present"] is True and res["data"]["config_present"] is True, str(res["data"]))
check("status is read-only", [c for c in calls if c[:2] == ["systemctl", "restart"]] == [], str(calls))
reset()
res = dispatch("plugin.lldpd.apply", {"enabled": False})
check("disabling lldpd succeeds and stops the unit",
      res["success"] is True and ["systemctl", "stop", "lldpd"] in calls, str(calls))
check("disabling removes the drop-in and the config",
      not os.path.exists(LD_DROPIN) and not os.path.exists(LD_CONFIG), str(res.get("steps")))

# 72. The lldpd RPCs refuse an id that is not in the catalogue.
saved_catalog = dict(micrond.PLUGIN_CATALOG)
del micrond.PLUGIN_CATALOG["lldpd"]
for method, params in (("plugin.lldpd.apply", {"enabled": True}),
                       ("plugin.lldpd.status", {}),
                       ("plugin.lldpd.neighbours", {})):
    res = dispatch(method, params)
    check(f"{method} refuses when the plugin is not in the catalogue",
          res["success"] is False and "unknown plugin" in res["message"], str(res.get("message")))
micrond.PLUGIN_CATALOG.clear()
micrond.PLUGIN_CATALOG.update(saved_catalog)

print()
if failures:
    print(f"FAILED: {len(failures)} check(s): " + ", ".join(failures))
    sys.exit(1)

print("ALL MICROND PLUGIN CHECKS PASSED")

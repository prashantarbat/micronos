#!/usr/bin/env python3
"""
MicronOS micrond Phase 0 RPC verification
=========================================

Standalone harness for the `system.service` and `dnsbl.update` / `dnsbl.apply`
handlers added to close the two stubbed RPCs.

External commands are stubbed. File I/O is real, but confined to temporary
directories (the staging dir and the compiled blocklist path are patched), so
the path-validation rules are exercised against a genuine filesystem.

  python3 tests/test_micrond_phase0.py
"""
import asyncio
import importlib.util
import os
import shutil
import struct
import subprocess
import sys
import tempfile

from _support.strictdecode import strict_json_loads

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

spec = importlib.util.spec_from_file_location("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))
micrond = importlib.util.module_from_spec(spec)
spec.loader.exec_module(micrond)

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


# ---- Isolate the filesystem: staging dir and blocklist live in temp ---------
STAGE = tempfile.mkdtemp(prefix="micron-stage-")
OUTDIR = tempfile.mkdtemp(prefix="micron-out-")
BLOCKLIST = os.path.join(OUTDIR, "micron-blocklist.conf")
micrond.DNSBL_STAGE_DIR = STAGE
micrond.DNSBL_BLOCKLIST_FILE = BLOCKLIST

# The appliance stub resolver is isolated the same way: the dns.resolver.apply
# RPC writes this file (real I/O, temp path), never /etc/resolv.conf in the test.
RESOLV = os.path.join(OUTDIR, "resolv.conf")
micrond.RESOLV_CONF = RESOLV

# Dynamic DNS collection is isolated the same way: the interface path reads one
# file per dynamic link from here, one address per line. On the appliance the
# directory is /run/micron/dynamic-dns.
DYNDNS_DIR = tempfile.mkdtemp(prefix="micron-dyndns-")
micrond.DYNAMIC_DNS_DIR = DYNDNS_DIR

loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)

calls = []
behaviour = {}


def fake_run(argv):
    """Records the command and returns a per-prefix canned result."""
    calls.append(list(argv))
    for prefix, (rc, out, err) in behaviour.items():
        if list(argv[: len(prefix)]) == list(prefix):
            return subprocess.CompletedProcess(argv, rc, out, err)
    return subprocess.CompletedProcess(argv, 0, "active", "")


handler._run = fake_run


def reset():
    calls.clear()
    behaviour.clear()


def clear_stage():
    for name in os.listdir(STAGE):
        path = os.path.join(STAGE, name)
        if os.path.isfile(path):
            os.remove(path)
    if os.path.isfile(BLOCKLIST):
        os.remove(BLOCKLIST)
    if os.path.isfile(RESOLV):
        os.remove(RESOLV)


def write_artifact(name, text):
    with open(os.path.join(STAGE, name), "w", encoding="utf-8") as fh:
        fh.write(text)


def read_blocklist():
    with open(BLOCKLIST, "r", encoding="utf-8") as fh:
        return fh.read()


def read_resolv():
    with open(RESOLV, "r", encoding="utf-8") as fh:
        return fh.read()


def write_dynamic(source, link, lines):
    directory = os.path.join(DYNDNS_DIR, source)
    os.makedirs(directory, exist_ok=True)
    with open(os.path.join(directory, link + ".dns"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")


def clear_dynamic():
    if os.path.isdir(DYNDNS_DIR):
        shutil.rmtree(DYNDNS_DIR)
    os.makedirs(DYNDNS_DIR, exist_ok=True)


print("=========================================================")
print("   MicronOS micrond Phase 0 RPC Verification")
print("=========================================================\n")

# =========================================================================
# system.service
# =========================================================================
reset()
res = loop.run_until_complete(handler.rpc_system_service({"service": "openvpn", "action": "restart"}))
check("known service restart succeeds", res.get("success") is True and res.get("status") == "applied", str(res))
check("service id resolves to its systemd unit", res.get("unit") == "openvpn@server", str(res))
check("restart issues systemctl for the resolved unit",
      ["systemctl", "restart", "openvpn@server"] in calls, str(calls))
check("reports post-action state", res.get("state") == "running", str(res))

reset()
res = loop.run_until_complete(handler.rpc_system_service({"service": "evil-unit", "action": "restart"}))
check("unknown service refused", res.get("success") is False and "unknown service" in str(res.get("message")), str(res))
check("no command run for an unknown service", calls == [], str(calls))

reset()
res = loop.run_until_complete(handler.rpc_system_service({"service": "squid", "action": "reload"}))
check("unsupported action refused", res.get("success") is False and "unsupported action" in str(res.get("message")), str(res))
check("no command run for an unsupported action", calls == [], str(calls))

reset()
res = loop.run_until_complete(handler.rpc_system_service({"service": "nftables", "action": "stop"}))
check("stopping nftables is allowed but warned",
      res.get("success") is True and any("packet filter" in w for w in res.get("warnings", [])), str(res))

# Post-action state reflects a real 'inactive' reply.
reset()
behaviour[("systemctl", "is-active", "squid")] = (0, "inactive", "")
res = loop.run_until_complete(handler.rpc_system_service({"service": "squid", "action": "stop"}))
check("stopped service reports stopped", res.get("state") == "stopped", str(res))

# A failing unit action reports failure rather than success.
reset()
behaviour[("systemctl", "restart", "squid")] = (1, "", "Job for squid.service failed")
res = loop.run_until_complete(handler.rpc_system_service({"service": "squid", "action": "restart"}))
check("failed systemctl reports failure", res.get("success") is False and res.get("status") == "error", str(res))
check("failed systemctl surfaces stderr", any("failed" in w for w in res.get("warnings", [])), str(res))

# =========================================================================
# dnsbl.update — staging-dir compile
# =========================================================================
reset()
clear_stage()
write_artifact("feed_a.txt", "# comment\n\nads.example.com\n0.0.0.0 tracker.example.net # inline\nnot a domain\n")
write_artifact("feed_b.txt", "malware.example.org\n")
res = loop.run_until_complete(handler.rpc_dnsbl_update({}))
check("staged compile succeeds", res.get("success") is True and res.get("status") == "applied", str(res))
check("staged compile counts entries", res.get("entries") == 3, str(res))

body = read_blocklist()
check("blocklist written with local-zone entries",
      'local-zone: "ads.example.com" always_nxdomain' in body
      and 'local-zone: "tracker.example.net" always_nxdomain' in body
      and 'local-zone: "malware.example.org" always_nxdomain' in body, body[:300])
check("invalid feed lines dropped", "not a domain" not in body, body[:300])
check("blocklist validated before reload", ["unbound-checkconf"] in calls, str(calls))
check("blocklist reloaded", res.get("reloaded") is True, str(res))

# Artifacts with unsafe filenames are ignored even when present.
reset()
clear_stage()
write_artifact("weird name!.txt", "sneaky.example.net\n")
write_artifact("good.txt", "good.example.net\n")
res = loop.run_until_complete(handler.rpc_dnsbl_update({}))
body = read_blocklist()
check("unsafe artifact filename ignored",
      "good.example.net" in body and "sneaky.example.net" not in body, body[:300])

# =========================================================================
# connector.service — strict allowlist (threat-model finding F2)
# =========================================================================
reset()
res = loop.run_until_complete(handler.rpc_connector_service({"service": "igmpproxy", "action": "restart"}))
check("connector may control an allowlisted daemon",
      res.get("success") is True and res.get("unit") == "igmpproxy", str(res))

reset()
res = loop.run_until_complete(handler.rpc_connector_service({"service": "squid", "action": "start"}))
check("connector may control squid", res.get("success") is True, str(res))

for critical in ("nftables", "unbound", "strongswan", "wireguard", "keepalived", "frr"):
    reset()
    res = loop.run_until_complete(handler.rpc_connector_service({"service": critical, "action": "stop"}))
    check(f"connector refused {critical}",
          res.get("success") is False and "not permitted for connectors" in str(res.get("message")),
          str(res))
    check(f"no command run for connector {critical}", calls == [], str(calls))

reset()
res = loop.run_until_complete(handler.rpc_connector_service({"service": "squid", "action": "reload"}))
check("connector action is still validated",
      res.get("success") is False and "unsupported action" in str(res.get("message")), str(res))

# The admin path keeps the full whitelist — no regression from the hardening.
reset()
res = loop.run_until_complete(handler.rpc_system_service({"service": "unbound", "action": "restart"}))
check("admin path still controls critical units", res.get("success") is True, str(res))

# =========================================================================
# dnsbl.update — feed content cannot become Unbound config syntax
# =========================================================================
reset()
clear_stage()
write_artifact(
    "inject.txt",
    'safe.example.com\n'
    'evil.example.com" always_nxdomain\n'
    'good.example.com local-zone: "x" always_nxdomain\n'
    'local-zone: "injected.example.net" always_nxdomain\n',
)
res = loop.run_until_complete(handler.rpc_dnsbl_update({}))
body = read_blocklist()
check("safe entry still compiled", 'local-zone: "safe.example.com" always_nxdomain' in body, body[:300])
check("feed content cannot inject Unbound directives",
      "injected.example.net" not in body and 'always_nxdomain" always_nxdomain' not in body,
      body[:300])
check("quote/space-bearing entries dropped",
      "evil.example.com" not in body and "good.example.com" not in body, body[:300])

# =========================================================================
# dnsbl.update — inline data and overrides
# =========================================================================
reset()
clear_stage()
res = loop.run_until_complete(handler.rpc_dnsbl_apply({
    "domains": ["inline.example.com", "bad domain"],
    "overrides": {"whitelist": ["inline.example.com"], "blacklist": ["extra.example.net"]},
}))
body = read_blocklist()
check("inline data validated",
      res.get("success") is True and any("dropped invalid domain" in w for w in res.get("warnings", [])), str(res))
check("whitelist removes an entry", "inline.example.com" not in body, body[:300])
check("blacklist adds an entry", "extra.example.net" in body, body[:300])

# =========================================================================
# dnsbl.update — path validation
# =========================================================================
reset()
clear_stage()
res = loop.run_until_complete(handler.rpc_dnsbl_update({"artifact_path": "/etc/passwd"}))
check("artifact outside the staging dir refused",
      res.get("success") is False and "refusing artifact" in str(res.get("message")), str(res))
check("no blocklist written for a refused path", not os.path.isfile(BLOCKLIST), "file exists")

reset()
clear_stage()
res = loop.run_until_complete(handler.rpc_dnsbl_update({"artifact_path": os.path.join(STAGE, "missing.txt")}))
check("missing artifact refused", res.get("success") is False and "not found" in str(res.get("message")), str(res))

# =========================================================================
# dnsbl.update — empty staging is a no-op, never a wipe
# =========================================================================
reset()
clear_stage()
with open(BLOCKLIST, "w", encoding="utf-8") as fh:
    fh.write("# previous blocklist\n")
res = loop.run_until_complete(handler.rpc_dnsbl_update({}))
check("no data is a no-op", res.get("success") is True and res.get("status") == "noop", str(res))
check("existing blocklist left untouched",
      read_blocklist() == "# previous blocklist\n", read_blocklist())
check("no reload attempted on a no-op", not any(c[:1] == ["unbound-control"] for c in calls), str(calls))

# An explicit empty list IS a deliberate clear, and is applied.
reset()
clear_stage()
res = loop.run_until_complete(handler.rpc_dnsbl_update({"domains": []}))
check("explicit empty list clears the blocklist",
      res.get("success") is True and res.get("entries") == 0, str(res))

# =========================================================================
# dnsbl.update — validation failure restores the previous blocklist
# =========================================================================
reset()
clear_stage()
with open(BLOCKLIST, "w", encoding="utf-8") as fh:
    fh.write("# last known good\n")
write_artifact("bad.txt", "evil.example.com\n")
behaviour[("unbound-checkconf",)] = (1, "", "unbound-checkconf: error in micron-blocklist.conf")
res = loop.run_until_complete(handler.rpc_dnsbl_update({}))
check("checkconf failure reports failure",
      res.get("success") is False and "rejected" in str(res.get("message")), str(res))
check("previous blocklist restored on validation failure",
      read_blocklist() == "# last known good\n", read_blocklist())
check("no reload after a validation failure",
      not any(c[:1] == ["unbound-control"] for c in calls), str(calls))

# =========================================================================
# dnsbl.update — reload failure is reported, not masked
# =========================================================================
reset()
clear_stage()
write_artifact("ok.txt", "ok.example.com\n")
behaviour[("unbound-control", "reload")] = (1, "", "unbound-control: could not connect")
behaviour[("systemctl", "reload", "unbound")] = (1, "", "Unit unbound.service not found")
res = loop.run_until_complete(handler.rpc_dnsbl_update({}))
check("reload failure reports failure", res.get("success") is False and res.get("status") == "error", str(res))
check("reload failure is surfaced",
      any("reload failed" in w for w in res.get("warnings", [])), str(res))

# =========================================================================
# dns.resolver.apply — appliance stub resolver (DNS Server Override + behavior)
# =========================================================================
reset()
clear_stage()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "# MicronOS stub resolver\n# DNS Server Override: enabled\nnameserver 127.0.0.1\nnameserver 1.1.1.1\n",
    "behavior": "local-fallback",
    "override": True,
}))
check("stub resolver applied", res.get("success") is True and res.get("status") == "applied", str(res))
check("stub resolver written atomically with no temp file left behind",
      os.path.isfile(RESOLV) and not os.path.isfile(RESOLV + ".tmp"), str(os.listdir(OUTDIR)))
check("stub resolver reports the servers it applied",
      res.get("nameservers") == ["127.0.0.1", "1.1.1.1"], str(res))
check("written file matches the compiled content",
      "nameserver 1.1.1.1" in read_resolv(), read_resolv())
check("override flag is echoed back", res.get("override") is True, str(res))
check("resolver caches are flushed after a change",
      ["resolvectl", "flush-caches"] in calls, str(calls))

# A cache-flush failure degrades a warning, never a failure: resolv.conf is
# already in effect and is read per lookup.
reset()
behaviour[("resolvectl", "flush-caches")] = (1, "", "resolvectl: not found")
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "nameserver 127.0.0.1\n", "behavior": "local-only", "override": False,
}))
check("cache-flush failure still applies the config", res.get("success") is True, str(res))
check("cache-flush failure is surfaced as a warning",
      any("not flushed" in w for w in res.get("warnings", [])), str(res))

# Directives the control plane does not own are refused, and the previous file
# is left exactly as it was.
reset()
with open(RESOLV, "w", encoding="utf-8") as fh:
    fh.write("# last known good\nnameserver 127.0.0.1\n")
for bad, label in (
    ("nameserver 127.0.0.1\nsearch evil.example\n", "search directive"),
    ("nameserver 127.0.0.1\noptions ndots:2\n", "options directive"),
    ("nameserver 1.1.1.1\ninclude /etc/passwd\n", "include directive"),
    ("nameserver 999.1.1.1\n", "invalid address"),
    ("nameserver 127.0.0.1 extra\n", "address carrying a payload"),
    ("nameserver 127.0.0.1; reboot\n", "shell metacharacters"),
):
    res = loop.run_until_complete(handler.rpc_dns_resolver_apply({"config": bad}))
    check(f"refused {label}",
          res.get("success") is False and "refusing" in str(res.get("message")), str(res))
check("previous resolver file untouched after refusals",
      read_resolv() == "# last known good\nnameserver 127.0.0.1\n", read_resolv())

# An empty resolv.conf would break every lookup on the appliance.
reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({"config": "# only a comment\n"}))
check("config with no nameserver refused",
      res.get("success") is False and "no nameserver" in str(res.get("message")), str(res))

reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({"config": "   "}))
check("blank config refused", res.get("success") is False, str(res))

reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({"config": ["nameserver 1.1.1.1"]}))
check("non-string config refused", res.get("success") is False, str(res))

reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({"config": "x" * (micrond.RESOLV_MAX_BYTES + 1)}))
check("oversized config refused",
      res.get("success") is False and "exceeds" in str(res.get("message")), str(res))

reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "".join(f"nameserver 10.0.0.{i}\n" for i in range(1, 12)),
}))
check("too many nameservers refused",
      res.get("success") is False and "too many nameservers" in str(res.get("message")), str(res))

# The file cannot contradict the policy the DNS page reports: a local-first
# policy must actually name the local resolver.
reset()
if os.path.isfile(RESOLV):
    os.remove(RESOLV)
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "nameserver 1.1.1.1\n", "behavior": "local-only",
}))
check("local-only without 127.0.0.1 refused",
      res.get("success") is False and "requires nameserver 127.0.0.1" in str(res.get("message")), str(res))
check("no file written for an inconsistent policy", not os.path.isfile(RESOLV), "file exists")

reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "nameserver 1.1.1.1\nnameserver 9.9.9.9\n", "behavior": "remote-only",
}))
check("remote-only without the local resolver is accepted", res.get("success") is True, str(res))

reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "nameserver 127.0.0.1\n", "behavior": "nonsense",
}))
check("unknown behavior refused",
      res.get("success") is False and "unknown DNS resolution behavior" in str(res.get("message")), str(res))

# Duplicates are collapsed so the reported server list matches the file.
reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "nameserver 127.0.0.1\nnameserver 127.0.0.1\nnameserver 1.1.1.1\n",
    "behavior": "local-fallback",
}))
check("duplicate nameservers deduped", res.get("nameservers") == ["127.0.0.1", "1.1.1.1"], str(res))

# IPv6 addresses are valid resolvers.
reset()
res = loop.run_until_complete(handler.rpc_dns_resolver_apply({
    "config": "nameserver 127.0.0.1\nnameserver 2606:4700:4700::1111\n", "behavior": "local-fallback",
}))
check("ipv6 nameserver accepted",
      res.get("success") is True and "2606:4700:4700::1111" in res.get("nameservers", []), str(res))

# =========================================================================
# interface.dynamic_dns — the servers the dynamic links assigned
# =========================================================================
reset()
clear_dynamic()
res = loop.run_until_complete(handler.rpc_interface_dynamic_dns({}))
check("no dynamic links reports nothing",
      res.get("success") is True and res.get("sources") == [], str(res))

reset()
clear_dynamic()
write_dynamic("dhcp", "WAN", ["10.0.0.138", "10.0.0.139"])
write_dynamic("ppp", "WAN_PPP", ["100.64.0.53"])
write_dynamic("openvpn", "ovpn-client1", ["# pushed by the peer", "10.8.0.1", "not-an-ip", "10.8.0.1", ""])
res = loop.run_until_complete(handler.rpc_interface_dynamic_dns({}))
by_source = {entry["source"]: entry for entry in res.get("sources", [])}
check("dhcp link reported",
      by_source.get("dhcp", {}).get("servers") == ["10.0.0.138", "10.0.0.139"], str(res))
check("ppp link reported",
      by_source.get("ppp", {}).get("servers") == ["100.64.0.53"], str(res))
check("openvpn link skips comments/invalid lines and dedupes",
      by_source.get("openvpn", {}).get("servers") == ["10.8.0.1"], str(res))
check("interface name is the link filename",
      by_source.get("dhcp", {}).get("interface") == "WAN", str(res))
check("flat server list aggregates every source",
      res.get("servers") == ["10.0.0.138", "10.0.0.139", "100.64.0.53", "10.8.0.1"], str(res))

# Each link is capped, so a hostile DHCP option cannot flood the resolver.
reset()
clear_dynamic()
write_dynamic("dhcp", "WAN", [f"10.20.30.{i}" for i in range(1, 13)])
res = loop.run_until_complete(handler.rpc_interface_dynamic_dns({}))
check("per-link server list is capped",
      len(res["sources"][0]["servers"]) == micrond.DYNAMIC_DNS_MAX_SERVERS, str(res))

# A directory named after something that is not a dynamic link is ignored.
reset()
clear_dynamic()
write_dynamic("manual", "X", ["8.8.8.8"])
res = loop.run_until_complete(handler.rpc_interface_dynamic_dns({}))
check("unknown source directory ignored", res.get("sources") == [], str(res))

# =========================================================================
# Strict JSON on the wire — NaN / Infinity can never enter the daemon
# =========================================================================
for bad in ('{"n": NaN}', '{"n": Infinity}', '{"n": -Infinity}'):
    try:
        micrond.strict_json_loads(bad)
        rejected = False
    except ValueError:
        rejected = True
    check(f"strict_json_loads rejects {bad}", rejected)

check("strict_json_loads still accepts standard JSON",
      micrond.strict_json_loads('{"a": 1.5, "b": [true, null]}') == {"a": 1.5, "b": [True, None]})


class _FakeReader:
    """A StreamReader stand-in that yields pre-framed payloads then EOF."""

    def __init__(self, frames):
        self.chunks = []
        for frame in frames:
            self.chunks += [struct.pack("!I", len(frame)), frame]

    async def readexactly(self, n):
        if not self.chunks:
            raise asyncio.IncompleteReadError(b"", n)
        chunk = self.chunks.pop(0)
        assert len(chunk) == n, (len(chunk), n)
        return chunk


class _FakeWriter:
    """A StreamWriter stand-in that records the framed responses it was given."""

    def __init__(self):
        self.frames = []
        self._buf = b""

    def write(self, data):
        self._buf += data

    async def drain(self):
        while len(self._buf) >= 4:
            length = struct.unpack("!I", self._buf[:4])[0]
            if len(self._buf) < 4 + length:
                break
            self.frames.append(self._buf[4:4 + length])
            self._buf = self._buf[4 + length:]

    def close(self):
        pass

    async def wait_closed(self):
        pass


class _StubHandler:
    def __init__(self, result=None):
        self.result = result
        self.seen = []

    async def handle_request(self, req):
        self.seen.append(req)
        return self.result if self.result is not None else {"jsonrpc": "2.0", "id": req.get("id"), "result": {"ok": True}}


NAN_FRAME = b'{"jsonrpc": "2.0", "id": 1, "method": "system.ping", "params": {"x": NaN}}'
GOOD_FRAME = b'{"jsonrpc": "2.0", "id": 2, "method": "system.ping", "params": {}}'

writer = _FakeWriter()
stub = _StubHandler()
loop.run_until_complete(micrond.handle_client(_FakeReader([NAN_FRAME, GOOD_FRAME]), writer, stub))
first, second = strict_json_loads(writer.frames[0]), strict_json_loads(writer.frames[1])
check("a NaN request is refused with a JSON-RPC parse error",
      first.get("error", {}).get("code") == -32700, str(first))
check("the refused NaN frame never reaches a handler",
      [r.get("id") for r in stub.seen] == [2], str(stub.seen))
check("the connection stays usable after a refused frame",
      second.get("result", {}).get("ok") is True, str(second))

# A handler that somehow produces a NaN must not emit an unparsable frame.
writer = _FakeWriter()
loop.run_until_complete(micrond.handle_client(
    _FakeReader([GOOD_FRAME]), writer,
    _StubHandler({"jsonrpc": "2.0", "id": 2, "result": {"v": float("nan")}})))
nan_response = strict_json_loads(writer.frames[0])
check("a NaN response body becomes an internal error, not an invalid frame",
      nan_response.get("error", {}).get("code") == -32603, str(nan_response))

# =========================================================================
# Transactional commit / rollback (system.commit, system.rollback)
# =========================================================================
# The management plane calls system.commit after every apply, and
# system.rollback for the Web and console rollback. The snapshot reload is
# isolated to a temp file and a recording runner, so the checks drive the real
# handler logic without an `nft` binary on the host.

tx_calls = []


def tx_run(argv):
    tx_calls.append(list(argv))
    return subprocess.CompletedProcess(argv, 0, "", "")


tx_mgr = micrond.SafeTransactionManager(run=tx_run)
tx_handler = micrond.RpcHandler(tx_mgr, loop)
SNAPSHOT = os.path.join(OUTDIR, "nftables.conf.bak")
micrond.NFTABLES_SNAPSHOT = SNAPSHOT

tx_mgr.start_rollback_timer(loop)
check("a rollback watchdog is armed before the commit", tx_mgr.pending_timer is not None)
res = loop.run_until_complete(tx_handler.rpc_system_commit({}))
check("system.commit disarms the rollback watchdog", tx_mgr.pending_timer is None, str(res))
check("system.commit reports success",
      res.get("success") is True and res.get("status") == "ok", str(res))

# A snapshot exists: the rollback reloads it and says so.
with open(SNAPSHOT, "w") as f:
    f.write("table inet filter { }\n")
tx_calls.clear()
tx_mgr.start_rollback_timer(loop)
res = loop.run_until_complete(tx_handler.rpc_system_rollback({}))
check("system.rollback reloads the captured snapshot",
      tx_calls == [["nft", "-f", SNAPSHOT]], str(tx_calls))
check("system.rollback reports the snapshot was restored", res.get("restored") is True, str(res))
check("system.rollback disarms the watchdog too", tx_mgr.pending_timer is None)

# Nothing captured yet: a reported no-op, never a silent success.
os.remove(SNAPSHOT)
tx_calls.clear()
res = loop.run_until_complete(tx_handler.rpc_system_rollback({}))
check("system.rollback without a snapshot reports restored=false",
      res.get("restored") is False and res.get("success") is True, str(res))
check("system.rollback runs no command when there is no snapshot", tx_calls == [], str(tx_calls))

# A snapshot that cannot be reloaded must surface as an error, so a rollback
# that did not happen can never look like one that did.
with open(SNAPSHOT, "w") as f:
    f.write("table inet filter { }\n")

def tx_run_failing(argv):
    raise OSError("nft: not found")


tx_mgr.run = tx_run_failing
res = loop.run_until_complete(tx_handler.handle_request(
    {"jsonrpc": "2.0", "id": 9, "method": "system.rollback", "params": {}}))
check("a failing snapshot reload is reported as an error, not a success",
      "error" in res and res.get("result") is None, str(res))
tx_mgr.run = tx_run
os.remove(SNAPSHOT)

# =========================================================================
# Dispatch through handle_request (method -> rpc_<name>)
# =========================================================================
reset()
clear_stage()
write_artifact("d.txt", "dispatch.example.com\n")
dispatch_params = {
    "system.service": {"service": "squid", "action": "start"},
    "connector.service": {"service": "squid", "action": "start"},
}
dispatch_params["dns.resolver.apply"] = {
    "config": "nameserver 127.0.0.1\n", "behavior": "local-only", "override": False,
}
for method in ("system.service", "connector.service", "dnsbl.update", "dnsbl.apply", "dns.resolver.apply", "interface.dynamic_dns", "system.commit", "system.rollback"):
    resp = loop.run_until_complete(
        handler.handle_request({"jsonrpc": "2.0", "id": 1, "method": method,
                                "params": dispatch_params.get(method, {})})
    )
    check(f"dispatch {method}", "result" in resp, str(resp)[:200])

print()
if failures:
    print(f"{len(failures)} FAILURE(S): {failures}")
    sys.exit(1)
print("ALL MICROND PHASE 0 CHECKS PASSED")

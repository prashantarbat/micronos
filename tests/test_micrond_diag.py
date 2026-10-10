#!/usr/bin/env python3
"""
MicronOS micrond Diagnostics (ping + traceroute) RPC verification
=================================================================

Standalone harness for the `diag.ping` and `diag.traceroute` handlers — the
pfSense field sets the Diagnostics → Ping and Diagnostics → Traceroute pages
submit. Ping: a hostname, an IP protocol, an optional source address, and the
maximum number of pings and seconds between them. Traceroute: a hostname, an IP
protocol, a source address, the maximum number of hops, and the Reverse Address
Lookup and Use ICMP switches.

`subprocess` is stubbed, so this runs anywhere Python 3 does without a `ping`
or `traceroute` binary. What it pins is the enforcement surface, because each
handler builds its own command line: the host must be a hostname or an IP
literal (never a flag), the source must be an address of the chosen family, the
counts are bounded, and a refused request runs nothing at all.

  python3 tests/test_micrond_diag.py
"""
import asyncio
import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile
import time

# Handlers return the same box-drawing separators the pages render, so the
# transcript is written as UTF-8 rather than whatever the console defaults to.
try:
    sys.stdout.reconfigure(encoding="utf-8")
except (AttributeError, OSError):
    pass

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

# argv tuple -> (returncode, stdout, stderr). Anything not listed answers
# success with a canned line.
answers = {}


def fake_run(argv):
    calls.append(list(argv))
    rc, out, err = answers.get(tuple(argv), (0, "canned output\n", ""))
    return subprocess.CompletedProcess(argv, rc, out, err)


handler._run = fake_run


def ping(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_ping(params))


def traceroute(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_traceroute(params))


print("=========================================================")
print("   MicronOS micrond Diagnostics RPC Verification")
print("=========================================================\n")
print("--- ICMP ping ---")

# ---- 1. The default field set: IPv4, three pings, one second apart ---------
res = ping({"host": "1.1.1.1"})
check("a default ping succeeds", res.get("success") is not False and res.get("returncode") == 0, str(res))
check("the default argv is ping -c 3 -i 1 -W 2 <host>",
      calls == [["ping", "-c", "3", "-i", "1", "-W", "2", "1.1.1.1"]], str(calls))
check("the daemon output is passed through",
      res.get("output") == "canned output\n", str(res.get("output")))
check("the command actually run is reported back",
      res.get("cmd") == "ping -c 3 -i 1 -W 2 1.1.1.1", str(res.get("cmd")))

# An empty count/wait is what a form posts for an untouched select, so it means
# "take the default" rather than "refuse me".
res = ping({"host": "1.1.1.1", "count": "", "wait": ""})
check("an empty count and wait take the defaults",
      calls == [["ping", "-c", "3", "-i", "1", "-W", "2", "1.1.1.1"]], str(calls))

# ---- 2. A hostname is accepted, and every count in range ------------------
res = ping({"host": "example.com", "count": 1, "wait": 10})
check("a hostname is accepted", res.get("success") is not False, str(res))
check("the smallest and largest counts are accepted",
      calls == [["ping", "-c", "1", "-i", "10", "-W", "2", "example.com"]], str(calls))

for n in range(1, micrond.DIAG_PING_MAX_COUNT + 1):
    res = ping({"host": "1.1.1.1", "count": n})
    if calls != [["ping", "-c", str(n), "-i", "1", "-W", "2", "1.1.1.1"]]:
        check(f"every count up to {micrond.DIAG_PING_MAX_COUNT} is passed through", False, str(calls))
        break
else:
    check(f"every count up to {micrond.DIAG_PING_MAX_COUNT} is passed through", True)

# ---- 3. IPv6 selects ping6 ------------------------------------------------
res = ping({"host": "2001:db8::5", "ipproto": "ipv6", "count": 2, "wait": 2})
check("IPv6 uses ping6",
      calls == [["ping6", "-c", "2", "-i", "2", "-W", "2", "2001:db8::5"]], str(calls))

# A scoped link-local keeps its scope: `%eth0` is part of the target, not a flag.
res = ping({"host": "fe80::1%eth0", "ipproto": "ipv6"})
check("a scoped link-local target keeps its zone",
      calls == [["ping6", "-c", "3", "-i", "1", "-W", "2", "fe80::1%eth0"]], str(calls))

# ---- 4. A source address becomes -S, and only in its own family ------------
res = ping({"host": "10.0.100.51", "sourceip": "192.168.1.1", "count": 3})
check("an IPv4 source address is passed as -S",
      calls == [["ping", "-S", "192.168.1.1", "-c", "3", "-i", "1", "-W", "2", "10.0.100.51"]], str(calls))

res = ping({"host": "2001:db8::5", "ipproto": "ipv6", "sourceip": "2001:db8::2"})
check("an IPv6 source address is passed as -S",
      calls == [["ping6", "-S", "2001:db8::2", "-c", "3", "-i", "1", "-W", "2", "2001:db8::5"]], str(calls))

res = ping({"host": "1.1.1.1"})
check("with no source address, no -S is emitted",
      calls == [["ping", "-c", "3", "-i", "1", "-W", "2", "1.1.1.1"]], str(calls))

# ---- 5. Every malformed field is refused before anything runs --------------
# A host that reads as an option is the one that mattered: the old handler put
# it straight into the argv, where `ping` would have parsed it as a flag.
for label, params in (
    ("an option-looking host", {"host": "-f"}),
    ("a host with a shell metacharacter", {"host": "1.1.1.1; rm -rf /"}),
    ("a missing host", {"host": ""}),
    ("a host that is not a name or address", {"host": "not a host!"}),
    ("an empty host string", {"host": "   "}),
    ("an unknown protocol", {"host": "1.1.1.1", "ipproto": "sctp"}),
    ("an IPv6 host under IPv4", {"host": "fe80::1", "ipproto": "ipv4"}),
    ("an IPv4 host under IPv6", {"host": "1.1.1.1", "ipproto": "ipv6"}),
    ("a count of zero", {"host": "1.1.1.1", "count": 0}),
    ("a count above the maximum", {"host": "1.1.1.1", "count": micrond.DIAG_PING_MAX_COUNT + 1}),
    ("a negative count", {"host": "1.1.1.1", "count": -5}),
    ("a non-integer count", {"host": "1.1.1.1", "count": "three"}),
    ("a wait of zero", {"host": "1.1.1.1", "wait": 0}),
    ("a wait above the maximum", {"host": "1.1.1.1", "wait": micrond.DIAG_PING_MAX_WAIT + 1}),
    ("a source address that is not an IP", {"host": "1.1.1.1", "sourceip": "lan0"}),
    ("an option-looking source address", {"host": "1.1.1.1", "sourceip": "-f"}),
    ("an IPv6 source under IPv4", {"host": "1.1.1.1", "sourceip": "2001:db8::1"}),
    ("an IPv4 source under IPv6", {"host": "2001:db8::1", "ipproto": "ipv6", "sourceip": "192.168.1.1"}),
):
    res = ping(params)
    check(f"ping refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} runs nothing", calls == [], str(calls))

# The refusal names what to fix, so the page can show it verbatim.
res = ping({"host": "1.1.1.1", "ipproto": "ipv6"})
check("the family mismatch message matches pfSense's wording",
      res.get("message") == "When using IPv6, the target host must be an IPv6 address or hostname",
      str(res.get("message")))
res = ping({"host": "-f"})
check("the bad-host message matches pfSense's wording",
      res.get("message") == "Hostname must be a valid hostname or IP address", str(res.get("message")))

# ---- 6. A failing ping with no output at all still says something ----------
answers[("ping", "-c", "3", "-i", "1", "-W", "2", "8.8.8.8")] = (1, "", "")
res = ping({"host": "8.8.8.8"})
check("a silent failure is reported rather than left blank",
      res.get("output", "").strip() != "" and "did not respond" in res["output"], str(res))
check("the failing return code is reported", res.get("returncode") == 1, str(res.get("returncode")))

answers[("ping", "-c", "3", "-i", "1", "-W", "2", "10.0.0.9")] = (1, "", "ping: connect: Network is unreachable\n")
res = ping({"host": "10.0.0.9"})
check("stderr from ping is kept, not replaced",
      "Network is unreachable" in res.get("output", ""), str(res.get("output")))

# ---- 7. The older `target` field name still works --------------------------
res = ping({"target": "1.1.1.1", "count": 2})
check("the legacy `target` alias is accepted",
      calls == [["ping", "-c", "2", "-i", "1", "-W", "2", "1.1.1.1"]], str(calls))
check("an explicit host wins over the alias",
      ping({"host": "1.1.1.1", "target": "9.9.9.9"}).get("success") is not False
      and calls[-1][-1] == "1.1.1.1", str(calls))

# ---- 8. The bounds the harness asserts against are the module's own -------
check("the count and wait ceilings are 10",
      micrond.DIAG_PING_MAX_COUNT == 10 and micrond.DIAG_PING_MAX_WAIT == 10,
      f"{micrond.DIAG_PING_MAX_COUNT}/{micrond.DIAG_PING_MAX_WAIT}")
check("the binaries are ping and ping6",
      micrond.PING_BIN == "ping" and micrond.PING6_BIN == "ping6",
      f"{micrond.PING_BIN}/{micrond.PING6_BIN}")

print("\n--- Traceroute ---")

# ---- 9. The default field set: IPv4, no reverse lookup, pfSense's 18 hops ---
res = traceroute({"host": "8.8.8.8"})
check("a default trace succeeds", res.get("success") is not False and res.get("returncode") == 0, str(res))
check("the default argv is traceroute -n -w 2 -m 18 <host>",
      calls == [["traceroute", "-n", "-w", "2", "-m", "18", "8.8.8.8"]], str(calls))
check("the command actually run is reported back",
      res.get("cmd") == "traceroute -n -w 2 -m 18 8.8.8.8", str(res.get("cmd")))

# ---- 10. The Reverse Address Lookup switch is what drops -n ---------------
# `-n` is the flag that SUPPRESSES the PTR lookups, so it must be present
# exactly when the box is clear — inverting this is a silent behaviour swap.
res = traceroute({"host": "pfsense.org", "resolve": True})
check("checking Reverse Address Lookup drops -n",
      calls == [["traceroute", "-w", "2", "-m", "18", "pfsense.org"]], str(calls))

res = traceroute({"host": "pfsense.org", "resolve": False})
check("clearing it puts -n back",
      calls == [["traceroute", "-n", "-w", "2", "-m", "18", "pfsense.org"]], str(calls))

# ---- 11. The Use ICMP switch adds -I --------------------------------------
res = traceroute({"host": "8.8.8.8", "useicmp": True})
check("Use ICMP adds -I",
      calls == [["traceroute", "-n", "-w", "2", "-I", "-m", "18", "8.8.8.8"]], str(calls))

res = traceroute({"host": "8.8.8.8", "resolve": "yes", "useicmp": "yes"})
check("both switches together",
      calls == [["traceroute", "-w", "2", "-I", "-m", "18", "8.8.8.8"]], str(calls))

# A checkbox posts its value when checked and nothing when not; every other
# spelling — including the string "false" — must mean off.
for label, value in (("an empty string", ""), ("the string 0", "0"), ("the string false", "false"),
                     ("not posted at all", None)):
    params = {"host": "8.8.8.8", "resolve": value, "useicmp": value}
    res = traceroute(params)
    check(f"{label} means the switches are off",
          "-n" in calls[0] and "-I" not in calls[0], str(calls))

for label, value in (("yes", "yes"), ("1", 1), ("on", "on"), ("true", True)):
    params = {"host": "8.8.8.8", "resolve": value, "useicmp": value}
    res = traceroute(params)
    check(f"the truthy spelling {label!r} turns both switches on",
          "-n" not in calls[0] and "-I" in calls[0], str(calls))

# ---- 12. IPv6 selects traceroute6 -----------------------------------------
res = traceroute({"host": "2606:4700::1111", "ipproto": "ipv6"})
check("IPv6 uses traceroute6",
      calls == [["traceroute6", "-n", "-w", "2", "-m", "18", "2606:4700::1111"]], str(calls))

# On FreeBSD pfSense swaps in `-l` here; Linux's traceroute6 has no such flag,
# so the reverse lookup is simply left on by omitting `-n`.
res = traceroute({"host": "2606:4700::1111", "ipproto": "ipv6", "resolve": True})
check("IPv6 with reverse lookup emits no flag an unknown to traceroute6",
      calls == [["traceroute6", "-w", "2", "-m", "18", "2606:4700::1111"]], str(calls))
check("  …and nothing FreeBSD-only such as -l",
      "-l" not in calls[0], str(calls))

# ---- 13. Every hop count in range, and the source address ----------------
for n in (1, 2, 18, micrond.DIAG_TRACEROUTE_MAX_TTL):
    res = traceroute({"host": "8.8.8.8", "ttl": n})
    if calls != [["traceroute", "-n", "-w", "2", "-m", str(n), "8.8.8.8"]]:
        check(f"a hop count of {n} is passed through", False, str(calls))
        break
else:
    check("every hop count in range is passed through", True)

for label, ttl in (("missing", None), ("empty", "")):
    params = {"host": "8.8.8.8"}
    if ttl is not None:
        params["ttl"] = ttl
    res = traceroute(params)
    check(f"a {label} hop count takes pfSense's default of 18",
          res.get("success") is not False
          and calls == [["traceroute", "-n", "-w", "2", "-m", "18", "8.8.8.8"]],
          str(calls))

res = traceroute({"host": "8.8.8.8", "sourceip": "192.168.1.1"})
check("a source address is passed as -s",
      calls == [["traceroute", "-n", "-s", "192.168.1.1", "-w", "2", "-m", "18", "8.8.8.8"]], str(calls))

res = traceroute({"host": "8.8.8.8"})
check("with no source address, no -s is emitted",
      calls == [["traceroute", "-n", "-w", "2", "-m", "18", "8.8.8.8"]], str(calls))

# ---- 14. Every malformed field is refused before anything runs -------------
for label, params in (
    ("an option-looking host", {"host": "-f"}),
    ("a host with a shell metacharacter", {"host": "1.1.1.1; rm -rf /"}),
    ("a missing host", {"host": ""}),
    ("a host that is not a name or address", {"host": "not a host!"}),
    ("an unknown protocol", {"host": "1.1.1.1", "ipproto": "sctp"}),
    ("an IPv6 host under IPv4", {"host": "2606:4700::1111", "ipproto": "ipv4"}),
    ("an IPv4 host under IPv6", {"host": "1.1.1.1", "ipproto": "ipv6"}),
    ("a hop count of zero", {"host": "1.1.1.1", "ttl": 0}),
    ("a negative hop count", {"host": "1.1.1.1", "ttl": -3}),
    ("a hop count above the maximum",
     {"host": "1.1.1.1", "ttl": micrond.DIAG_TRACEROUTE_MAX_TTL + 1}),
    ("a non-integer hop count", {"host": "1.1.1.1", "ttl": "lots"}),
    ("a hop count that reads as an option", {"host": "1.1.1.1", "ttl": "-q"}),
    ("a source address that is not an IP", {"host": "1.1.1.1", "sourceip": "lan0"}),
    ("an option-looking source address", {"host": "1.1.1.1", "sourceip": "-f"}),
    ("an IPv6 source under IPv4", {"host": "1.1.1.1", "sourceip": "2001:db8::1"}),
    ("an IPv4 source under IPv6", {"host": "2606:4700::1111", "ipproto": "ipv6", "sourceip": "192.168.1.1"}),
):
    res = traceroute(params)
    check(f"traceroute refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} runs nothing", calls == [], str(calls))

res = traceroute({"host": "1.1.1.1", "ttl": 0})
check("the hop-count refusal matches pfSense's wording",
      res.get("message") == "Maximum number of hops must be between 1 and 64", str(res.get("message")))

# ---- 15. A failing trace with no output at all still says something --------
answers[("traceroute", "-n", "-w", "2", "-m", "18", "10.0.0.9")] = (1, "", "")
res = traceroute({"host": "10.0.0.9"})
check("a silent trace failure is reported rather than left blank",
      "did not respond" in res.get("output", ""), str(res.get("output")))
check("the failing return code is reported", res.get("returncode") == 1, str(res.get("returncode")))

answers[("traceroute", "-n", "-w", "2", "-m", "18", "10.0.0.8")] = \
    (1, "", "traceroute: sendto: Network is unreachable\n")
res = traceroute({"host": "10.0.0.8"})
check("stderr from traceroute is kept, not replaced",
      "Network is unreachable" in res.get("output", ""), str(res.get("output")))

# ---- 16. The older `target` field name still works -------------------------
res = traceroute({"target": "8.8.8.8", "ttl": 5})
check("the legacy `target` alias is accepted",
      calls == [["traceroute", "-n", "-w", "2", "-m", "5", "8.8.8.8"]], str(calls))
check("an explicit host wins over the alias",
      traceroute({"host": "1.1.1.1", "target": "9.9.9.9"}).get("success") is not False
      and calls[-1][-1] == "1.1.1.1", str(calls))

check("the hop ceiling and default are 64 and 18",
      micrond.DIAG_TRACEROUTE_MAX_TTL == 64 and micrond.DIAG_TRACEROUTE_DEFAULT_TTL == 18,
      f"{micrond.DIAG_TRACEROUTE_MAX_TTL}/{micrond.DIAG_TRACEROUTE_DEFAULT_TTL}")
check("the binaries are traceroute and traceroute6",
      micrond.TRACEROUTE_BIN == "traceroute" and micrond.TRACEROUTE6_BIN == "traceroute6",
      f"{micrond.TRACEROUTE_BIN}/{micrond.TRACEROUTE6_BIN}")

# ===========================================================================
# The other Diagnostics tools: DNS Lookup, Packet Capture, Route Lookup, the
# Firewall Logs stream, the Connection States table and the Kernel Routing and
# NDP tables. Each builds its own command line, so what these pin is the
# enforcement surface — and that a refused field runs nothing at all.
# ===========================================================================
print()
print("--- DNS lookup ---")


def dns_lookup(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_dns_lookup(params))


res = dns_lookup({"name": "cloudflare.com"})
check("a default DNS lookup succeeds", res.get("success") is not False, str(res))
check("the default query runs dig +time=3 +tries=2 <name> A",
      calls == [["dig", "+time=3", "+tries=2", "cloudflare.com", "A"]], str(calls))
check("the daemon output is passed through",
      res.get("output") == "canned output\n", str(res.get("output")))

res = dns_lookup({"name": "cloudflare.com", "type": "mx", "server": "1.1.1.1"})
check("the record type is upper-cased and the server becomes @<addr>",
      calls == [["dig", "+time=3", "+tries=2", "@1.1.1.1", "cloudflare.com", "MX"]], str(calls))

for label, params in (
    ("a missing name", {"name": ""}),
    ("a bare-option name", {"name": "-f"}),
    ("a name with a space", {"name": "not a host"}),
    ("an unknown record type", {"name": "cloudflare.com", "type": "LOC"}),
    ("a server that is not an IP", {"name": "cloudflare.com", "server": "-f"}),
    ("a server hostname", {"name": "cloudflare.com", "server": "dns.example.com"}),
):
    res = dns_lookup(params)
    check(f"DNS lookup refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} runs nothing", calls == [], str(calls))

print()
print("--- Packet capture ---")


def tcpdump(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_tcpdump(params))


res = tcpdump({"interface": "eth0", "filter": "icmp", "count": 5})
check("a packet capture with a filter succeeds", res.get("success") is not False, str(res))
check("the capture runs tcpdump -i <iface> -n -c <count> <filter>",
      calls == [["tcpdump", "-i", "eth0", "-n", "-c", "5", "icmp"]], str(calls))

tcpdump({"interface": "eth1", "count": 1})
check("an empty filter is omitted, not passed as an empty argv element",
      calls == [["tcpdump", "-i", "eth1", "-n", "-c", "1"]], str(calls))

for label, params in (
    ("an interface that reads as an option", {"interface": "-i"}),
    ("a malformed interface", {"interface": "eth0;rm -rf /"}),
    ("an over-long interface", {"interface": "a" * 20}),
    ("a packet count of zero", {"interface": "eth0", "count": 0}),
    ("a packet count above the cap", {"interface": "eth0", "count": micrond.DIAG_CAPTURE_MAX + 1}),
    ("a non-integer packet count", {"interface": "eth0", "count": "lots"}),
    ("a filter with a newline", {"interface": "eth0", "filter": "icmp\nsh"}),
):
    res = tcpdump(params)
    check(f"the capture refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} runs nothing", calls == [], str(calls))

check("the capture cap is 50", micrond.DIAG_CAPTURE_MAX == 50, str(micrond.DIAG_CAPTURE_MAX))

print()
print("--- Route lookup ---")


def route_lookup(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_route_lookup(params))


res = route_lookup({"target": "8.8.8.8"})
check("a route lookup runs ip route get <target>",
      calls == [["ip", "route", "get", "8.8.8.8"]], str(calls))
check("the route output is passed through",
      res.get("output") == "canned output\n", str(res.get("output")))

answers[("ip", "route", "get", "10.9.9.9")] = (2, "", "RTNETLINK answers: Network is unreachable\n")
res = route_lookup({"target": "10.9.9.9"})
check("a route failure keeps its stderr",
      "Network is unreachable" in res.get("output", ""), str(res.get("output")))

for label, params in (
    ("a missing target", {"target": ""}),
    ("a bare-option target", {"target": "-f"}),
):
    res = route_lookup(params)
    check(f"the route lookup refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} runs nothing", calls == [], str(calls))

print()
print("--- Kernel routing table ---")


def routing_table():
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_routing_table({}))


route_sample = (
    "default via 192.168.1.1 dev eth0 proto dhcp metric 100\n"
    "10.8.0.0/24 via 10.8.0.2 dev tun0 proto static metric 50\n"
    "192.168.1.0/24 dev eth1 proto kernel scope link src 192.168.1.1 metric 0\n"
)
answers[("ip", "-4", "route", "show")] = (0, route_sample, "")
res = routing_table()
check("the routing table runs ip -4 route show",
      calls == [["ip", "-4", "route", "show"]], str(calls))
routes = res.get("routes", [])
check("a default route becomes 0.0.0.0/0 with its gateway, device and metric",
      routes and routes[0]["destination"] == "0.0.0.0/0" and routes[0]["gateway"] == "192.168.1.1"
      and routes[0]["interface"] == "eth0" and routes[0]["metric"] == 100 and routes[0]["flags"] == "UG",
      str(routes[:1]))
check("a direct route is flagged U with no gateway",
      len(routes) == 3 and routes[2]["gateway"] == "direct" and routes[2]["flags"] == "U", str(routes))

print()
print("--- IPv6 NDP table ---")


def ndp_table():
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_ndp_table({}))


neigh_sample = (
    "fe80::1 dev eth0 lladdr e0:63:da:89:12:34 REACHABLE\n"
    "2001:db8::5 dev eth1 lladdr aa:bb:cc:dd:ee:ff STALE\n"
)
answers[("ip", "-6", "neigh", "show")] = (0, neigh_sample, "")
res = ndp_table()
check("the NDP table runs ip -6 neigh show",
      calls == [["ip", "-6", "neigh", "show"]], str(calls))
rows = res.get("neighbors", [])
check("a neighbour row carries its address, MAC, device and state",
      len(rows) == 2 and rows[1] == {"ip": "2001:db8::5", "mac": "aa:bb:cc:dd:ee:ff",
                                     "interface": "eth1", "state": "STALE"}, str(rows))

print()
print("--- Connection states ---")


def conntrack(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_conntrack_states(params))


conntrack_sample = (
    "conntrack v1.4.6 (conntrack-tools): 4 flow entries have been shown.\n"
    "tcp      6 431998 ESTABLISHED src=192.168.1.101 dst=104.21.50.182 sport=54102 dport=443 "
    "packets=381 bytes=148902 src=104.21.50.182 dst=192.168.1.101 sport=443 dport=54102 "
    "packets=312 bytes=120000 [ASSURED] mark=0 use=1\n"
    "udp      2 28 src=192.168.1.101 dst=1.1.1.1 sport=58910 dport=853 packets=42 bytes=6100 "
    "[UNREPLIED] mark=0 use=1\n"
    # A flow the appliance SNATed: the reply direction carries the WAN address.
    "tcp      6 120 ESTABLISHED src=192.168.1.101 dst=1.2.3.4 sport=5000 dport=443 "
    "packets=5 bytes=500 src=203.0.113.10 dst=192.168.1.101 sport=443 dport=5000 "
    "packets=4 bytes=400 [ASSURED] mark=0 use=1\n"
    # A flow of the appliance's own (a resolver query from loopback).
    "udp      2 30 src=127.0.0.1 dst=1.1.1.1 sport=40000 dport=53 packets=2 bytes=140 "
    "[UNREPLIED] mark=0 use=1\n"
)
# The appliance's own addresses: what the Interface column is resolved from.
iface_sample = (
    "1: lo    inet 127.0.0.1/8 scope host lo\\       valid_lft forever preferred_lft forever\n"
    "2: eth1    inet 192.168.1.1/24 brd 192.168.1.255 scope global eth1\\       "
    "valid_lft forever preferred_lft forever\n"
    "3: eth0    inet 203.0.113.10/24 brd 203.0.113.255 scope global eth0\\       "
    "valid_lft forever preferred_lft forever\n"
)
answers[("conntrack", "-L", "-o", "extended")] = (0, conntrack_sample, "")
answers[("ip", "-o", "addr", "show")] = (0, iface_sample, "")
res = conntrack({"limit": 100})
check("the states table reads conntrack and this box's interface addresses",
      calls == [["conntrack", "-L", "-o", "extended"], ["ip", "-o", "addr", "show"]], str(calls))
states = res.get("states", [])
check("the conntrack banner is not mistaken for a state", len(states) == 4, str(states))
check("the original direction is kept",
      states and states[0]["src"] == "192.168.1.101" and states[0]["dst"] == "104.21.50.182"
      and states[0]["sport"] == 54102 and states[0]["dport"] == 443, str(states[:1]))
check("the state token and counters are read",
      states[0]["state"] == "ESTABLISHED" and states[0]["packets"] == 381
      and states[0]["bytes"] == 148902, str(states[:1]))
check("a line with no state token is reported as NONE",
      states[1]["state"] == "NONE" and states[1]["proto"] == "udp", str(states[1:]))
check("a flow with no address of ours is left without an interface, not guessed",
      states[0]["iface"] == "" and states[1]["iface"] == "", str(states[:2]))
check("a flow the appliance SNATed is attributed to the interface it leaves by",
      states[2]["iface"] == "eth0", str(states[2]))
check("a flow that is the appliance's own is attributed to its interface",
      states[3]["iface"] == "lo", str(states[3]))

res = conntrack({"limit": "lots"})
check("a non-integer state limit is refused", res.get("success") is False, str(res.get("message")))
check("  …and a refused state limit reads nothing", calls == [], str(calls))

res = conntrack({"limit": 1})
check("the state limit caps how many rows are returned",
      len(res.get("states", [])) == 1, str(res.get("states")))

print()
print("--- Firewall logs ---")


def firewall_logs(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_firewall_logs(params))


log_lines = [
    "Oct  8 12:00:00 micron kernel: MICRON-FW: IN=eth0 OUT= MAC=00:11 SRC=198.51.100.9 "
    "DST=203.0.113.10 LEN=60 PROTO=TCP SPT=4001 DPT=22 SYN\n",
    "Oct  8 12:00:01 micron kernel: MICRON-DOS-DROP: IN=eth0 OUT= SRC=185.220.101.5 "
    "DST=203.0.113.10 PROTO=UDP SPT=53 DPT=5353\n",
    "Oct  8 12:00:02 micron kernel: something else entirely\n",
]
events = micrond.RpcHandler._diag_parse_firewall_log(log_lines, 20)
check("only the firewall-prefixed lines become events", len(events) == 2, str(events))
check("the newest line is reported first", events[0]["rule"] == "MICRON-DOS-DROP", str(events[:1]))
check("a drop line is classified as a block",
      events[0]["action"] == "block" and events[0]["src"] == "185.220.101.5"
      and events[0]["dport"] == 5353, str(events[:1]))
check("a generic log line keeps its packet fields, time and TCP flags",
      events[1]["proto"] == "tcp" and events[1]["src"] == "198.51.100.9"
      and events[1]["sport"] == 4001 and events[1]["time"] == "12:00:00"
      and events[1]["flags"] == "SYN", str(events[1:]))

# With no readable logfile the kernel journal is the fallback.
answers[("journalctl", "-k", "-n", "200", "--no-pager")] = (0, "".join(log_lines), "")
res = firewall_logs({"limit": 20})
check("with no logfile the events come from the kernel journal",
      calls == [["journalctl", "-k", "-n", "200", "--no-pager"]], str(calls))
check("the journal lines are parsed into events",
      len(res.get("events", [])) == 2, str(res.get("events")))

# A readable file wins over the journal.
with tempfile.NamedTemporaryFile("w", suffix=".log", delete=False, encoding="utf-8") as handle:
    handle.write(log_lines[0])
    handle.write(log_lines[1])
    log_path = handle.name
try:
    saved_log_files = micrond.FIREWALL_LOG_FILES
    micrond.FIREWALL_LOG_FILES = (log_path,)
    res = firewall_logs({"limit": 20})
    check("a readable logfile is preferred over the journal",
          len(res.get("events", [])) == 2, str(res.get("events")))
    check("  …and no journal command runs when a file exists", calls == [], str(calls))
finally:
    micrond.FIREWALL_LOG_FILES = saved_log_files
    os.unlink(log_path)

res = firewall_logs({"limit": "lots"})
check("a non-integer log limit is refused", res.get("success") is False, str(res.get("message")))
check("  …and a refused log limit reads nothing", calls == [], str(calls))

check("the diagnostics binaries are the expected ones",
      micrond.DIG_BIN == "dig" and micrond.TCPDUMP_BIN == "tcpdump"
      and micrond.IP_BIN == "ip" and micrond.CONNTRACK_BIN == "conntrack",
      f"{micrond.DIG_BIN}/{micrond.TCPDUMP_BIN}/{micrond.IP_BIN}/{micrond.CONNTRACK_BIN}")

# ===========================================================================
# Port / socket probe: a real connect on an appliance, stubbed here so no socket
# is opened and no network is touched.
# ===========================================================================
print()
print("--- Port / socket probe ---")

probe_calls = []
probe_result = []


def fake_probe(host, port, proto, timeout):
    probe_calls.append((host, port, proto, timeout))
    return probe_result[0] if probe_result else ("open", "")


handler._diag_socket_probe = fake_probe


def port_probe(params):
    calls.clear()
    probe_calls.clear()
    return loop.run_until_complete(handler.rpc_diag_port_probe(params))


res = port_probe({"host": "1.1.1.1", "port": 443, "protocol": "tcp"})
check("a port probe succeeds", res.get("success") is not False, str(res))
check("the probe connects to the requested address, port and protocol",
      probe_calls == [("1.1.1.1", 443, "tcp", 2.5)], str(probe_calls))
check("an open port is reported as open",
      res.get("status") == "open" and "OPEN" in res.get("output", ""), str(res.get("status")))
check("the probe's return code is reported", res.get("returncode") == 0, str(res))

probe_result[:] = [("closed", "Connection refused")]
res = port_probe({"host": "example.com", "port": 22, "protocol": "tcp"})
check("a refused connection is reported as closed with its reason",
      res.get("status") == "closed" and "Connection refused" in res.get("output", ""),
      str(res.get("output")))

probe_result[:] = [("filtered", "the attempt timed out after 2.5s")]
res = port_probe({"host": "10.0.0.9", "port": 80, "protocol": "tcp"})
check("a timeout is reported as filtered",
      res.get("status") == "filtered" and "timed out" in res.get("output", ""),
      str(res.get("output")))

probe_result[:] = [("sent", "no reply is expected: UDP is connectionless")]
res = port_probe({"host": "1.1.1.1", "port": 53, "protocol": "udp"})
check("a UDP probe reports that it sent, not that the port is open",
      res.get("status") == "sent" and "connectionless" in res.get("output", ""),
      str(res.get("output")))

for label, params in (
    ("a missing host", {"host": ""}),
    ("a bare-option host", {"host": "-f"}),
    ("a port of zero", {"host": "1.1.1.1", "port": 0}),
    ("a port above 65535", {"host": "1.1.1.1", "port": 70000}),
    ("a non-integer port", {"host": "1.1.1.1", "port": "https"}),
    ("an unknown protocol", {"host": "1.1.1.1", "protocol": "sctp"}),
    ("a non-numeric timeout", {"host": "1.1.1.1", "timeout": "soon"}),
):
    res = port_probe(params)
    check(f"the probe refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} opens no socket", probe_calls == [], str(probe_calls))

probe_result[:] = []
res = port_probe({"host": "1.1.1.1", "port": 443, "timeout": 99})
check("an over-long timeout is clamped to the daemon's ceiling",
      probe_calls == [("1.1.1.1", 443, "tcp", micrond.DIAG_PROBE_TIMEOUT_MAX)], str(probe_calls))

# ===========================================================================
# DNS multi-resolver benchmark: every row is a real `dig` against a fixed
# resolver set, so one that does not answer is not shown as a fast one. `_run`
# is already stubbed, so no query leaves the machine.
# ===========================================================================
print()
print("--- DNS multi-resolver benchmark ---")


def dns_benchmark(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_dns_benchmark(params))


for _name, _server, _category in micrond.DIAG_BENCHMARK_RESOLVERS:
    answers[("dig", "+time=1", "+tries=1", "+short", "@" + _server, "cloudflare.com", "A")] = \
        (0, "104.16.132.229\n", "")
# One resolver that never answers, so the "NO ANSWER" row is exercised.
answers[("dig", "+time=1", "+tries=1", "+short", "@9.9.9.9", "cloudflare.com", "A")] = (9, "", "")

res = dns_benchmark({"domain": "cloudflare.com"})
check("the benchmark succeeds", res.get("success") is not False, str(res))
check("every configured resolver is queried exactly once",
      len(calls) == len(micrond.DIAG_BENCHMARK_RESOLVERS), str(len(calls)))
check("every query is a short, time-bounded dig",
      all(argv[:3] == ["dig", "+time=1", "+tries=1"] and "+short" in argv for argv in calls),
      str(calls[:1]))
check("a resolver that answers is reported as resolved",
      "RESOLVED" in res.get("output", ""), str(res.get("output")))
check("a resolver that does not answer is reported as such, not as fast",
      "NO ANSWER" in res.get("output", ""), str(res.get("output")))
check("the summary names the lowest-latency resolver",
      "Summary: Lowest latency resolver is" in res.get("output", ""), str(res.get("output")))

res = dns_benchmark({"domain": "-f"})
check("the benchmark refuses a bare-option domain", res.get("success") is False, str(res.get("message")))
check("  …and a refused domain queries nothing", calls == [], str(calls))

check("the resolver set is fixed in the daemon, not chosen by the caller",
      len(micrond.DIAG_BENCHMARK_RESOLVERS) == 5, str(len(micrond.DIAG_BENCHMARK_RESOLVERS)))

# ===========================================================================
# Live packet capture: a session that `start`s, is `poll`ed for the output it
# has produced since the last read, and is `stop`ped. `_spawn_capture` is
# stubbed, so no `tcpdump` (and no root) is needed to drive the lifecycle.
# ===========================================================================
print()
print("--- Live packet capture ---")

capture_root = tempfile.mkdtemp(prefix="micron-capture-")
micrond.CAPTURE_DIR = capture_root

capture_spawns = []


class FakeCaptureProc:
    """A stand-in for the detached tcpdump process."""

    def __init__(self):
        self.pid = 424242
        self.returncode = None

    def poll(self):
        return self.returncode

    def terminate(self):
        self.returncode = -15

    def kill(self):
        self.returncode = -9

    def wait(self, timeout=None):
        if self.returncode is None:
            self.returncode = 0
        return self.returncode


def fake_spawn(argv, stdout_path):
    capture_spawns.append(list(argv))
    open(stdout_path, "w", encoding="utf-8").close()
    return FakeCaptureProc()


handler._spawn_capture = fake_spawn


def capture_start(params):
    calls.clear()
    capture_spawns.clear()
    return loop.run_until_complete(handler.rpc_diag_capture_start(params))


def capture_poll(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_capture_poll(params))


def capture_stop(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_capture_stop(params))


def capture_append(session_id, text):
    with open(handler._captures[session_id]["path"], "a", encoding="utf-8") as handle:
        handle.write(text)


res = capture_start({"interface": "eth0", "filter": "icmp", "count": 10})
check("a live capture starts", res.get("session") and res.get("running") is True, str(res))
check("the BPF filter is compiled before anything is launched",
      calls == [["tcpdump", "-d", "-i", "eth0", "icmp"]], str(calls))
check("the capture runs a bounded, line-buffered tcpdump",
      capture_spawns == [["timeout", "120", "tcpdump", "-i", "eth0", "-n", "-l", "-c", "10", "icmp"]],
      str(capture_spawns))

session = res.get("session", "")
check("the session id is 16 hex characters",
      len(session) == 16 and all(c in "0123456789abcdef" for c in session), session)
check("the command line the daemon ran is reported back",
      res.get("cmd") == "timeout 120 tcpdump -i eth0 -n -l -c 10 icmp", str(res.get("cmd")))

# A poll hands back only what is new since the last read.
capture_append(session, "12:00:01.1 IP 1.1.1.1 > 2.2.2.2: ICMP echo request\n")
first = capture_poll({"session": session})
check("a poll returns the output produced since the last read",
      "ICMP echo request" in first.get("output", ""), str(first.get("output")))
check("a running capture reports itself as running", first.get("running") is True, str(first))
second = capture_poll({"session": session})
check("a poll with nothing new returns nothing", second.get("output") == "", str(second.get("output")))
capture_append(session, "12:00:02.1 IP 2.2.2.2 > 1.1.1.1: ICMP echo reply\n")
third = capture_poll({"session": session})
check("the next poll returns only the new bytes",
      "echo reply" in third.get("output", "") and "echo request" not in third.get("output", ""),
      str(third.get("output")))

# When tcpdump exits on its frame count, the poll reports it done.
handler._captures[session]["proc"].returncode = 0
finished = capture_poll({"session": session})
check("a finished capture reports not-running and its return code",
      finished.get("running") is False and finished.get("returncode") == 0, str(finished))

# A stop terminates the process and hands back its tail.
res = capture_start({"interface": "eth1", "filter": "udp port 53", "count": 5})
stop_session = res.get("session", "")
capture_append(stop_session, "partial capture output\n")
stopped = capture_stop({"session": stop_session})
check("stopping a capture returns its tail",
      "partial capture output" in stopped.get("output", ""), str(stopped.get("output")))
check("a stopped capture is not running", stopped.get("running") is False, str(stopped))
check("stopping terminates the capture process",
      handler._captures[stop_session]["proc"].returncode is not None,
      str(handler._captures[stop_session]["proc"].returncode))

for label, params in (
    ("an interface that reads as an option", {"interface": "-i"}),
    ("a malformed interface", {"interface": "eth0;rm"}),
    ("an interface that is too long", {"interface": "a" * 20}),
    ("a frame count of zero", {"interface": "eth0", "count": 0}),
    ("a frame count above the cap", {"interface": "eth0", "count": micrond.DIAG_CAPTURE_MAX + 1}),
    ("a non-integer frame count", {"interface": "eth0", "count": "lots"}),
    ("a filter with a newline", {"interface": "eth0", "filter": "icmp\nsh"}),
):
    res = capture_start(params)
    check(f"the capture refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} launches nothing", capture_spawns == [], str(capture_spawns))

# The BPF expression is compiled for real: a filter tcpdump cannot build is
# refused with tcpdump's own reason, and nothing is launched.
answers[("tcpdump", "-d", "-i", "eth0", "tcp port")] = (1, "", "tcpdump: syntax error\n")
res = capture_start({"interface": "eth0", "filter": "tcp port "})
check("an uncompilable BPF filter is refused with tcpdump's reason",
      res.get("success") is False and "syntax error" in res.get("message", ""), str(res.get("message")))
check("  …and a bad filter launches nothing", capture_spawns == [], str(capture_spawns))

# An empty filter is not compiled and is not passed as an empty argv element.
res = capture_start({"interface": "eth1", "count": 3})
check("an empty filter is neither compiled nor passed",
      calls == [] and capture_spawns == [["timeout", "120", "tcpdump", "-i", "eth1", "-n", "-l", "-c", "3"]],
      f"{calls} / {capture_spawns}")

res = capture_poll({"session": "deadbeefdeadbeef"})
check("a poll for an unknown session is refused", res.get("success") is False, str(res.get("message")))
res = capture_stop({"session": "deadbeefdeadbeef"})
check("stopping an unknown session is refused", res.get("success") is False, str(res.get("message")))

# The number of live captures is bounded; the oldest finished ones are reaped.
handler._captures.clear()
for _ in range(micrond.DIAG_CAPTURE_MAX_SESSIONS):
    capture_start({"interface": "eth0", "count": 1})
over = capture_start({"interface": "eth0", "count": 1})
check("the number of concurrent captures is capped", over.get("success") is False, str(over.get("message")))

# A finished session is kept until its tail is read, but not past the capture's
# own wall-clock cap: a client that stops polling cannot hold a slot for good.
handler._captures.clear()
res = capture_start({"interface": "eth0", "count": 1})
stale_session = res.get("session", "")
handler._captures[stale_session]["proc"].returncode = 0
handler._captures[stale_session]["started"] = time.time() - (micrond.DIAG_CAPTURE_SECONDS + 60)
capture_start({"interface": "eth0", "count": 1})
check("an unread finished session is reaped once it outlives the wall-clock cap",
      stale_session not in handler._captures, str(list(handler._captures)))

check("the capture cap is 4 and the wall-clock cap is 120s",
      micrond.DIAG_CAPTURE_MAX_SESSIONS == 4 and micrond.DIAG_CAPTURE_SECONDS == 120,
      f"{micrond.DIAG_CAPTURE_MAX_SESSIONS}/{micrond.DIAG_CAPTURE_SECONDS}")

handler._captures.clear()
shutil.rmtree(capture_root, ignore_errors=True)

# ===========================================================================
# Streamed ping / traceroute: the same session shape as a live capture, so a
# reply or a hop reaches the page as it is produced instead of after the whole
# command. `_spawn_capture` is stubbed here too (no `ping`/`traceroute` runs),
# and `stdbuf` is pinned so the argv is asserted the same on every platform.
# ===========================================================================
print()
print("--- Streamed ping / traceroute ---")

stream_root = tempfile.mkdtemp(prefix="micron-stream-")
micrond.DIAG_STREAM_DIR = stream_root
micrond.STDBUF_BIN = "/usr/bin/stdbuf"

stream_spawns = []


def fake_stream_spawn(argv, stdout_path):
    stream_spawns.append(list(argv))
    open(stdout_path, "w", encoding="utf-8").close()
    return FakeCaptureProc()


handler._spawn_capture = fake_stream_spawn


def ping_start(params):
    calls.clear()
    stream_spawns.clear()
    return loop.run_until_complete(handler.rpc_diag_ping_start(params))


def traceroute_start(params):
    calls.clear()
    stream_spawns.clear()
    return loop.run_until_complete(handler.rpc_diag_traceroute_start(params))


def stream_poll(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_stream_poll(params))


def stream_stop(params):
    calls.clear()
    return loop.run_until_complete(handler.rpc_diag_stream_stop(params))


def stream_append(session_id, text):
    with open(handler._streams[session_id]["path"], "a", encoding="utf-8") as handle:
        handle.write(text)


# A streamed ping leaves ping running and hands back a session.
res = ping_start({"host": "1.1.1.1", "count": 3, "wait": 1})
check("a streamed ping starts", res.get("session") and res.get("running") is True, str(res))
check("the streamed ping argv is line-buffered and wall-clock bounded",
      stream_spawns == [["timeout", "300", "/usr/bin/stdbuf", "-oL", "-eL",
                         "ping", "-c", "3", "-i", "1", "-W", "2", "1.1.1.1"]],
      str(stream_spawns))

session = res.get("session", "")
check("the stream session id is 16 hex characters",
      len(session) == 16 and all(c in "0123456789abcdef" for c in session), session)

stream_append(session, "64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=4.12 ms\n")
first = stream_poll({"session": session})
check("a poll returns the reply produced since the last read",
      "icmp_seq=1" in first.get("output", ""), str(first.get("output")))
check("a running probe reports itself as running", first.get("running") is True, str(first))
second = stream_poll({"session": session})
check("a poll with nothing new returns nothing", second.get("output") == "", str(second.get("output")))

# When ping exits on its own, the poll reports it done.
handler._streams[session]["proc"].returncode = 0
finished = stream_poll({"session": session})
check("a finished probe reports not-running and its return code",
      finished.get("running") is False and finished.get("returncode") == 0, str(finished))

# A traceroute streams a hop at a time, with its own argv shape.
res = traceroute_start({"host": "8.8.8.8", "ttl": 18, "resolve": False, "useicmp": False})
trace_session = res.get("session", "")
check("a streamed traceroute starts",
      bool(trace_session) and res.get("kind") == "traceroute", str(res))
check("the streamed traceroute argv carries -n, -w and -m",
      stream_spawns == [["timeout", "300", "/usr/bin/stdbuf", "-oL", "-eL",
                         "traceroute", "-n", "-w", "2", "-m", "18", "8.8.8.8"]],
      str(stream_spawns))
stream_append(trace_session, " 1  192.168.1.1  0.412 ms\n")
hop1 = stream_poll({"session": trace_session})
stream_append(trace_session, " 2  10.0.0.1  4.221 ms\n")
hop2 = stream_poll({"session": trace_session})
check("each hop is delivered on its own poll",
      "192.168.1.1" in hop1.get("output", "") and "10.0.0.1" in hop2.get("output", ""),
      f"{hop1.get('output')!r} / {hop2.get('output')!r}")

# A stop ends the probe early and hands back the tail.
stream_append(trace_session, " 3  172.16.32.1  7.803 ms\n")
stopped = stream_stop({"session": trace_session})
check("stopping a probe returns its tail",
      "172.16.32.1" in stopped.get("output", ""), str(stopped.get("output")))
check("a stopped probe is not running", stopped.get("running") is False, str(stopped))
check("stopping terminates the probe process",
      handler._streams[trace_session]["proc"].returncode is not None,
      str(handler._streams[trace_session]["proc"].returncode))

# A probe that failed and printed nothing (an unresolvable name) says so.
res = ping_start({"host": "unresolvable.invalid"})
silent = res.get("session", "")
handler._streams[silent]["proc"].returncode = 2
quiet = stream_poll({"session": silent})
check("a probe that printed nothing and failed explains itself",
      "did not respond or could not be resolved" in quiet.get("output", ""), str(quiet.get("output")))

# Refusals run nothing at all.
for label, params in (
    ("a bare-option hostname", {"host": "-f"}),
    ("a protocol that is neither IPv4 nor IPv6", {"host": "1.1.1.1", "ipproto": "ipx"}),
    ("a count of zero", {"host": "1.1.1.1", "count": 0}),
    ("a count above the cap", {"host": "1.1.1.1", "count": micrond.DIAG_PING_MAX_COUNT + 1}),
    ("a wait above the cap", {"host": "1.1.1.1", "wait": micrond.DIAG_PING_MAX_WAIT + 1}),
):
    res = ping_start(params)
    check(f"a streamed ping refuses {label}", res.get("success") is False, str(res.get("message")))
    check(f"  …and {label} launches nothing", stream_spawns == [], str(stream_spawns))

res = traceroute_start({"host": "8.8.8.8", "ttl": micrond.DIAG_TRACEROUTE_MAX_TTL + 1})
check("a streamed traceroute refuses a hop limit above the cap", res.get("success") is False, str(res.get("message")))
check("  …and it launches nothing", stream_spawns == [], str(stream_spawns))

# Unknown sessions are refused for both poll and stop.
res = stream_poll({"session": "deadbeefdeadbeef"})
check("polling an unknown stream session is refused", res.get("success") is False, str(res.get("message")))
res = stream_stop({"session": "deadbeefdeadbeef"})
check("stopping an unknown stream session is refused", res.get("success") is False, str(res.get("message")))

# The stream cap counts running probes only, and finished ones are reaped.
handler._streams.clear()
for _ in range(micrond.DIAG_STREAM_MAX_SESSIONS):
    ping_start({"host": "1.1.1.1", "count": 1})
over = ping_start({"host": "1.1.1.1", "count": 1})
check("the number of concurrent streamed probes is capped", over.get("success") is False, str(over.get("message")))

handler._streams.clear()
res = ping_start({"host": "1.1.1.1", "count": 1})
stale = res.get("session", "")
handler._streams[stale]["proc"].returncode = 0
handler._streams[stale]["started"] = time.time() - (micrond.DIAG_STREAM_SECONDS + 60)
ping_start({"host": "1.1.1.1", "count": 1})
check("an unread finished stream session is reaped once it outlives the wall-clock cap",
      stale not in handler._streams, str(list(handler._streams)))

check("the stream cap is 4 and the wall-clock cap is 300s",
      micrond.DIAG_STREAM_MAX_SESSIONS == 4 and micrond.DIAG_STREAM_SECONDS == 300,
      f"{micrond.DIAG_STREAM_MAX_SESSIONS}/{micrond.DIAG_STREAM_SECONDS}")

handler._streams.clear()
shutil.rmtree(stream_root, ignore_errors=True)

print()
if failures:
    print(f"FAILED: {len(failures)} check(s): " + ", ".join(failures))
    sys.exit(1)

print("ALL MICROND DIAGNOSTICS CHECKS PASSED")

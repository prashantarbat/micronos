#!/usr/bin/env python3
"""
MicronOS micrond — network & security utility toolkit verification
====================================================================

Standalone harness (run: `python3 tests/test_micrond_toolkit.py`) for the
diag.* toolkit added by docs/MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md:

  diag.telnet, diag.nmap(_start), diag.mtr(_start), diag.dns_query, diag.whois,
  diag.arping, diag.sockets, diag.tls_probe, diag.http, diag.iperf3,
  diag.ethtool, diag.tools

What is proved here (spec §9, daemon tests 1-8):
  * happy paths against a real local TCP listener (telnet) and captured argv
    for tools that need a binary;
  * the injection matrix — metacharacters, newline, NUL, leading '-', 4 KiB
    junk — is refused before any child process is built;
  * forbidden options (nmap --script/-oN/-iL, curl file:// …) can never reach argv;
  * bounds: ports, timeouts, cycles, CIDR size, port-list size;
  * scanning requires an authorisation acknowledgement and refuses public space
    unless explicitly allowed;
  * a missing binary degrades with the package name, never a crash.
"""
import asyncio
import importlib.util
import os
import socket
import sys
import threading

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


def call(method, params=None, peer=None):
    return loop.run_until_complete(handler.handle_request(
        {"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}, peer))


def result(method, params=None):
    resp = call(method, params)
    if "error" in resp:
        return {"_error": resp["error"]}
    return resp["result"]


# ---- capture argv instead of running tools ---------------------------------
ARGV = []
FAKE_OUTPUT = {"text": "", "rc": 0}
REAL_WHICH = micrond.OpsMixin._which


def fake_bounded(argv, timeout=20.0, input_text=None, cap=65536):
    ARGV.append(list(argv))
    return {"rc": FAKE_OUTPUT["rc"], "output": FAKE_OUTPUT["text"], "timed_out": False,
            "truncated": False, "missing": False}


def installed(*_):
    return "/usr/bin/fake"


micrond.OpsMixin._run_bounded = staticmethod(fake_bounded)
micrond.OpsMixin._which = staticmethod(installed)

STREAMS = []
micrond.RpcHandler._stream_open = lambda self, argv, kind, host: (
    STREAMS.append(list(argv)) or {"session": "a" * 16, "running": True, "kind": kind, "cmd": " ".join(argv)})

INJECTIONS = ["host;id", "host|cat /etc/shadow", "host&&reboot", "`id`", "$(id)", "host\nid",
              "host'x", 'host"x', "-oN/tmp/x", "--script=all", "host\x00", "a" * 4096, "host x",
              "host>out", "../../etc/passwd"]

# =============================================================== telnet
srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("127.0.0.1", 0))
srv.listen(4)
PORT = srv.getsockname()[1]


def serve():
    for _ in range(3):
        try:
            conn, _ = srv.accept()
        except OSError:
            return
        conn.sendall(b"220 micron-test ESMTP ready\r\n")
        conn.close()


threading.Thread(target=serve, daemon=True).start()
res = result("diag.telnet", {"host": "127.0.0.1", "port": PORT, "timeout": 3})
check("telnet reports an open port", res.get("success") is True and res["parsed"]["open"] is True, str(res)[:200])
check("telnet captures the service banner", "220 micron-test ESMTP" in res["parsed"].get("banner", ""), res["parsed"].get("banner"))
check("telnet warns that telnet is unencrypted", any("unencrypted" in w for w in res.get("warnings", [])))

closed = socket.socket()
closed.bind(("127.0.0.1", 0))
cport = closed.getsockname()[1]
closed.close()
res = result("diag.telnet", {"host": "127.0.0.1", "port": cport, "timeout": 2})
check("telnet reports a closed port as closed, not success", res.get("success") is False and res.get("status") == "closed", str(res)[:200])

for bad in INJECTIONS:
    res = result("diag.telnet", {"host": bad, "port": 80})
    check(f"telnet refuses injected host {bad[:20]!r}", res.get("success") is False and "message" in res)
for port in (0, 65536, "x", -1):
    res = result("diag.telnet", {"host": "127.0.0.1", "port": port})
    check(f"telnet refuses port {port!r}", res.get("success") is False)
res = result("diag.telnet", {"host": "127.0.0.1", "port": 80, "timeout": 61})
check("telnet refuses a timeout above 30 s", res.get("success") is False)
res = result("diag.telnet", {"host": "127.0.0.1", "port": 80, "send": "GET /\nrm -rf"})
check("telnet refuses a multi-line payload", res.get("success") is False)

# =============================================================== nmap
ARGV.clear()
res = result("diag.nmap", {"target": "10.0.0.5", "profile": "quick"})
check("nmap refuses without an authorisation acknowledgement",
      res.get("success") is False and res.get("status") == "blocked" and not ARGV, str(res))
res = result("diag.nmap", {"target": "8.8.8.8", "profile": "quick", "authorized": "1"})
check("nmap refuses public address space by default", res.get("status") == "blocked" and not ARGV, str(res))
res = result("diag.nmap", {"target": "8.8.8.8", "profile": "quick", "authorized": "1", "allow_public": "1"})
check("nmap scans public space only when explicitly allowed", res.get("tool") == "nmap" and ARGV, str(res)[:120])
ARGV.clear()
res = result("diag.nmap", {"target": "10.0.0.0/23", "profile": "quick", "authorized": "1"})
check("nmap refuses a network larger than /24", res.get("success") is False and not ARGV, res.get("message"))
res = result("diag.nmap", {"target": "127.0.0.1", "profile": "quick", "authorized": "1"})
check("nmap refuses loopback targets", res.get("success") is False and not ARGV, res.get("message"))
res = result("diag.nmap", {"target": ",".join(f"10.0.0.{i}" for i in range(1, 40)), "profile": "quick", "authorized": "1"})
check("nmap refuses more than 32 listed targets", res.get("success") is False and not ARGV)
res = result("diag.nmap", {"target": "10.0.0.5", "profile": "aggressive-os", "authorized": "1"})
check("nmap refuses an unknown profile", res.get("success") is False and not ARGV)
res = result("diag.nmap", {"target": "10.0.0.5", "profile": "ports", "ports": "1-200", "authorized": "1"})
check("nmap refuses more than 128 explicit ports", res.get("success") is False and not ARGV, res.get("message"))
res = result("diag.nmap", {"target": "10.0.0.5", "profile": "ports", "ports": "22,80;id", "authorized": "1"})
check("nmap refuses an injected port list", res.get("success") is False and not ARGV)
for bad in INJECTIONS + ["10.0.0.1 -iL /etc/passwd", "--script=vuln", "-oX", "10.0.0.1,--resume"]:
    res = result("diag.nmap", {"target": bad, "profile": "quick", "authorized": "1"})
    check(f"nmap refuses target {bad[:24]!r}", res.get("success") is False and not ARGV)
ARGV.clear()
FAKE_OUTPUT.update(text="22/tcp open  ssh  syn-ack\n443/tcp open  https syn-ack\n", rc=0)
res = result("diag.nmap", {"target": "192.168.1.0/24", "profile": "service", "authorized": "1"})
argv = ARGV[-1] if ARGV else []
check("nmap builds an argv list ending with the target", argv[:1] == ["nmap"] and argv[-1] == "192.168.1.0/24", str(argv))
check("nmap argv never carries a forbidden option",
      not any(a.startswith(("--script", "-oN", "-oX", "-oG", "-oA", "-iL", "--datadir", "--resume", "--proxies")) for a in argv), str(argv))
check("nmap parses open ports", res["parsed"]["open_ports"] == [
    {"port": 22, "proto": "tcp", "service": "ssh"}, {"port": 443, "proto": "tcp", "service": "https"}], str(res["parsed"]))
STREAMS.clear()
res = result("diag.nmap_start", {"target": "192.168.1.10", "profile": "quick", "authorized": "1"})
check("nmap_start opens a streamed, cancellable session", res.get("session") and STREAMS and STREAMS[0][0] == "nmap", str(res))

# =============================================================== mtr
ARGV.clear()
FAKE_OUTPUT.update(text="HOST: fw  Loss%   Snt   Last   Avg  Best  Wrst StDev\n  1.|-- 10.0.0.1  0.0%  10  0.5  0.6  0.4  0.9  0.1\n", rc=0)
res = result("diag.mtr", {"host": "1.1.1.1", "cycles": 10})
check("mtr runs in report mode with a cycle count", ARGV and ARGV[-1][:2] == ["mtr", "--report"] and "-c" in ARGV[-1], str(ARGV[-1:]))
check("mtr parses hop statistics", res["parsed"]["hops"] and res["parsed"]["hops"][0]["host"] == "10.0.0.1", str(res["parsed"]))
res = result("diag.mtr", {"host": "1.1.1.1", "cycles": 21})
check("mtr refuses more than 20 cycles", res.get("success") is False)
for bad in INJECTIONS[:6]:
    res = result("diag.mtr", {"host": bad})
    check(f"mtr refuses injected host {bad[:16]!r}", res.get("success") is False)

# =============================================================== DNS / whois
ARGV.clear()
FAKE_OUTPUT.update(text=";; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 1\nexample.com. 60 IN A 93.184.216.34\n", rc=0)
res = result("diag.dns_query", {"name": "example.com", "type": "A", "server": "1.1.1.1", "dnssec": "1"})
check("dns_query builds dig argv with @server and +dnssec",
      ARGV[-1][:1] == ["dig"] and "@1.1.1.1" in ARGV[-1] and "+dnssec" in ARGV[-1], str(ARGV[-1]))
check("dns_query reports the rcode", res["parsed"]["rcode"] == "NOERROR")
res = result("diag.dns_query", {"name": "example.com", "server": "-f/etc/passwd"})
check("dns_query refuses a non-IP server", res.get("success") is False)
res = result("diag.dns_query", {"name": "example.com", "type": "AXFR"})
check("dns_query refuses zone transfers", res.get("success") is False)
ARGV.clear()
res = result("diag.whois", {"object": "AS13335"})
check("whois passes the object after `--`", ARGV and ARGV[-1] == ["whois", "--", "AS13335"], str(ARGV[-1:]))
for bad in ("-h evil", "x;id", "a b", "--verbose"):
    ARGV.clear()
    res = result("diag.whois", {"object": bad})
    check(f"whois refuses {bad!r}", res.get("success") is False and not ARGV)

# =============================================================== arping / sockets / ethtool
ARGV.clear()
res = result("diag.arping", {"interface": "eth0", "target": "192.168.1.1", "count": 3})
check("arping builds a bounded argv", ARGV[-1] == ["arping", "-c", "3", "-w", "5", "-I", "eth0", "192.168.1.1"], str(ARGV[-1:]))
check("arping refuses an IPv6 target", result("diag.arping", {"interface": "eth0", "target": "fe80::1"}).get("success") is False)
check("arping refuses an injected interface", result("diag.arping", {"interface": "eth0;id", "target": "10.0.0.1"}).get("success") is False)
ARGV.clear()
FAKE_OUTPUT.update(text='tcp LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=812,fd=3))\n', rc=0)
res = result("diag.sockets", {"listen": "1", "tcp": "1", "udp": "0"})
check("sockets uses ss with fixed flags", ARGV[-1][0] == "ss" and ARGV[-1][1].startswith("-H"), str(ARGV[-1:]))
check("sockets parses the owning process", res["parsed"]["sockets"][0]["process"] == "sshd", str(res["parsed"]))
check("ethtool refuses an unknown mode", result("diag.ethtool", {"interface": "eth0", "mode": "--flash"}).get("success") is False)

# =============================================================== TLS / HTTP
ARGV.clear()
FAKE_OUTPUT.update(text="New, TLSv1.3, Cipher is TLS_AES_256_GCM_SHA384\nsubject=CN = example.com\nissuer=CN = R3\nVerify return code: 0 (ok)\n", rc=0)
res = result("diag.tls_probe", {"host": "example.com", "port": 443})
check("tls_probe uses s_client with SNI", ARGV[0][:3] == ["openssl", "s_client", "-connect"] and "-servername" in ARGV[0], str(ARGV[:1]))
check("tls_probe parses protocol and cipher", res["parsed"]["protocol"] == "TLSv1.3" and res["parsed"]["cipher"] == "TLS_AES_256_GCM_SHA384", str(res["parsed"]))
check("tls_probe refuses a caller-chosen CA file", "-CAfile" not in " ".join(ARGV[0]))
check("tls_probe refuses an unknown version", result("diag.tls_probe", {"host": "example.com", "version": "ssl3"}).get("success") is False)

for url, why in (("file:///etc/shadow", "file scheme"), ("gopher://x", "gopher scheme"), ("dict://x", "dict scheme"),
                 ("http://169.254.169.254/latest/meta-data", "metadata address"),
                 ("http://metadata.google.internal/", "metadata host"), ("http://x/ -o /tmp/p", "whitespace"),
                 ("http://[fe80::1]/", "link-local"), ("-K/etc/passwd", "option")):
    ARGV.clear()
    res = result("diag.http", {"url": url})
    check(f"http probe refuses {why}", res.get("success") is False and not ARGV, str(res)[:120])
ARGV.clear()
FAKE_OUTPUT.update(text="HTTP/1.1 200 OK\r\n\n---\nhttp_code=200\nremote_ip=1.2.3.4\ntime_total=0.120\nnum_redirects=0\nurl_effective=https://example.com/\n", rc=0)
res = result("diag.http", {"url": "https://example.com/", "method": "HEAD", "follow": "1"})
argv = ARGV[-1]
check("http probe never writes a caller-chosen file", "--output" in argv and argv[argv.index("--output") + 1] == "/dev/null", str(argv))
check("http probe restricts protocols and redirects", "=http,https" in argv and "--max-redirs" in argv)
check("http probe passes the URL after `--`", argv[-2:] == ["--", "https://example.com/"])
check("http probe parses status and timing", res["parsed"]["http_code"] == 200 and res["parsed"]["timing_ms"].get("total") == 120.0, str(res["parsed"]))
check("http probe refuses POST", result("diag.http", {"url": "https://example.com/", "method": "POST"}).get("success") is False)

# =============================================================== iperf3
ARGV.clear()
FAKE_OUTPUT.update(text='{"end": {"sum_sent": {"bits_per_second": 941000000, "retransmits": 3}, "sum_received": {"bits_per_second": 939000000}}}', rc=0)
res = result("diag.iperf3", {"host": "10.0.0.2", "time": 5})
check("iperf3 runs a bounded JSON client", ARGV[-1][:3] == ["iperf3", "-c", "10.0.0.2"] and "-J" in ARGV[-1])
check("iperf3 reports throughput", res["parsed"]["sent_mbps"] == 941.0, str(res["parsed"]))
check("iperf3 refuses more than 30 s", result("diag.iperf3", {"host": "10.0.0.2", "time": 31}).get("success") is False)

# =============================================================== missing binaries
micrond.OpsMixin._which = staticmethod(lambda b: None)
for method, params in (("diag.nmap", {"target": "10.0.0.1", "authorized": "1"}), ("diag.mtr", {"host": "1.1.1.1"}),
                       ("diag.whois", {"object": "example.com"}), ("diag.http", {"url": "https://x.y/"})):
    res = result(method, params)
    check(f"{method} degrades with the missing package named",
          res.get("status") == "degraded" and res.get("package") and "not installed" in res.get("message", ""), str(res)[:160])
tools = result("diag.tools")["tools"]
check("diag.tools reports availability per tool", tools["nmap"]["installed"] is False and tools["nmap"]["package"] == "nmap")
micrond.OpsMixin._which = staticmethod(installed)

# =============================================================== caller classes
peer_other = {"uid": 4242, "pid": 99, "gid": 33}
resp = call("diag.nmap", {"target": "10.0.0.1", "authorized": "1"}, peer=peer_other)
check("an unprivileged peer cannot run an intrusive scan",
      resp.get("error", {}).get("code") == -32003, str(resp)[:160])
resp = call("diag.sockets", {}, peer=peer_other)
check("an unprivileged peer may still use read-only tools", "result" in resp, str(resp)[:160])
resp = call("diag.nmap", {"target": "10.0.0.1", "authorized": "1"}, peer={"uid": 0, "pid": 1, "gid": 0})
check("root may run an intrusive scan", "result" in resp)
resp = call("diag.ping", {}, peer={})
check("an unidentified peer is limited to read methods", "result" in resp)
resp = call("system.reboot", {}, peer={})
check("an unidentified peer cannot reboot", resp.get("error", {}).get("code") == -32003)

srv.close()
if failures:
    print(f"\nFAILED: {len(failures)} check(s): " + "; ".join(failures))
    sys.exit(1)
print("\nALL MICROND TOOLKIT CHECKS PASSED")

"""
micron-terminald — integration harness (run as root: `python3 tests/test_micron_terminald.py`).

Starts the real service on a private port and control socket, then drives it
the way the Web UI does: issue a ticket over the control socket, open the
WebSocket with that ticket and Origin, run commands in the real PTY, resize,
interrupt, detach/reattach, and check every refusal path:

  * no ticket / reused ticket / wrong Origin / wrong client address → 403
  * a ticket for another user's session is refused
  * per-user session limit
  * standard sessions are not root; admin sessions are
  * idle timeout ends a session and kills its processes
  * malformed and oversized messages do not take the service down
  * the audit log records events but never terminal contents
"""
import base64
import json
import os
import socket
import struct
import subprocess
import sys
import tempfile
import time

if os.name == "nt":
    print("SKIP micron-terminald PTY harness requires Linux/POSIX environment")
    sys.exit(0)

import pwd

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
failures = []


def jl(text):
    """Strict JSON (NaN / Infinity refused), like the service's own decoder."""
    def reject(name):
        raise ValueError(name)
    return json.loads(text, parse_constant=reject)


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail and not cond else ""))
    if not cond:
        failures.append(name)


if os.geteuid() != 0:
    print("SKIP: needs root (the service starts shells for other accounts)")
    sys.exit(0)

TMP = tempfile.mkdtemp(prefix="micron-term-test-")
PORT = 17681
CTL = os.path.join(TMP, "terminal.sock")
AUDIT = os.path.join(TMP, "audit.log")
cfg = {"listen_port": PORT, "control_socket": CTL, "audit_log": AUDIT, "standard_user": "nobody",
       "control_group": "root", "idle_timeout": 6, "admin_idle_timeout": 6, "max_sessions_per_user": 2,
       "detach_grace": 30, "ticket_ttl": 5}
with open(os.path.join(TMP, "terminal.json"), "w") as fh:
    json.dump(cfg, fh)

env = dict(os.environ, MICRON_TERMINAL_CONFIG=os.path.join(TMP, "terminal.json"))
proc = subprocess.Popen([sys.executable, os.path.join(ROOT, "system", "micron-terminald")], env=env,
                        stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
for _ in range(50):
    if os.path.exists(CTL):
        break
    time.sleep(0.1)


def control(method, params=None):
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.connect(CTL)
    body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}).encode()
    s.sendall(struct.pack("!I", len(body)) + body)
    n = struct.unpack("!I", s.recv(4))[0]
    data = b""
    while len(data) < n:
        data += s.recv(n - len(data))
    s.close()
    return jl(data)


def printed(out, word):
    """`word` printed by a command (at the start of a line), not just echoed as input."""
    return (b"\r" + word + b"\r\n") in out or (b"\n" + word + b"\r\n") in out


def issue(user="7", name="alice", level="standard", origin="http://10.0.0.1", client="127.0.0.1", attach=None):
    p = {"user_id": user, "username": name, "level": level, "origin": origin, "client_ip": client}
    if attach:
        p["attach"] = attach
    return control("terminal.issue", p)["result"]


class WS:
    def __init__(self, ticket, origin="http://10.0.0.1"):
        self.s = socket.create_connection(("127.0.0.1", PORT), timeout=5)
        key = base64.b64encode(os.urandom(16)).decode()
        req = (f"GET /ws?ticket={ticket} HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
               f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {origin}\r\n\r\n")
        self.s.sendall(req.encode())
        head = b""
        while b"\r\n\r\n" not in head:
            chunk = self.s.recv(1)
            if not chunk:
                break
            head += chunk
        self.status = int(head.split(b" ")[1]) if head else 0
        self.buf = b""
        self.texts = []
        self.out = b""

    def send(self, obj, opcode=1, raw=None):
        payload = raw if raw is not None else json.dumps(obj).encode()
        mask = os.urandom(4)
        n = len(payload)
        hdr = bytes([0x80 | opcode])
        if n < 126:
            hdr += bytes([0x80 | n])
        elif n < 65536:
            hdr += bytes([0x80 | 126]) + struct.pack("!H", n)
        else:
            hdr += bytes([0x80 | 127]) + struct.pack("!Q", n)
        self.s.sendall(hdr + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))

    def pump(self, seconds=1.0, until=None):
        end = time.time() + seconds
        self.s.settimeout(0.2)
        while time.time() < end:
            try:
                chunk = self.s.recv(65536)
                if not chunk:
                    return False
                self.buf += chunk
            except socket.timeout:
                pass
            except OSError:
                return False
            while len(self.buf) >= 2:
                b1, b2 = self.buf[0], self.buf[1]
                n, off = b2 & 0x7F, 2
                if n == 126:
                    if len(self.buf) < 4:
                        break
                    n, off = struct.unpack("!H", self.buf[2:4])[0], 4
                elif n == 127:
                    if len(self.buf) < 10:
                        break
                    n, off = struct.unpack("!Q", self.buf[2:10])[0], 10
                if len(self.buf) < off + n:
                    break
                payload, self.buf = self.buf[off:off + n], self.buf[off + n:]
                op = b1 & 0x0F
                if op == 1:
                    self.texts.append(jl(payload))
                elif op == 2:
                    self.out += payload
                elif op == 9:
                    self.send(None, opcode=0xA, raw=payload)
                elif op == 8:
                    return False
            if until and until(self):
                return True
        return True

    def type(self, text):
        self.send({"t": "i", "d": text})


try:
    st = control("terminal.status")["result"]
    check("service answers on the control socket", st["success"] and st["sessions"] == 0, str(st))

    # --- refusals before any shell starts
    check("an invalid level is refused", issue(level="root")["success"] is False)
    check("an origin with a path is refused", issue(origin="http://10.0.0.1/evil")["success"] is False)
    check("a bad client address is refused", issue(client="not-an-ip")["success"] is False)
    w = WS("bogus")
    check("an unknown ticket gets 403", w.status == 403, str(w.status))
    t = issue()["ticket"]
    w = WS(t, origin="http://attacker.example")
    check("a wrong Origin gets 403 (cross-site WebSocket hijacking)", w.status == 403, str(w.status))
    w = WS(t)
    check("a ticket is single-use even after a refused attempt", w.status == 403, str(w.status))
    t = issue(client="10.9.9.9")["ticket"]
    w = WS(t)
    check("a ticket bound to another client address gets 403", w.status == 403, str(w.status))

    # --- a standard session
    w = WS(issue()["ticket"])
    check("a valid ticket upgrades to WebSocket", w.status == 101, str(w.status))
    w.pump(2, until=lambda x: any(m.get("t") == "hello" for m in x.texts))
    hello = next((m for m in w.texts if m.get("t") == "hello"), {})
    sid = hello.get("session")
    check("hello names the session and the level", bool(sid) and hello.get("level") == "standard", str(w.texts))
    w.send({"t": "r", "c": 132, "r": 40})
    w.type("stty size; id -u; echo TERM=$TERM\n")
    w.pump(5, until=lambda x: b"TERM=xterm" in x.out)
    check("resize reaches the PTY (stty size = 40 132)", b"40 132" in w.out, w.out[-300:])
    nobody = str(pwd.getpwnam("nobody").pw_uid).encode()
    check("the standard shell runs as the unprivileged account", b"\n" + nobody in w.out.replace(b"\r", b""), w.out[-300:])
    check("the terminal type is xterm-256color", b"TERM=xterm-256color" in w.out)
    w.out = b""
    w.type("sleep 30; echo NOTREACHED\n")
    time.sleep(0.5)
    w.type("\x03")
    w.type("echo AFTER-INTERRUPT\n")
    w.pump(5, until=lambda x: printed(x.out, b"AFTER-INTERRUPT"))
    check("Ctrl+C interrupts the foreground command", printed(w.out, b"AFTER-INTERRUPT") and not printed(w.out, b"NOTREACHED"), w.out[-300:])
    w.send({"t": "r", "c": 99999, "r": -1})
    w.send(None, raw=b"{not json")
    w.pump(1)
    check("invalid sizes and malformed messages are ignored, not fatal",
          sum(1 for m in w.texts if m.get("t") == "notice") >= 2, str(w.texts))

    # --- ownership and reattach
    other = issue(user="8", name="mallory", attach=sid)
    check("another user cannot get a ticket for this session", other["success"] is False, str(other))
    w.s.close()
    time.sleep(0.5)
    lst = control("terminal.list", {"user_id": "7"})["result"]["sessions"]
    check("a closed tab leaves the session detached, not killed", len(lst) == 1 and lst[0]["attached"] is False, str(lst))
    w2 = WS(issue(attach=sid)["ticket"])
    w2.pump(3, until=lambda x: any(m.get("t") == "hello" for m in x.texts) and printed(x.out, b"AFTER-INTERRUPT"))
    h2 = next((m for m in w2.texts if m.get("t") == "hello"), {})
    check("the owner can reattach to the same shell", h2.get("session") == sid and h2.get("reattached") is True, str(w2.texts))
    check("reattach replays recent output", printed(w2.out, b"AFTER-INTERRUPT"), w2.out[-200:])

    # --- limits
    w3 = WS(issue()["ticket"])
    w3.pump(1)
    lim = issue()
    check("the per-user session limit is enforced", lim["success"] is False and lim.get("code") == "limit", str(lim))

    # --- admin session
    wa = WS(issue(user="1", name="root-admin", level="admin")["ticket"])
    wa.pump(2, until=lambda x: any(m.get("t") == "hello" for m in x.texts))
    wa.type("id -u\n")
    wa.pump(2, until=lambda x: b"\n0\r" in x.out.replace(b"\r\n", b"\n\r"))
    check("an admin session is a root shell", b"0\r\n" in wa.out, wa.out[-200:])

    # --- explicit termination and process cleanup
    wa.type("sleep 300 &\n")
    time.sleep(0.5)
    admin_sid = next((m for m in wa.texts if m.get("t") == "hello"), {}).get("session")
    pgid = next(s["pid"] for s in control("terminal.list")["result"]["sessions"] if s["id"] == admin_sid)
    wa.send({"t": "x"})
    wa.pump(3, until=lambda x: any(m.get("t") == "closed" for m in x.texts))
    time.sleep(0.5)
    left = subprocess.run(["pgrep", "-g", str(pgid)], capture_output=True, text=True).stdout.strip()
    check("closing a session kills its whole process group (no orphans)", left == "", left)

    # --- idle timeout
    deadline = time.time() + 20
    while time.time() < deadline and control("terminal.list", {"user_id": "7"})["result"]["sessions"]:
        w2.pump(1)
        w3.pump(1)
    check("idle sessions are ended by the idle timeout",
          control("terminal.list", {"user_id": "7"})["result"]["sessions"] == [])
    check("the browser is told why", any(m.get("t") == "closed" and "idle" in m.get("reason", "") for m in w3.texts + w2.texts),
          str(w3.texts[-3:]))

    # --- revoke
    w4 = WS(issue(user="9", name="bob")["ticket"])
    w4.pump(1)
    r = control("terminal.revoke_user", {"user_id": "9", "reason": "logged out"})["result"]
    time.sleep(1)
    check("revoke_user ends every session of a user (logout / permission change)",
          r["terminated"] == 1 and control("terminal.list", {"user_id": "9"})["result"]["sessions"] == [], str(r))

    check("the service is still healthy after all of the above", proc.poll() is None and control("terminal.status")["result"]["success"])

    audit = open(AUDIT).read()
    events = [jl(line)["event"] for line in audit.splitlines()]
    for ev in ("ticket_issued", "auth_failed", "session_start", "admin_session_start", "admin_session_end",
               "session_detach", "session_attach", "limit_reached", "attach_denied"):
        check(f"audit records {ev}", ev in events)
    check("the audit log never contains terminal contents", "AFTER-INTERRUPT" not in audit and "sleep 30" not in audit)
    check("the audit log is private (0600)", oct(os.stat(AUDIT).st_mode & 0o777) == "0o600", oct(os.stat(AUDIT).st_mode))
finally:
    proc.terminate()
    try:
        proc.wait(timeout=10)
    except subprocess.TimeoutExpired:
        proc.kill()

check("the service stops cleanly on SIGTERM", proc.returncode == 0, str(proc.returncode))

if failures:
    print(f"\nFAILED: {len(failures)} check(s): " + "; ".join(failures))
    sys.exit(1)
print("\nALL TERMINAL SERVICE CHECKS PASSED")

# MicronOS — Diagnostic & Network Utility Toolkit Specification

**Document type:** Feature specification
**Scope:** first-class network/security utilities (telnet, nmap, mtr, whois/dig, arping,
socket list, TLS probe, HTTP probe, iperf3) with menus, RBAC, RPC, CLI and tests.
**Companion:** [MICRONOS_COMPLETION_PROGRAMME.md](MICRONOS_COMPLETION_PROGRAMME.md) §4 W4,
[MICRONOS_IPC_RPC_CLI_CONTRACT.md](MICRONOS_IPC_RPC_CLI_CONTRACT.md).

---

## 1. Goal

Turn **Diagnostics** into the toolbox an engineer reaches for during an incident. Today
MicronOS offers ping, traceroute, DNS lookup, packet capture, port probe, DNS benchmark,
route/NDP/conntrack inspection and firewall log tail. That answers "is it up?" but not:

* "is that TCP service actually accepting and speaking the protocol?" → **telnet / nc**
* "which ports are open on that host or segment?" → **nmap**
* "where precisely is the loss and latency?" → **mtr**
* "who owns that address, and what does DNS really return?" → **whois / dig**
* "is the neighbour alive at layer 2?" → **arping**
* "who is listening on this box, and which process holds it?" → **ss**
* "what certificate and ciphers is that endpoint presenting?" → **openssl s_client**
* "what does that API return, with which headers and status?" → **HTTP probe**
* "how much throughput do I actually get?" → **iperf3** (exists as a plugin; promote)

Every tool must be **safe, permissioned, audited, testable, and reachable from both the
Web UI and the console CLI**.

---

## 2. Current inventory (verified)

| Layer | Present |
|---|---|
| RPC (`diag.*`, 18) | `ping`, `ping_start`, `traceroute`, `traceroute_start`, `dns_lookup`, `tcpdump`, `port_probe`, `dns_benchmark`, `capture_start`, `capture_poll`, `capture_stop`, `stream_poll`, `stream_stop`, `route_lookup`, `routing_table`, `ndp_table`, `conntrack_states`, `firewall_logs` |
| Controller | `app/Controllers/Diagnostics.php` — `index`, `ping`, `pingStart`, `traceroute`, `tracerouteStart`, `dnsLookup`, `packetCapture(Start/Stream/Stop)`, `diagnosticStream(Stop)`, `routeLookup`, `portProbe`, `dnsBenchmark`, `logs(Data)`, `states(Data)`, `exportStatesCsv/Json`, `killState`, `killHostStates` + validation helpers `targetInputError`, `sourceInputError`, `ipLiteral`, `isHostname` |
| Routes | group `diag`, view permission `system.info.view`, action permissions `diag.ping`, `diag.packet_capture`, `diag.logs.view`, `diag.audit.view`, `diag.arp.view`, `diag.states.view` |
| Views | `app/Views/diag/index.php`, `logs.php`, `states.php` |
| CLI | **nothing** diagnostic in `microctl`; `micron-menu` unverified |

**Reuse, don't fork:** the existing validation helpers, the `capture_start/_poll/_stop`
and `stream_poll/_stop` job pattern, the mock-output convention, and the mock-mode banner.
A new tool that invents its own validation dialect or its own streaming protocol is a
defect, not a feature.

---

## 3. Tool catalogue

`Permission` = the new route key to introduce. `Class` = daemon capability class
(read / configure / destructive — see the contract doc §7).

| Tool | Operator question | RPC method | Permission | Class | CLI |
|---|---|---|---|---|---|
| **TCP service probe (telnet/nc)** | "Is this TCP port accepting, what does it say?" | `diag.telnet` | `diag.telnet` | configure | `microctl diag telnet <host> <port>` |
| **Port scan (nmap)** | "Which ports are open?" | `diag.nmap` (+ `_poll/_stop`) | `diag.scan` | destructive | `microctl diag scan <target>` |
| **Path/loss (mtr)** | "Where is the loss?" | `diag.mtr` (+ `_poll/_stop`) | `diag.ping` | configure | `microctl diag mtr <host>` |
| **Whois** | "Who owns this?" | `diag.whois` | `diag.ping` | read | `microctl diag whois <object>` |
| **DNS query (dig/drill)** | "What does DNS really say?" | `diag.dns_query` | `diag.ping` | read | `microctl diag dns <name> [type]` |
| **L2 neighbour (arping/ndisc6)** | "Is it alive at L2?" | `diag.arping` | `diag.arp.view` | configure | `microctl diag arping <if> <ip>` |
| **Listening sockets (ss)** | "Who is listening here?" | `diag.sockets` | `diag.states.view` | read | `microctl diag sockets` |
| **TLS probe (s_client)** | "What cert/cipher/version is offered?" | `diag.tls_probe` | `diag.tls` | configure | `microctl diag tls <host>[:port]` |
| **HTTP probe (curl)** | "What does this endpoint return?" | `diag.http` | `diag.http` | configure | `microctl diag http <url>` |
| **Throughput (iperf3)** | "How fast, really?" | `plugin.iperf3.*` → promote to `diag.iperf3` | `diag.iperf` | configure | `microctl diag iperf <host>` |

New permission keys: `diag.telnet`, `diag.scan`, `diag.tls`, `diag.http`, `diag.iperf`.
Each must be added to the permission catalog and to the role editor, and documented in
`MICRONOS_MODULE_REFERENCE.md`.

---

## 4. Safety model (normative)

These are requirements, not guidance. Each is testable; each gets a test.

### 4.1 No shell, ever

* Execution uses argument arrays (`subprocess.run(argv, …)` / `escapeshellarg`), never a
  shell string.
* **No user-supplied flags.** Flags are server-side constants chosen by the selected
  mode. If an operator needs a variant, add an enum case in the daemon — not a free-text
  flag field.
* Reject any input beginning with `-` where a target is expected (option injection).
* Reject shell metacharacters and whitespace in host/port/interface/URL fields
  (`;`, `|`, `&`, `` ` ``, `$`, newline, quotes, `>`/`<`).

### 4.2 Explicitly forbidden tool options

The following are **not exposed** and must be asserted absent by test:

| Tool | Never exposed |
|---|---|
| nmap | `--script*`, `-oN/-oX/-oG/-oA` (file output), `-iL` (target file), `--datadir`, `--servicedb`, `--resume`, `--proxies`, `-b` (FTP bounce), `--interactive` |
| nc/telnet | `-e`/`-c` (command execution), `-l` on the appliance, arbitrary port redirection |
| curl/http | `-o`/`-O` (file write), `--config`, `@file` upload syntax, `file://`, `gopher://`, `dict://` |
| openssl | any `-CAfile`/`-cert` path supplied by the caller |
| tcpdump | `-z` (postrotate command), `-w` to a caller-chosen path, `-F` file filters |
| whois | any flag that reads a file |

**SSRF guard for HTTP probe:** refuse `file://`, `unix://` and other non-HTTP schemes;
cap redirects; make following redirects to link-local/metadata addresses
(`169.254.0.0/16`, `fd00:ec2::254`, `metadata.google.internal`) an explicit refusal;
prefer egress through a selected source interface.

### 4.3 Target policy

* Default: permit RFC1918/ULA and public targets for `ping`/`traceroute`/`telnet`/`dns`/
  `whois`/`tls`.
* **Scanning** (`diag.nmap`) requires an explicit acknowledgement from the operator
  ("I confirm I am authorised to scan this target"), is logged, and is rate/time bounded.
* Scanning public address space is refused by default; if enabled, it requires a
  dedicated permission and an interface selection.
* Refuse targets that would route through the appliance's own management socket/loopback
  unless the operator confirms a local self-test.

### 4.4 Bounds (hard server-side caps)

| Dimension | Cap |
|---|---|
| timeout per invocation | 1–60 s (default chosen per tool) |
| output retained | 64 KiB (truncate with a visible `… truncated` marker) |
| job runtime (`nmap`, `mtr`, `iperf3`) | ≤ 300 s, cancellable |
| nmap targets | 1 host, or one CIDR ≤ /24, or an explicit list ≤ 32 hosts |
| nmap ports | ≤ top 1000, or an explicit list ≤ 128 ports |
| mtr cycles | ≤ 20 |
| concurrency | one running job per tool per session; refuse with a clear message |
| rate | minimum 1 s between invocations of the same intrusive tool per session |

### 4.5 Audit

Every invocation writes an audit entry: actor, source (UI/CLI), tool, target, port/proto,
duration, result status, bytes returned. Useful for both incident review and for proving
the appliance was not used as a pivot.

---

## 5. Common API shape

```jsonc
// request
{ "jsonrpc":"2.0", "id":1, "method":"diag.telnet",
  "params":{ "host":"10.0.0.5", "port":443, "timeout":5 } }

// response
{ "jsonrpc":"2.0", "id":1, "result":{
    "success": true,
    "status": "ok",              // ok | error | blocked | degraded
    "output": "Connected to 10.0.0.5.\n220 mail.example ESMTP\n",
    "parsed": { "open": true, "banner": "220 mail.example ESMTP" },
    "warnings": [] } }
```

Errors use the same envelope with `success:false`, a `message` sentence an operator can
act on (naming the host/port), and `error.code` where meaningful. Long jobs return an id
from `*_start` and are drained with `*_poll` / stopped with `*_stop`, exactly like
`diag.capture_*`.

---

## 6. Web UI

* Diagnostics gets **tabs**: `Reachability` (ping, traceroute, mtr), `Ports & Services`
  (telnet/nc, nmap), `DNS` (query, benchmark, whois), `Layer 2` (arping, NDP),
  `Sockets & Sessions` (ss, conntrack, states), `TLS & HTTP`, `Packets` (capture),
  `Logs`, `Throughput` (iperf3).
* Each tab: a form with per-field help, remembered last-used values, a **Run** button, a
  live output pane with copy/download, a **Cancel** for jobs, and a visible elapsed timer.
* Intrusive tools (`nmap`, telnet to a third party) show an authorisation checkbox and a
  one-line privacy/legal reminder before enabling **Run**.
* Every result is honest about partial output (`… truncated at 64 KiB`), timeouts and
  refusals — never a bare spinner.
* Keyboard: `Ctrl/⌘+Enter` runs, `Esc` cancels.
* Mobile: forms and output reflow; the output pane stays monospaced and scrollable.
* Accessibility: `aria-live="polite"` on the output region, labelled inputs, focus
  management on tab change.

---

## 7. CLI

```text
microctl diag ping <host> [--count N] [--source IFACE] [--json]
microctl diag traceroute <host> [--ttl N] [--json]
microctl diag mtr <host> [--cycles N] [--json]
microctl diag telnet <host> <port> [--timeout N] [--json]
microctl diag scan <host|cidr> [--ports LIST] [--timeout N] [--yes] [--json]
microctl diag dns <name> [--type A|AAAA|MX|TXT|CNAME|NS|SOA] [--server IP] [--json]
microctl diag whois <object> [--json]
microctl diag arping <iface> <ip> [--count N] [--json]
microctl diag sockets [--listen] [--tcp] [--udp] [--json]
microctl diag tls <host>[:port] [--sni NAME] [--json]
microctl diag http <url> [--method GET|HEAD|POST] [--header 'K: V'] [--json]
microctl diag iperf <host> [--time N] [--json]
```

Every subcommand: `--help`, `--json`, non-zero exit on failure, and no capability that the
Web UI cannot do. `--yes` is required for intrusive scanning so a script cannot silently
scan a network.

`micron-menu` mirrors these in the Diagnostics submenu.

---

## 8. Mock mode

`IpcClient::mockDispatch()` gains a plausible simulated response per new method so the UI
is developable on Windows and the pages are demoable. Mock output must be clearly
labelled as simulated in the UI (the existing mock banner pattern), and must never be
mistaken for a real result in a support conversation.

---

## 9. Tests (required)

**Daemon (pytest, `tests/test_micrond_diag.py` + new groups):**

1. Happy path per tool against localhost/a fixture server (open and closed port,
   resolvable and non-resolvable host, valid and self-signed certificate).
2. Injection matrix per text field: `;`, `|`, `&&`, `` ` ``, `$()`, newline, quotes,
   leading `-`, NUL, 4 KiB junk → all rejected before execution, none reach the child
   process argv.
3. Option-injection: `--script`, `-oN`, `-iL`, `-c`, `-e`, `--config`, `-z`, `-F` →
   refused.
4. Bounds: timeout outside 1–60 s, port 0/65536, CIDR larger than /24 (scan), too many
   targets, too many ports, output larger than the cap (truncation flagged).
5. Concurrency/rate: second simultaneous job refused; rapid repeats throttled.
6. SSRF: `file://`, `unix://`, metadata address → refused.
7. Audit: entry written with actor/target/result.
8. Cancellation: `*_stop` terminates the child and reports `status: cancelled`.
9. No orphan processes after stop/timeout.

**Web (PHPUnit):**

10. 403 without the tool's permission; 403 for the view without `system.info.view`.
11. Invalid input returns a per-field error, not a 500, and does not call the daemon
    (assert via `FakeIpcClient::$calls` being empty).
12. Mock-mode happy path renders output.
13. CSRF required on every POST.
14. Secrets scan: no key material in any diag response.
15. Output escaping: hostile output containing `<script>` is escaped in the render.

**CLI (shell):**

16. `--help` for every subcommand; `--json` parses; invalid input exits non-zero;
    missing permission exits non-zero with an actionable message.

**Live (VM):**

17. Run each tool against a real target and capture the output in the verification report.

---

## 10. Packaging dependency

`nmap`, `mtr`, `whois`, `dnsutils` (or `bind9-dnsutils`), `arping`/`iputils-arping`,
`netcat-openbsd`, `iproute2` (`ss`), `openssl`, `curl` must exist in the appliance image.
Add them to the live-build package list and to the `verify:system` checks, and make each
tool's page degrade explicitly ("`nmap` is not installed on this appliance") rather than
failing opaquely.

---

## 11. Rollout

| Phase | Contents | Exit criterion |
|---|---|---|
| **P1** | `diag.telnet`, `diag.mtr`, `diag.whois`, `diag.dns_query`, `diag.sockets`, `diag.arping` | Each live in UI + CLI, injection-tested, permissioned |
| **P2** | `diag.nmap` with job/progress/cancel + authorisation gate; `diag.tls_probe`; `diag.http` | Job pattern reused from `capture_*`; SSRF and option-injection tests green |
| **P3** | Promote iperf3 into Diagnostics while keeping the plugin as its packaging wrapper; unify the Throughput page | One page, one RPC surface, plugin manages the dependency |

---

## 12. Decisions to record

1. **Core vs plugin.** Generic tools (telnet, nmap, mtr, dig, whois, ss, TLS/HTTP probe)
   belong in core Diagnostics; heavy or optional daemons stay plugins. iperf3 is both:
   plugin for the package/lifecycle, core for the operator surface. Record the rationale
   per tool in this document as it is implemented.
2. **Scanning policy.** Default-deny for public address space; document the exact rule,
   the permission that overrides it, and the audit entry it produces.
3. **Output retention.** In-memory only, or downloadable as a file (with the file-write
   path server-chosen)? Decide and document before P2.

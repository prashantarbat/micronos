# MicronOS — Completion Programme

**Document type:** Programme plan + audited gap register
**Prepared:** 2026-10-13
**Scope:** Web UI revamp, IPC/RPC ⇄ CLI alignment, network/security utility toolkit,
plugin workspaces, and end-to-end regression validation.
**Companion:** [MICRONOS_COMPLETION_MASTER_PROMPT.md](MICRONOS_COMPLETION_MASTER_PROMPT.md)
(the paste-ready instruction), [MICRONOS_IPC_RPC_CLI_CONTRACT.md](MICRONOS_IPC_RPC_CLI_CONTRACT.md),
[MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md](MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md),
[MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md](MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md).

---

## 1. Purpose

MicronOS is functionally broad: 140 daemon RPC handlers, 35+ controllers, 8 plugins,
two console CLIs, an ISO pipeline and a large test suite. What it is **not** yet is
*finished* — and the difference between broad and finished is where this programme
lives:

1. every capability reachable and *working* from the Web UI,
2. the same capability reachable from the CLI,
3. one authoritative daemon implementation behind both,
4. honest state reporting (no control that silently does nothing),
5. and evidence — tests and a validation report — that all of the above is true.

This document records the **verified** current state, the **gaps** between it and that
target, and the workstreams that close them.

---

## 2. Verified current state (audit, 2026-10-13)

All of the following was confirmed by reading the tree, not assumed.

### 2.1 Architecture — sound, keep it

| Subsystem | State |
|---|---|
| Web app | CodeIgniter 4, `micronos/app`, server-rendered views under `app/Views/` |
| Privileged daemon | `system/micrond/micrond.py`, ~9,000 lines, **140** `rpc_*` handlers |
| IPC | `AF_UNIX`/`SOCK_STREAM` at `/run/micron/micrond.sock`, 4-byte big-endian length prefix + strict JSON-RPC 2.0; socket `0660` group `www-data` |
| PHP client | `app/Libraries/IpcClient.php` (TIMEOUT 5 s, MAX_READ 64 KiB, mock mode on Windows/no socket) |
| Authz | global `csrf` + `auth` filters; per-route `permission:<key>` → `app/Filters/PermissionFilter.php` → `PermissionModel::userCan()` |
| Plugins | `app/Plugins/definitions/<id>/plugin.json` validated by `PluginManifest` (closed enums, no `shell.run`); `PluginNav::entries()` renders `provides.menu`; `Plugins::show/$1` serves `/plugins/<id>`; daemon resolves packages/units/capabilities against a fixed `PLUGIN_CATALOG` and refuses undeclared capabilities |
| CLIs | `bin/microctl` (break-glass console), `bin/micron-menu` (TUI), `spark` + 17 commands in `app/Commands/` |
| Tests | 142 PHPUnit files, 16 pytest files, 3 shell suites |

### 2.2 Daemon RPC surface (by namespace)

```
plugin 32   diag 18   system 10   vpn 8        dhcp 7    user 5   pppoe 5
webfilter 4 squid 4   network 4   interface 4  firewall 4 captive 4
ztna 3      ssl 3      sdwan 3     dns 3        bridge 3  bond 3
wireless 2  route 2    multicast 2 dnsbl 2      service 1 routing 1
nat 1       ids 1      connector 1
```

`diag.*` today: `ping`, `ping_start`, `traceroute`, `traceroute_start`, `dns_lookup`,
`tcpdump`, `port_probe`, `dns_benchmark`, `capture_start|poll|stop`,
`stream_poll|stop`, `route_lookup`, `routing_table`, `ndp_table`,
`conntrack_states`, `firewall_logs`.

**Absent:** `telnet`/`nc`, `nmap`, `mtr`, `whois`, `ss`/socket list, `arping`,
`openssl s_client`/TLS probe, `curl`/HTTP probe, `iperf3` (only as a plugin).

### 2.3 Plugins

Installed definitions: `darkstat`, `iperf3`, `ipv6-tunnel`, `lightsquid`, `lldpd`,
`nat64`, `netbird`, `zerotier`. Each declares `provides.menu` (path, label, permission)
and, in most cases, its own JSON endpoints on `Plugins` (e.g. `plugins/lldpd/status`,
`plugins/lldpd/neighbours`, `plugins/nat64/apply`, `plugins/zerotier/control`).

Gap: the *menu entries* and the *endpoints* exist, but there is no contract test proving
that every declared `path` resolves to a working page for every plugin, that uninstalled
plugins vanish from the nav, or that a dead plugin daemon renders a degraded state
rather than a permanent spinner.

---

## 3. Gap register

Severity: **S1** = correctness/security, **S2** = operator-visible defect, **S3** = polish.

| # | Sev | Gap | Evidence | Workstream |
|---|---|---|---|---|
| G1 | S1 | **WireGuard "sync" cannot work.** `rpc_vpn_wireguard_sync` runs `wg syncconf <iface> /etc/wireguard/<iface>.conf`, but nothing on the appliance ever writes that file — `WireguardCompiler::compileServerConfig()` output is only ever *downloaded*. | `system/micrond/micrond.py:1259-1264`; grep for `syncconf`/`/etc/wireguard` shows no writer; `app/Controllers/VpnWireguard.php:232` (download only) | W1 |
| G2 | S2 | **UI messages overstate what happened.** WireGuard enable/disable and peer toggle report the interface was brought up/torn down; with G1, nothing was applied. | `VpnWireguard::togglePeer()` message text | W1 + W3 |
| G3 | S1 | **Air-gapped captive portal depends on a CDN.** `@import` of Google Fonts in portal/captive views. An appliance with no internet renders a broken portal — the one page an unauthenticated guest must see. | `OfflineAssetsTest`; `app/Views/captive/print.php`, `app/Views/portal/success.php`, `app/Views/portal/auth.php` | W0 |
| G4 | S1 | **Strict-decoding guard breach.** Base64 decoded without `validate=True` in the daemon. | `StrictDecodingGuardTest`; `system/micrond/micrond.py` (~line 1323) | W0 |
| G5 | S2 | **View ⇄ controller contract drift.** The WireGuard Add/Edit Peer modal posted `peer_address` and a single `endpoint` string; the controller reads `allowed_ips` (deriving the address) and `endpoint_host`/`endpoint_port`. Fields the operator filled in were silently discarded. | `app/Views/vpn/wireguard.php` vs `VpnWireguard::buildEndpoint()/splitAllowedIps()` | W1 (this is the class of bug a contract test must catch) |
| G6 | S1 | **No method-level authorization at the RPC boundary.** `handle_request()` dispatches purely by name; any process in the `www-data` socket group can invoke any handler, including destructive ones. Capability checks exist for the plugin surface only. | `RpcHandler.handle_request()` (`micrond.py:769-788`); `PLUGIN_CATALOG` capability refusal (~`:2023`) | W1 |
| G7 | S2 | **Diagnostics toolkit is missing the tools engineers ask for** (nmap, telnet, mtr, whois, ss, TLS probe, iperf3-in-core). | `diag.*` inventory above | W4 |
| G8 | S2 | **CLI parity gaps.** `microctl` has no diagnostics, VPN, plugin, or route commands; `micron-menu` coverage unverified against the Web UI. | `bin/microctl` command list (`status`, `enable`, `disable`, `interface`, `firewall`, `backup`, `config`, `admin`, `help`) | W2 |
| G9 | S2 | **Plugin workspace depth unproven** — see §2.3. | — | W5 |
| G10 | S3 | **UI consistency debt.** Bespoke class systems (`wg-*`) mixed with ad-hoc inline styles on nearly every element; inconsistent empty/error states across modules. | `app/Views/vpn/wireguard.php` | W3 |
| G11 | S2 | **No parity matrix.** Nothing mechanically proves RPC ↔ UI ↔ CLI agreement, so drift (as in G5) is discovered by an operator, not by CI. | — | W1 + W6 |
| G12 | S3 | **Documentation drift.** Marks: several `.md` specs were edited without regenerating their `.html`. | `git status` on `docs/` | W7 |

---

## 4. Workstreams

### W0 — Baseline truth
**Deliverable:** `docs/MICRONOS_BASELINE_SNAPSHOT.md`; one entry-point test command;
every failure explained with evidence.
**Fix at cause:** G3 (self-host or remove the web font), G4 (`validate=True`).
**Exit gate:** a single command runs PHPUnit + pytest + shell suites; results reproduced
twice; no unexplained red.

### W1 — IPC/RPC ⇄ Web ⇄ CLI alignment
**Deliverables:** `docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md` (with a **generated** matrix);
contract tests wired into the suite; G1, G2, G5, G6, G11 closed.

1. Generate the inventory: daemon handlers, `app/` call sites, `client.py` calls, CLI
   calls, and `IpcClient::mockDispatch()` keys.
2. Reconcile: implement or delete every called-but-missing method; remove every
   implemented-but-unreachable method or document it as internal.
3. Make G1 real: the daemon materialises `/etc/wireguard/<iface>.conf` atomically from
   the same compiled content the UI offers for download, honouring the enabled/disabled
   peer set — with a test that writes the file and reads it back.
4. Add the method registry + capability classes (read-only / configure / destructive)
   and refuse destructive invocations from callers without that class. Document honestly
   that the socket group is today's authorization boundary.
5. Ship the contract test: route actions exist; permission keys exist in the catalog;
   RPC methods referenced by PHP exist in the daemon; plugin menu paths resolve; view
   form fields are read by the controller (G5's detector).
6. Adopt the **alignment rule**: a capability is done only when RPC + controller + route +
   permission + UI + CLI + tests + matrix row all exist.

### W2 — CLI completion
**Deliverables:** `microctl` subcommand set (diag, vpn, interface, route, dhcp, service,
plugin) each with `--help` and `--json`; matching `micron-menu` entries; new `spark`
commands including a single `micron:doctor` health report.
**Exit gate:** parity matrix shows CLI = UI for every operator-relevant row; each command
tested.

### W3 — Web UI revamp
**Deliverables:** page-by-page audit (before/after), a consolidated design-token pass,
information-architecture rework by task, and accessibility/performance targets.
**Continues** `docs/UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.md` — reconcile, do not replace.
**Specific, evidence-backed tasks:** kill inline-style drift (G10); make every message
truthful (G2); give every table loading/empty/error/permission states; confirm naming the
object on every destructive action; add a `Ctrl/⌘+K` command palette; responsive tables.
**Exit gate:** screenshot set (desktop + 390 px) per changed page; automated a11y check;
render budget measured and met.

### W4 — Network & security utility toolkit
**Deliverables:** see [MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md](MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md).
Core tools: telnet/nc, nmap (allow-listed flags only), mtr, whois/dig, arping, ss,
TLS probe, HTTP probe, iperf3.
**Exit gate:** each tool live, permissioned, audited, injection-tested, in UI + CLI, with
documentation.

### W5 — Plugin workspaces
**Deliverables:** see [MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md](MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md).
A functional page per installed plugin; verified nav behaviour; degraded-state handling;
lifecycle correctness; a completeness table across all eight plugins.
**Exit gate:** live install → configure → verify → remove walkthrough on a scratch VM.

### W6 — Regression, validation and proof
**Deliverables:** `docs/MICRONOS_VERIFICATION_REPORT.md` with commands, raw output,
exit statuses, screenshots, and an explicit list of what could not be verified.
**Tiers:** unit → feature/contract → integration (live socket, failure injection) →
installer/packaging → live appliance smoke.
**Mandatory per change:** permission-denied, invalid-input, daemon-unavailable, mock-mode,
happy path.
**Guardrails:** never weaken a test to go green; measure before/after for optimisations;
run a permission-matrix sweep (every route × every role) and a secrets-in-response scan.

### W7 — Documentation & release
**Deliverables:** every new spec registered once in `docs/build_docs.php`, generated to
HTML, linked from `docs/index.html`; `VERSION`/`system/os-release`/release notes in step;
`README.md` and `MICRONOS_MODULE_REFERENCE.md` updated for new controllers, namespaces,
permissions, commands and menus.

---

## 5. Milestones

| Milestone | Contents | Exit criterion |
|---|---|---|
| **M1 Truth** | W0 | One-command suite; baseline snapshot; G3/G4 fixed at cause |
| **M2 Alignment** | W1 | Matrix generated in CI; zero unexplained drift; G1/G2/G5/G6 closed |
| **M3 Toolkit** | W4 core | Each tool live, safe, in UI + CLI |
| **M4 Plugins** | W5 | All eight plugins ticked; lifecycle walkthrough |
| **M5 CLI** | W2 | CLI = UI across the matrix |
| **M6 UI** | W3 | Audits, screenshots, a11y and render budgets |
| **M7 Proof** | W6 + W7 | Verification report with evidence and named limitations |

M1 and M2 are prerequisites: without a trustworthy drift inventory, every later claim is
unverifiable.

---

## 6. Test matrix

| Tier | Command | Covers | Frequency |
|---|---|---|---|
| 1 Unit | `php vendor/bin/phpunit tests/app/Libraries` · `pytest tests/ -q -k unit` | Compilers, validators, libraries | Every change |
| 2 Feature / contract | `php vendor/bin/phpunit tests/app/Controllers` + parity/contract tests | Routes, filters, permissions, validation, DB, view⇄controller contract | Every change |
| 3 Integration | `pytest tests/test_micrond_*.py` against a live socket | Daemon handlers, framing, failure injection | Every daemon change |
| 4 Installer / packaging | `tests/installer/*.sh`, packaging suites, scripted ISO build | Installer, slots, healthcheck, image | Before release |
| 5 Live appliance | Scripted VM smoke matrix | Login, page render, new tools, plugin lifecycles | Per milestone |

**Exit-status discipline:** when piping through `tail`/`grep`, run under
`set -o pipefail` or capture `$?` — a filtered log is not a passing test.

---

## 7. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| New diagnostic tools become an injection vector | Critical | Argument arrays, allow-listed flags, no shell strings; injection matrix as a required test |
| nmap/telnet used as a pivot from the appliance | High | Explicit acknowledgement, source-interface choice, private-range policy, audit log, permission key |
| Destructive lifecycle actions (plugin remove, firewall reset) taken on production config | High | Confirmation naming the object, config-revision snapshot before apply, rollback path |
| W1 refactor breaks existing RPC contracts | Medium | Additive only; contract test locks existing method names |
| UI revamp churn invalidates live tests | Medium | Stabilise selectors via `data-testid`; refactor in page-sized batches with screenshots |
| Suite grows too slow to run per change | Medium | Tier split with a fast default; integration tier on demand/CI |

---

## 8. Definition of done

A capability is done when **all** of the following are true, evidenced:

1. RPC method implemented, validated, bounded, hostile-input tested.
2. Controller action with server-side validation and per-field errors.
3. Route registered with a justified permission key present in the catalog.
4. UI control that works, is consistent, states its outcome, and is permission-gated.
5. CLI command (where operator-relevant) with `--help` and `--json`.
6. Mock-mode response so the UI is developable offline.
7. Tests: unit, feature, integration, permission-denied, invalid-input, daemon-unavailable.
8. No secret material in any HTML/JSON payload (test-proved).
9. Audit-log entry for state-changing actions.
10. Documentation row in the parity matrix + module reference + spec section.
11. Live verification with captured evidence.

---

## 9. Verification report template

```text
# MicronOS Verification Report — <date> — <scope>

## Environment
Host / OS image / commit / VERSION / os-release BUILD_ID

## Suites
| Suite | Command | Result | Exit | Notes |
(verbatim counts; not "all good")

## Delivered
| Item | Path | Evidence (screenshot / output excerpt / test name) |

## Failed or unverified
| Item | Reason | Impact | Follow-up |

## Regression deltas
Before/after conformance, render timings, permission-matrix sweep

## Remaining limitations
Stated plainly. Never presented as a pass.
```

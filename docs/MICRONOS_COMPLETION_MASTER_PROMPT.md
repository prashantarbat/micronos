# MicronOS — Master Completion Prompt

> **How to use this document.** Everything between the `=== BEGIN PROMPT ===` and
> `=== END PROMPT ===` markers is a single, self-contained, paste-ready prompt.
> Hand it to a coding agent (or a human engineer) exactly as it stands. It carries
> its own context, constraints, method, acceptance criteria and reporting format, so
> it needs no additional briefing.

---

=== BEGIN PROMPT ===

# ROLE

You are a **Principal Linux Systems Engineer** with deep, hands-on mastery of all of
the following, and you are expected to work at that level:

* **Networking** — L3/L4 forwarding, nftables/iptables, routing tables, policy
  routing, VRF, NAT (SNAT/DNAT/NAT64), DHCP/Kea, DNS (Unbound, DNS64), PPPoE, SD-WAN,
  QoS, multicast, LACP bonding, bridges/VLANs, WireGuard/OpenVPN/IPsec, ZTNA.
* **Cyber security** — least privilege, RBAC design, capability bounding, input
  validation at every trust boundary, secrets handling, audit trails, supply-chain
  integrity (signed artefacts), threat modelling, and the discipline of proving a
  control actually holds rather than assuming it.
* **System administration** — Debian/apt, systemd (units, templates, drop-ins,
  watchdog, `sd_notify`), journald, live-build ISO construction, GRUB/slot A-B
  updates, filesystem and database resilience, appliance lifecycle.
* **Software engineering** — PHP 8.3+ and CodeIgniter 4 at production quality,
  Python 3.13 `asyncio`, JSON-RPC 2.0 protocol design, shell, SQL/SQLite, and
  test-driven verification of every change you make.
* **Diagnostics** — you know exactly which tool answers which question: `nmap` for
  reachability/port state, `telnet`/`openssl s_client` for application handshakes,
  `tcpdump` for the wire truth, `mtr` for path and loss, `conntrack` for state,
  `arping` for L2, `iperf3` for throughput.

You are also a **product-minded engineer**. You care about whether an operator can
actually finish a task at 03:00 during an outage, not about whether the code compiles.

---

# PROJECT CONTEXT (verified — treat as ground truth)

**MicronOS** (Micron Firewall OS) is an enterprise firewall / router / VPN appliance
built on **Debian 13 (trixie)**. It is a complete product with a web UI, a privileged
daemon, two console CLIs, a TUI installer, an ISO build pipeline, and a substantial
test suite.

## Layout

| Path | What it is |
|---|---|
| `micronos/app/` | CodeIgniter 4 application: `Controllers/`, `Libraries/`, `Models/`, `Filters/`, `Views/`, `Commands/`, `Config/` |
| `micronos/public/index.php` | Web front controller |
| `micronos/system/micrond/micrond.py` | The privileged daemon — **~9,000 lines, 140 JSON-RPC handlers** (`rpc_<namespace>_<name>`) |
| `micronos/system/micrond/client.py` | Python RPC client used by tooling |
| `micronos/bin/microctl` | Break-glass **console CLI** (VGA/Serial) — `status`, `enable`, `disable`, `interface set-lan`, `firewall reset`, `backup create|restore|verify`, `config rollback`, `admin reset-password` |
| `micronos/bin/micron-menu` | Interactive **TUI menu** for console/serial |
| `micronos/spark` + `micronos/app/Commands/` | 17 CI4 CLI commands (`acme:renew`, `backup:create`, `firewall:compile`, `plugin:manage`, `verify:system`, …) |
| `micronos/installer/` | Debian TUI installer, kickstart, slot A/B management, healthcheck |
| `micronos/packaging/` | `live-build` ISO construction |
| `micronos/tests/` | **142 PHPUnit files**, **16 pytest files**, **3 shell suites** |
| `micronos/docs/` | Specifications + `build_docs.php` (Markdown → styled HTML) |

## The IPC/RPC contract (already aligned — preserve it)

* Transport: **Unix Domain Socket** `AF_UNIX`, `SOCK_STREAM`, path **`/run/micron/micrond.sock`**.
* Framing: **4-byte big-endian `uint32` length prefix** + UTF-8 JSON payload.
* Protocol: **JSON-RPC 2.0** (`jsonrpc`, `id`, `method`, `params` → `result` | `error`).
* Strictness: both ends refuse `NaN`/`Infinity` and malformed JSON at the boundary
  (`strict_json_loads`, `json.dumps(..., allow_nan=False)`).
* PHP side: `app/Libraries/IpcClient.php` (`SOCK_PATH`, `TIMEOUT = 5`, `MAX_READ = 65536`,
  mock mode on Windows / missing socket).
* Dispatch: `RpcHandler.handle_request()` resolves `rpc_` + `method.replace('.', '_')`.
  Unknown method → `-32601`. Handler exception → `-32000`.
* Socket permissions: `0660`, group `www-data`.
* Daemon surface is grouped by namespace: `system` (10), `interface` (4), `network` (4),
  `firewall` (4), `diag` (18), `dhcp` (7), `pppoe` (5), `vpn` (8), `plugin` (32),
  `webfilter` (4), `squid` (4), `ztna` (3), `captive` (4), `sdwan` (3), `dns`/`dnsbl` (5),
  `bridge` (3), `bond` (3), `wireless` (2), `ssl` (3), `user` (5), `route`/`routing` (3),
  `multicast` (2), `ids`/`nat`/`connector`/`service` (1 each).

## Authorization model (preserve it)

* Global filters: `csrf` then `auth` (`app/Config/Filters.php`).
* Per-route: `['filter' => 'permission:<key>']` → `app/Filters/PermissionFilter.php`
  → `PermissionModel::userCan()` (session `user_permissions` cache, DB fallback when the
  permission catalog changed).
* Controllers must not invent their own authorization. A new page is a new route with
  a new (or reused) permission key, added to the catalog.

## Plugin model (preserve it)

* Definitions: `app/Plugins/definitions/<id>/plugin.json` (currently: `darkstat`,
  `iperf3`, `ipv6-tunnel`, `lightsquid`, `lldpd`, `nat64`, `netbird`, `zerotier`).
* Validated once at load by `app/Plugins/PluginManifest.php`: closed enums for `class`
  (A/B/C) and lifecycle `actions`; capability grammar; unknown keys refused.
* Lifecycle actions are a **closed enum** — `apt.install`, `apt.remove`,
  `system.enable|disable|start|stop`, `template.render`, `file.copy`, `dir.ensure`,
  `pki.issue`, `dns.setupstream`, `nft.set`. There is deliberately **no `shell.run`**.
* `provides.menu` entries (`path`, `label`, `permission`) are rendered by
  `app/Libraries/PluginNav.php` into the main layout, for **installed** plugins only.
* `Plugins::show/$1` serves `/plugins/<id>`; each plugin also has its own JSON endpoints
  (`plugins/iperf3/run`, `plugins/lldpd/status`, `plugins/lldpd/neighbours`, …).
* The daemon resolves every package/unit/capability against a fixed `PLUGIN_CATALOG`
  and refuses undeclared capabilities and protected packages/units.

---

# MISSION

Complete and consolidate MicronOS into a finished, coherent, defensible appliance:

1. **Finish what is unfinished.** No half-migrated module, no orphaned route, no
   controller calling an RPC that does not exist, no UI control that does nothing.
2. **Make the Web UI genuinely user-friendly** — consistent, predictable, keyboard- and
   screen-reader-usable, honest about state, and fast on a slow link.
3. **Guarantee Web UI ⇄ IPC/RPC ⇄ CLI alignment.** One source of truth per capability:
   if the UI can do it, the daemon can do it, a CLI can do it, and all three agree on
   names, parameters, validation and error reporting.
4. **Add the missing network/security utilities** (telnet, nmap, mtr, whois, dig, nc,
   `ss`, `arping`, `openssl s_client`, iperf3, …) as **first-class, safe, permissioned
   features** with their own menus, RPC methods, CLI commands and tests.
5. **Make installed plugins first-class workspaces** — each one reachable from the menu,
   with a real working page (status, configuration, logs, lifecycle), not a stub.
6. **Prove it.** Very high-coverage regression testing, live validation, and a written
   evidence trail. Claims without evidence are not acceptable.

---

# NON-NEGOTIABLE GUARDRAILS

1. **No command injection, ever.** User input never reaches a shell string. Use
   argument arrays / `escapeshellarg` equivalents, and prefer **allow-listed argument
   sets** over free-form flags. If a tool (e.g. `nmap`) has an option that can write
   files, run scripts or spawn a shell (`--script`, `-oN`, `-iL` with a path, `-oX`),
   that option is **not exposed**, or is exposed only as a server-side constant.
2. **Validate at the boundary, twice.** The controller validates and rejects; the daemon
   validates again. The daemon assumes the management plane is hostile.
3. **Least privilege.** New RPCs get the narrowest capability; new routes get the
   narrowest permission key. Never reuse a `*.manage` key for a read-only view.
4. **Secrets never reach the browser.** Private keys, pre-shared keys, passwords,
   tokens, and credential material are never rendered into page source or JSON handed
   to the client. Key fields are **blank-to-keep**.
5. **Destructive actions are explicit, confirmed and reversible.** Anything that drops a
   firewall table, kills a session, removes a package, deletes a VPN peer or rewrites the
   network configuration must be confirmed in the UI, audited server-side, and must
   degrade to the last known-good state on failure.
6. **Never break the appliance out of band.** A change must not be able to lock the
   operator out of the management interface. Validate the management path before applying.
7. **Preserve existing contracts.** Do not rename existing RPC methods, routes,
   database columns or permissions to make something neater. Add, deprecate, migrate.
8. **Respect the test suite.** Rising coverage is the goal; weakening an assertion,
   skipping a test, or suppressing a warning to make a run green is a failure and must
   be reported, not hidden.
9. **Exit-status fidelity.** When filtering output through `tail`/`grep`, preserve the
   real exit status (`set -o pipefail` or capture `$?` explicitly). A filtered log is not
   a passing test.
10. **Don't touch what you weren't asked to touch.** No opportunistic reformatting, no
    dependency bumps, no "while I was in there" refactors of unrelated modules.

---

# WORKSTREAM W0 — BASELINE TRUTH

**Goal:** know exactly where the project stands before changing anything, and leave the
baseline green.

1. Run every suite and record the **verbatim** result and exit status:
   * `php vendor/bin/phpunit`
   * `pytest tests/ -q`
   * every `tests/installer/*.sh` and `tests/*/*_test.sh`
   * `php spark verify:system`
2. For every failure: find the **cause**, not a way around it. Two known examples in the
   current tree (verify and fix, or prove pre-existing and unrelated with a `git stash`
   comparison):
   * `StrictDecodingGuardTest` — `system/micrond/micrond.py` decodes base64 without
     `validate=True`.
   * `OfflineAssetsTest` — `app/Views/captive/print.php`, `app/Views/portal/success.php`,
     `app/Views/portal/auth.php` pull Google Fonts with an `@import`, which breaks the
     captive portal on an appliance with no internet. **An air-gapped appliance must not
     depend on a CDN for its captive portal.** Self-host or drop the font.
3. Write the baseline into `docs/` as a dated snapshot: suite, counts, failures, cause
   of each, and disposition.
4. **Acceptance:** a single command runs every suite; the result is reproducible; every
   remaining failure has a written, evidence-backed explanation.

---

# WORKSTREAM W1 — IPC/RPC ⇄ WEB ⇄ CLI ALIGNMENT

**Goal:** make it impossible for the three surfaces to disagree.

1. **Build the inventory.** Extract, mechanically:
   * every `rpc_*` handler in `micrond.py`,
   * every `$this->ipc->call('…')` / `Services::ipc()->call('…')` method string in `app/`,
   * every method string in `system/micrond/client.py` and the CLIs,
   * every mock response key in `IpcClient::mockDispatch()`.
   Produce a generated matrix and commit it as documentation
   (see `docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md`).
2. **Close the drift.** For every method called but not implemented, either implement it
   or remove the call. Two concrete examples found during the audit — confirm and fix:
   * `vpn.wireguard.sync` runs `wg syncconf <iface> /etc/wireguard/<iface>.conf`, but
     **nothing writes that file** — the compiler output is only ever *downloaded*.
     The daemon must be able to materialise the interface config (write it atomically,
     with the enabled/disabled peer set the compiler computes) or the "sync" is theatre.
   * Verify `plugins/refresh` and `plugins/upgrade-all` route-shadowing: they must not be
     reachable as a plugin *id* by `Plugins::show/$1`.
3. **Add a contract test** that fails the build when:
   * the UI calls an RPC the daemon does not implement (excluding documented mock-only),
   * a `permission:` key used in `Routes.php` is absent from the permission catalog,
   * a route action class/method does not exist,
   * a view references a route that does not exist,
   * a plugin manifest declares a `provides.menu` path with no resolvable route.
4. **Harden the dispatch boundary.** Keep the existing JSON-RPC behaviour, and add:
   * an explicit method **registry** with a declared capability class per method
     (read-only / configure / destructive), so the daemon can refuse a destructive
     method from a caller that has not been granted that class;
   * uniform error objects (`code`, `message`, `hint`) with an operator-readable `hint`;
   * per-method timeouts and output caps (never let one call wedge the socket for all).
   Document the trust model honestly: the socket group is the current authorization
   boundary; say so, and describe what a future per-caller identity would look like.
5. **Parity rule going forward:** a new capability is not "done" until it exists as
   RPC method + controller action + route with permission + UI control + CLI command +
   tests + documentation row in the matrix.
6. **Acceptance:** matrix generated in CI, zero unexplained drift, contract tests green,
   every row of the matrix has RPC + UI + CLI, and the daemon-side write path for
   WireGuard exists (proved by a test that reads back the file it wrote).

---

# WORKSTREAM W2 — CLI COMPLETION

**Goal:** the console can do what the GUI can do, for the cases that matter at 03:00.

1. **`microctl`** (break-glass, no web): add, at minimum —
   * `diag ping|traceroute|dns|port|scan|mtr|whois|tcpdump` (bounded, with `--json`),
   * `vpn wireguard status|peers|sync`, `vpn ipsec status`,
   * `interface list|show <if>, `route show`,
   * `dhcp leases|status`,
   * `service status|restart <unit>` (allow-listed units only),
   * `plugin list|status|enable|disable`.
   Every subcommand: `--help`, non-zero exit on failure, machine-readable `--json`,
   never edit config by hand (call the RPC).
2. **`micron-menu`** (TUI): mirror the same capabilities, with a consistent
   back/quit contract, and no action that can lock out the console.
3. **`spark`**: add the missing operator commands (e.g. `micron:diag`, `micron:doctor`
   — a single "check everything" health report), and make `verify:system` cover the new
   surfaces.
4. **Acceptance:** every new command has a test, `--help`, `--json`, and a row in the
   parity matrix proving it reaches the same RPC as the Web UI.

---

# WORKSTREAM W3 — WEB UI REVAMP

**Goal:** an operator can complete any task without guessing.

1. **Read `docs/UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.md` first** and continue it —
   do not restart it or contradict it. Reconcile it with reality and mark what is done.
2. **Information architecture.** Group nav by task, not by module: Dashboard /
   Interfaces / Firewall / Routing / Services / VPN / Security / Diagnostics /
   System / Plugins. Collapse what is unused; promote what is frequent. Every page
   reachable in ≤2 clicks; breadcrumbs everywhere; a global search/command palette
   (`Ctrl/⌘+K`) that jumps to any page and any object.
3. **Design system.** One set of tokens (already present as CSS variables) applied
   consistently: spacing scale, typography scale, borders, focus rings, badges, tables,
   empty states, skeletons, toasts. Delete one-off inline styles that fight the tokens.
   *(Example of the inconsistency to hunt: `app/Views/vpn/wireguard.php` still mixes
   bespoke `.wg-*` classes with ad-hoc inline `style="…"` on nearly every element.)*
4. **Honest state.** Every table: loading, empty, error, and "you lack permission"
   states. Every destructive button: confirmation naming the exact object. Every async
   action: progress and a definite success/failure outcome. **No silently dead buttons
   and no button that lies about what happened** — the WireGuard toggle messages claim
   the live interface was brought up/torn down; make the message match what the daemon
   actually did, or change the daemon.
5. **Forms.** Inline validation with specific messages, server-side validation echoed
   back per-field (not one concatenated string), sensible defaults, keyboard submit,
   tab order, `autocomplete`, and no field that the backend ignores.
6. **Accessibility & performance.** WCAG 2.2 AA contrast, labels tied to inputs,
   `aria-live` for async results, full keyboard operation, `prefers-reduced-motion`
   respected; target < 200 ms server render for list pages, virtualise or paginate
   long tables (conntrack states, logs), and never block the request on a slow RPC.
7. **Mobile.** The appliance is often configured from a phone in a rack room: every
   table must have a usable narrow layout.
8. **Acceptance:** a written page-by-page audit before/after; a screenshot set for each
   changed page (desktop + 390 px); an automated accessibility check; zero inline-style
   regressions in the touched views; and the UI test suite extended to cover the new
   components.

---

# WORKSTREAM W4 — NETWORK & SECURITY UTILITY TOOLKIT

**Goal:** the Diagnostics area becomes the toolbox a network engineer expects.

Implement as **core features** (not plugins) unless the tool is heavy or optional:

| Tool | Operator question it answers | Notes |
|---|---|---|
| **telnet / nc** | "Is that TCP service actually accepting and speaking?" | Banner + timeout; never an interactive shell; no `-c`/`-e` |
| **nmap** | "Which ports are open on that host/segment?" | Allow-list flags only (`-sT -sV -Pn -p`, bounded); **no `--script`, no file output**; cap targets and ports; run as a job with progress |
| **mtr** | "Where exactly is the loss/latency?" | Report mode, bounded cycles |
| **whois / dig / drill** | "Who owns this, and what does DNS really say?" | Server-selectable dig, all record types |
| **arping / ndisc6** | "Is the neighbour alive at L2?" | Interface-scoped |
| **ss / netstat / socket list** | "Who is listening / what is this process holding?" | Read-only |
| **openssl s_client / tls probe** | "What certificate and cipher is that endpoint offering?" | Chain, expiry, SNI, protocol versions |
| **iperf3** | "How much throughput do I actually get?" | Already a plugin — promote it into Diagnostics *and* keep the plugin |
| **curl / HTTP probe** | "What does the API return, with what headers?" | Header/method/body control, no arbitrary file paths |

For each tool, implement the **full stack in one pass**:

1. **RPC** in `micrond.py`: `diag.<tool>` (+ `diag.<tool>_start|_poll|_stop` for long
   jobs), with strict parameter validation, argument-array execution, hard timeouts,
   output caps, and a structured result (`{success, status, output, parsed, warnings}`).
2. **Controller** action in `app/Controllers/Diagnostics.php` (or a new
   `DiagnosticsScan.php` if it grows), reusing the shared validation helpers that
   already exist there (`targetInputError`, `sourceInputError`, `ipLiteral`,
   `isHostname`) — do not create a second validation dialect.
3. **Route** in `app/Config/Routes.php` under the `diag` group with a **new permission
   key** (e.g. `diag.scan`, `diag.telnet`) added to the catalog and to the role editor.
4. **UI**: a tab on the Diagnostics page with its own form, parameter help, live output,
   copy/download, cancel, and an explicit "this tool is intrusive — you are auditing an
   external host" acknowledgement for scanning.
5. **CLI**: `microctl diag …` and a `micron-menu` entry.
6. **Mock mode**: a plausible simulated output in `IpcClient::mockDispatch()` so the UI
   is developable on Windows and the feature is demoable.
7. **Tests**: pytest for the daemon (validation rejections, injection attempts, output
   parsing), PHPUnit for the controller (permission enforcement, invalid input, mock
   happy path), and a shell/CLI test.
8. **Docs**: a row in the toolkit spec + the parity matrix.

**Security requirements that must be tested, not asserted:** shell-metacharacter
injection via every text field; oversized input; unbounded target lists; extra
arguments; option injection (`--script`, `-oN`, `-iL`, `-c`, `-e`, leading `-`);
private/loopback scanning policy; and audit-log entries for every invocation
(who, what, when, from where).

**Acceptance:** every tool above works end-to-end on the appliance, is permissioned,
audited, injected-proof under test, reachable from UI + CLI, and documented.

---

# WORKSTREAM W5 — PLUGIN WORKSPACES

**Goal:** an installed plugin is a real feature, not a card in a list.

1. **Audit all eight plugins** (`darkstat`, `iperf3`, `ipv6-tunnel`, `lightsquid`,
   `lldpd`, `nat64`, `netbird`, `zerotier`): for each, confirm
   `install → enable → configure → status → logs → disable → remove` all work from the UI,
   that `/plugins/<id>` renders a **functional page** (not the generic show stub) when
   the plugin declares its own surface, and that every `provides.menu` entry resolves.
2. **Give every plugin a workspace page** with, where applicable: live status
   (state, uptime, version), configuration form (validated, persisted, applied via RPC),
   a log viewer, a metrics/neighbour/report view, and lifecycle controls with
   confirmation. Use one shared component library so all eight look and behave alike.
3. **Make the plugin menu real.** Installed plugins appear in the nav (already wired via
   `PluginNav::entries()`); ensure: uninstalled plugins do not appear; the entry's
   permission is enforced by the route as well as hidden in the nav (never rely on
   hiding alone); a plugin that fails to install leaves no phantom menu entry; and a
   plugin with a dead daemon shows an explicit **degraded** state, not a spinner forever.
4. **Prefer core promotion for generic tools.** `iperf3` and `lldpd` are general-purpose;
   a plugin is the right wrapper for packaging/dependency management, but the *feature*
   should also exist as a first-class diagnostics/networking page when it is broadly
   useful. State the decision and rationale per plugin in the docs.
5. **Lifecycle correctness:** a plugin whose `requires.binaries` are missing must refuse
   to enable with the missing dependency named; removal must clean its config, its menu
   entry and its systemd drop-ins; a failed upgrade must roll back.
6. **Acceptance:** a table in the plugin workspace spec with one row per plugin ×
   (page, menu, status, config, logs, lifecycle, tests, docs) all ticked, plus a live
   walkthrough of at least install → configure → verify → remove on a scratch VM.

---

# WORKSTREAM W6 — REGRESSION, VALIDATION AND PROOF

**Goal:** trustworthy evidence for every claim.

1. **Test tiers, all runnable from one entry point:**
   * Tier 1 — unit: PHPUnit `tests/app/Libraries`, pytest unit groups.
   * Tier 2 — feature/contract: PHPUnit `tests/app/Controllers` (route + filter +
     validation + DB), the new RPC contract test, the parity matrix test.
   * Tier 3 — integration: pytest against a live `micrond` on a unix socket (the suite
     already does this), including failure injection (daemon down, socket refused,
     malformed response, timeout, oversized response).
   * Tier 4 — installer / packaging: the shell suites and a scripted ISO build check.
   * Tier 5 — live appliance: deploy to a VM, run a scripted smoke matrix (login,
     page-render, every new tool, every plugin lifecycle) and capture it.
2. **Mandatory regression cases for every change:** permission-denied path, invalid
   input path, daemon-unavailable path, mock-mode path, and the happy path — for both
   the Web UI and the CLI.
3. **Never** mark a suite green by relaxing it. If a test must change, explain why the
   *behaviour* changed and prove the new behaviour.
4. **Performance guardrails:** page render budget, RPC round-trip budget, and a
   long-table budget; record before/after numbers for anything you optimise.
5. **Security validation:** run the injection matrix, the CSRF checks, the
   permission-matrix sweep (every route × every role), and a secrets-in-response scan
   (assert no key material appears in any HTML or JSON payload).
6. **Acceptance:** a dated verification report in `docs/` with commands, raw output
   excerpts, exit statuses, screenshots, and an explicit list of what could **not** be
   verified and why. Report limitations as limitations.

---

# WORKSTREAM W7 — DOCUMENTATION & RELEASE

1. Follow the existing docs convention: `docs/MICRONOS_*.md`, declared **once** in the
   `$docs` map in `docs/build_docs.php`, generated to styled HTML by
   `php docs/build_docs.php`, and linked from `docs/index.html`.
2. Every workstream produces or updates its specification, and the **parity matrix** is
   regenerated rather than hand-edited.
3. Keep `VERSION`, `system/os-release` (`PRETTY_NAME`, `VERSION_ID`, `BUILD_ID`) and the
   release notes consistent — they are read by the UI, the updater and the ISO.
4. Update `README.md`, `MICRONOS_MODULE_REFERENCE.md` and the architecture document for
   anything structural you add (new controllers, new RPC namespaces, new permissions,
   new CLI commands, new menus).
5. **Acceptance:** `php docs/build_docs.php` runs clean, every new document is reachable
   from the hub, and no document describes behaviour that does not exist.

---

# METHOD — HOW TO WORK

For every unit of work, in order:

1. **Read before writing.** Find the existing pattern for this kind of change and follow
   it. Use `code_search`/`grep` first; never invent a second convention.
2. **State the acceptance criteria** before implementing: exact paths, route names, RPC
   method names, permission keys, request/response shapes, CLI invocations, and any
   performance or safety constraint.
3. **Plan it** as an ordered todo list, and update it as you finish each step.
4. **Implement minimally** — the smallest change that satisfies the criteria, matching
   surrounding style, with a comment explaining *why* where the reasoning is not obvious.
5. **Test it** at the right tier, plus the five mandatory regression cases.
6. **Verify the artefact the user will touch** — load the page, run the command, call the
   RPC. A green build is not proof of working behaviour.
7. **Report honestly**: what you verified, the exact command and result, what failed,
   and what remains.

**After every edit:** re-run the affected checks. Earlier green results do not cover
later changes. If you filter output, keep the real exit status.

**When a check fails, fix the cause.** Never skip, weaken, or suppress.

---

# SEQUENCING

| Milestone | Contents | Exit criterion |
|---|---|---|
| **M1 — Truth** | W0 complete; baseline snapshot; contract inventory generated | Every suite runs from one command; drift matrix exists |
| **M2 — Alignment** | W1 complete; drift closed; contract tests in CI | Zero unexplained drift; WireGuard sync writes a real config |
| **M3 — Toolkit** | W4 core tools (telnet, nmap, mtr, whois/dig, s_client, tcpdump) | Each tool live, permissioned, injection-proof, in UI + CLI |
| **M4 — Plugins** | W5 complete | Table of 8 plugins fully ticked; live lifecycle walkthrough |
| **M5 — CLI** | W2 complete | Parity matrix shows CLI = UI for every row |
| **M6 — UI** | W3 complete | Page-by-page audit + screenshots + a11y check |
| **M7 — Proof** | W6 + W7 complete | Verification report with evidence and named limitations |

Work M1→M2 first: everything else depends on the drift inventory being trustworthy.

---

# DEFINITION OF DONE (apply to every single feature)

* [ ] RPC method implemented, validated, bounded, unit-tested (happy + hostile input).
* [ ] Controller action with server-side validation and per-field errors.
* [ ] Route registered with a **new or justified** permission key in the catalog.
* [ ] UI control that works, looks consistent, states what it did, and cannot be
      triggered without permission.
* [ ] CLI command (where operator-relevant) with `--help` and `--json`.
* [ ] Mock-mode response so the UI is developable offline.
* [ ] Tests: unit, feature, integration, permission-denied, invalid-input,
      daemon-unavailable.
* [ ] Secrets do not leak into HTML/JSON (proved by test).
* [ ] Audit-log entry for state-changing actions.
* [ ] Documentation: module reference row + parity matrix row + spec section.
* [ ] Live verification with captured evidence.

---

# REPORTING FORMAT

End every work session with:

1. **What changed** — files, with a one-line reason each.
2. **Verification** — exact commands, observed results, exit statuses.
3. **Failed or unverified** — and why, without spin.
4. **Risk / follow-up** — what is now more fragile, what you deferred, what you found
   that the brief did not mention.
5. **Next** — the single next best action.

---

# FIRST ACTIONS

1. Read `README.md`, `docs/MICRON_FIREWALL_OS_ARCHITECTURE.md`,
   `docs/MICRONOS_MODULE_REFERENCE.md`, `docs/UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.md`,
   `docs/MICRONOS_ADVANCED_ROADMAP_SPECIFICATION.md`.
2. Run every suite; write `docs/MICRONOS_BASELINE_SNAPSHOT.md`.
3. Generate the RPC/UI/CLI parity matrix and start `docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md`.
4. Fix the two known red tests **at their cause**.
5. Then begin M2.

Report back with the baseline snapshot and the drift list before starting M2.

=== END PROMPT ===

---

## Companion documents

This prompt is the *instruction*. The programme is documented across:

| Document | Role |
|---|---|
| [MICRONOS_COMPLETION_PROGRAMME.md](MICRONOS_COMPLETION_PROGRAMME.md) | Audited current state, gap register, workstreams, gates, test matrix, risks |
| [MICRONOS_IPC_RPC_CLI_CONTRACT.md](MICRONOS_IPC_RPC_CLI_CONTRACT.md) | The wire contract and the parity rules that keep UI, RPC and CLI aligned |
| [MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md](MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md) | telnet/nmap/mtr/whois/tls/iperf3 etc. — safety model, RBAC, API, tests |
| [MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md](MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md) | Making every installed plugin a working menu + page |

Build the HTML editions with:

```bash
php docs/build_docs.php
```

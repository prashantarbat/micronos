# MicronOS — Plugin Workspace Specification

**Document type:** Feature specification
**Scope:** making every installed plugin a first-class, working destination under the
Plugins menu — status, configuration, logs, lifecycle — with enforced menu and permission
semantics.
**Companion:** [MICRONOS_COMPLETION_PROGRAMME.md](MICRONOS_COMPLETION_PROGRAMME.md) §4 W5,
[MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.md](MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.md)
(the normative extension/security model — this document does not replace it).

---

## 1. Problem

The plugin *engine* is strong: signed first-party definitions under
`app/Plugins/definitions/<id>/plugin.json`, validated by `PluginManifest` with closed
enums (no `shell.run` ever), a capability grammar, a fixed daemon-side `PLUGIN_CATALOG`
that refuses undeclared capabilities and protected packages/units, and `provides.menu`
entries rendered into the navigation by `PluginNav::entries()`.

What is missing is the last mile: an installed plugin must behave like a **built-in
feature**. Today an operator can install `lldpd` and get a nav entry — but whether
`/plugins/lldpd` is a working page, whether the daemon's state is visible, whether the
configuration can be applied, and whether removal cleans up, is not proven for all eight
plugins.

---

## 2. Installed plugins (audit baseline)

| Plugin | Class | Provides service | Menu label (declared) | Own JSON endpoints (declared/implemented) |
|---|---|---|---|---|
| `darkstat` | B | `darkstat` | Traffic Analyser | `plugins/darkstat/status`, `plugins/darkstat/apply` |
| `iperf3` | B | `iperf3` | iperf3 Speed Test | `plugins/iperf3/run` |
| `ipv6-tunnel` | B | 6in4 tunnel | IPv6 Tunnel (6in4) | `plugins/ipv6-tunnel/status`, `/apply`, `/update` |
| `lightsquid` | B | LightSquid reporter | Web Usage Reports | `plugins/lightsquid/status`, `/apply`, `/refresh`, `/report` |
| `lldpd` | B | `lldpd` | LLDP Neighbours | `plugins/lldpd/status`, `/neighbours`, `/apply` |
| `nat64` | B | NAT64/DNS64 | NAT64 / DNS64 | `plugins/nat64/status`, `/apply` |
| `netbird` | B | NetBird overlay | NetBird Overlay | `plugins/netbird/status`, `/control` |
| `zerotier` | B | ZeroTier overlay | ZeroTier Networks | `plugins/zerotier/status`, `/control` |

Each manifest declares `requires.packages`, `requires.binaries`, `provides.services`,
`provides.menu`, `provides.status_source` and a `lifecycle` list of closed-enum actions.

---

## 3. Required workspace anatomy

Every installed plugin page must have these regions — same order, same components, same
behaviour across all eight:

1. **Header** — plugin name, vendor, version, appliance-compatibility range, and a
   **state chip**: `Running` / `Stopped` / `Degraded` / `Not installed` / `Missing
   dependency`.
2. **Lifecycle controls** — install, enable, disable, start, stop, restart, remove.
   Each is permission-gated, confirmed when destructive, and reports the daemon's
   real `status` back (never an optimistic message).
3. **Status panel** — state, uptime, version, process/listening-port summary, last
   change, from the declared `status_source` (`rpc:plugin.status` etc.). Polls only while
   visible; stops polling on a permanent failure and shows the error.
4. **Configuration** — a form whose fields map 1:1 to the plugin's own apply RPC
   parameters. Server-side validation with per-field errors; a **config revision
   snapshot before apply**; "Apply" is idempotent and reversible.
5. **Operational views** — the plugin's reason for existing: the LLDP neighbour table,
   the LightSquid report, the iperf3 run console, the darkstat traffic graph, the
   ZeroTier/NetBird network list, the NAT64 mapping table, the 6in4 tunnel state.
6. **Logs** — the plugin's units via the existing `plugins/daemons/(:segment)/logs`
   pattern, with follow/tail and level filtering.
7. **Dependencies** — `requires.packages` ✓/✗, `requires.binaries` ✓/✗ with the resolved
   path, `requires.kernel_modules` ✓/✗. A missing dependency is named, not summarised.
8. **Capabilities** — the manifest's declared capabilities, displayed read-only, so an
   operator can see exactly what the plugin declared (and therefore what the daemon will
   permit).

**Build this once as shared components** (a plugin workspace partial + a small JS module)
and have all plugins use it. Eight bespoke pages will drift within one release.

---

## 4. Menu semantics (normative)

1. A plugin appears in the navigation **only when installed** (already implemented via
   `PluginNav::entries()`), and only when the session holds the entry's declared
   `permission`.
2. **Hiding is not enforcement.** The route behind the entry must carry the same
   permission filter, so a direct URL is refused too. A test asserts this.
3. The declared `path` must resolve to a real page. A contract test asserts every
   installed plugin's menu path resolves; a declared-but-missing path is a build failure.
4. Placement: plugin entries live under the **Plugins** group in the nav, grouped by
   function (Network overlay, Traffic analysis, Reporting, Connectivity) rather than
   alphabetically, and never above core features.
5. A plugin whose daemon is dead renders **Degraded** with the journal excerpt that
   explains it — never an infinite spinner and never a green "Running".
6. Installing, upgrading or removing a plugin must not leave a stale menu entry, a stale
   permission, or a stale systemd drop-in. Removal cleans: units, drop-ins, config
   (`/etc/…`), state (`/var/lib/micron/plugins/<id>`), and the nav entry.
7. `plugins/(:segment)` must not shadow literal routes (`plugins/refresh`,
   `plugins/upgrade-all`, `plugins/repositories`, `plugins/updates`). Literal routes are
   registered first; a test asserts the precedence (this is the class of bug already seen
   with `plugins/refresh` and `plugins/upgrade-all`).

---

## 5. Lifecycle correctness (normative)

| Condition | Required behaviour |
|---|---|
| `requires.binaries` missing | Refuse to enable; name each missing binary and the package that provides it |
| `requires.packages` unavailable in configured repos | Refuse with the repository name and the refresh action to try |
| Install succeeds, `system.enable` fails | Roll back the install (or leave the state explicitly `Installed, not enabled`) and surface the unit's error |
| Upgrade fails | Roll back to the previous package version; keep the previous config revision |
| Apply fails validation | Change nothing; return per-field errors |
| Apply fails at the daemon | Restore the previous config revision and report which step failed |
| Remove with the daemon running | Stop, disable, remove, verify the unit is gone; report leftovers if any |
| Plugin declares a protected package/unit | Refused by `PLUGIN_CATALOG` before any action; the UI explains why |

---

## 6. Reuse rather than reinvent

| Need | Use |
|---|---|
| Status polling, start/stop/restart, log fetch | the existing `plugins/daemons/status`, `plugins/daemons/(:segment)/control`, `plugins/daemons/(:segment)/logs` pattern |
| Lifecycle | `Plugins::install/remove/upgrade` + `PLUGIN_CATALOG` |
| Menu | `PluginNav::entries()` + `plugins/(:segment)` |
| Config revisions | the existing config-revision engine (same as firewall/interface applies) |
| Daemon access | `IpcClient` only — never shell out from PHP |
| Mock mode | `IpcClient::mockDispatch()` entries per plugin endpoint |

---

## 7. Evidence table (to be completed during W5)

One row per plugin; every cell proven by a test or a captured live walkthrough.

| Plugin | Page | Menu | Status | Config | Oper. view | Logs | Deps | Lifecycle | Tests | Docs |
|---|---|---|---|---|---|---|---|---|---|---|
| darkstat | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| iperf3 | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| ipv6-tunnel | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| lightsquid | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| lldpd | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| nat64 | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| netbird | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| zerotier | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |

---

## 8. Core promotion decision

Some "plugins" are really general-purpose diagnostics or networking features that happen
to need a package. Keep the **plugin** as the packaging/lifecycle wrapper, but expose the
**feature** as a core page when an operator would look for it outside the Plugins menu:

| Plugin | Core page to add | Rationale |
|---|---|---|
| `iperf3` | Diagnostics → Throughput | Throughput testing is a first-class diagnostic (see the toolkit spec) |
| `lldpd` | Interfaces → Neighbours (read-only view) | Neighbours belong where the interfaces are |
| `nat64` | Routing → NAT64 (read-only status) | It is a routing feature; the plugin supplies the daemon |
| `zerotier` / `netbird` | VPN → Overlays (status + control) | Operators look for overlays under VPN, not under Plugins |
| `darkstat` / `lightsquid` | stay under Plugins | Reporting surfaces are genuinely plugin-shaped |
| `ipv6-tunnel` | Interfaces → 6in4 tunnel status | It is an interface concern |

Record the decision and its rationale here as each is implemented; a core page must not
duplicate the plugin's logic — both must call the same RPC.

### 8.1 Implemented (2026-10-14): functional menu placement

Rather than a second, duplicate page per plugin, each **installed** plugin's existing page
(`/plugins/<id>`, same controller, same RPCs) is now also linked from the functional menu
an operator would look in. The map is `App\Libraries\PluginNav::SECTIONS`:

| Plugin | Appears under | Rationale |
|---|---|---|
| `lldpd`, `ipv6-tunnel` | Network | neighbours and a 6in4 tunnel are interface concerns |
| `nat64` | Routing & Clustering | a routing feature |
| `zerotier`, `netbird` | VPN | overlays are looked for under VPN |
| `iperf3` | Administration | throughput testing sits with diagnostics |
| `darkstat`, `lightsquid` | Security | traffic accounting and proxy usage reports |

Semantics, all unchanged from §4: an entry appears only when the plugin is installed
and the session holds the entry's permission; the route behind it carries the same
permission filter. `NavGroups::owns()` resolves `/plugins/<id>` to the functional section,
so that section opens and is highlighted on the plugin's page. The Plugins group still
lists every installed plugin for installation, configuration and troubleshooting, and the
links appear in both the sidebar and the top-bar layouts. The §7 evidence table has not
been filled in by this pass.

---

## 9. Tests

1. **Menu/route contract** — for each installed plugin: menu path resolves; the route's
   permission matches the declared permission; a session without the permission is
   refused on direct URL access.
2. **Uninstalled plugins contribute nothing** — no nav entry, no phantom page.
3. **Literal-route precedence** — `/plugins/refresh`, `/plugins/updates`,
   `/plugins/repositories`, `/plugins/upgrade-all` resolve to their own actions, not to
   `Plugins::show`.
4. **Degraded state** — with the unit stopped/masked, the page shows Degraded with the
   journal excerpt, and polling stops rather than spinning.
5. **Lifecycle** — one pytest/PHPUnit case per closed-enum action, including the refusal
   paths in §5.
6. **Capability transparency** — the page lists exactly the manifest's capabilities; a
   capability the daemon would refuse is never offered.
7. **Removal cleanliness** — after remove, assert no unit, drop-in, config file or nav
   entry remains.
8. **Live walkthrough** — install → configure → verify status → remove on a scratch VM,
   captured in the verification report.

---

## 10. Rollout

| Phase | Contents | Exit criterion |
|---|---|---|
| **P1** | Shared workspace components + complete the two plugins with the deepest existing surface (`lldpd`, `zerotier`) | Both fully ticked in §7; pattern proven |
| **P2** | `nat64`, `netbird`, `iperf3`, `ipv6-tunnel` | Six ticked; core-promotion pages for iperf3/lldpd in place |
| **P3** | `darkstat`, `lightsquid` | All eight ticked; live walkthrough captured |

Each phase ends with the §7 table updated and the contract tests green.

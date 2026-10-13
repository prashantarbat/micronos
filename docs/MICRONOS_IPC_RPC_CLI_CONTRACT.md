# MicronOS — IPC / RPC / CLI Alignment Contract

**Document type:** Interface contract (normative)
**Status:** describes the contract as implemented, plus the rules and tests that keep it
from drifting.
**Companion:** [MICRONOS_COMPLETION_PROGRAMME.md](MICRONOS_COMPLETION_PROGRAMME.md) §4 W1.

> **Why this document exists.** MicronOS has three ways to touch the same capability:
> the Web UI (PHP → `IpcClient`), the console CLIs (`microctl`, `micron-menu`, `spark`),
> and the daemon itself (`micrond`). When those three drift, an operator gets a form that
> discards their input, a toggle that reports success while nothing happened, or a CLI
> that fails where the UI succeeds. The contract below — plus the contract tests in §9 —
> makes that drift a build failure instead of a support ticket.

---

## 1. Transport

| Property | Value |
|---|---|
| Socket type | `AF_UNIX`, `SOCK_STREAM` |
| Path | `/run/micron/micrond.sock` (daemon `SOCKET_PATH`; client `IpcClient::SOCK_PATH`) |
| Permissions | `0660`, group `www-data` (daemon `chown`s the socket at startup) |
| Framing | **4-byte big-endian `uint32` length** followed by exactly that many UTF-8 bytes of JSON |
| Protocol | **JSON-RPC 2.0** |
| Client timeout | `IpcClient::TIMEOUT = 5` s connect/read/write |
| Client read cap | `IpcClient::MAX_READ = 65536` bytes |
| Strictness | daemon: `strict_json_loads` (rejects `NaN`/`Infinity`/malformed); responses encoded with `json.dumps(..., allow_nan=False)` |

Request:

```json
{ "jsonrpc": "2.0", "id": 1, "method": "interface.list", "params": {} }
```

Response (success / failure):

```json
{ "jsonrpc": "2.0", "id": 1, "result": { "success": true, "status": "ok" } }
{ "jsonrpc": "2.0", "id": 1, "error": { "code": -32601, "message": "Method not found: x.y" } }
```

The PHP client returns `{ result: mixed, error: mixed|null }` and **never throws** on a
daemon-side error — callers must inspect `error`. `callOrFail()` is the throwing variant.

---

## 2. Daemon dispatch

`RpcHandler.handle_request()` (`system/micrond/micrond.py`):

1. `method` missing → `-32600`.
2. Resolve `getattr(self, "rpc_" + method.replace(".", "_"))`.
3. Unknown → `-32601 Method not found: <method>`.
4. Handler raises → `-32000` with the exception message.

**Consequences you must respect when adding a method:**

* The method name is the handler name with dots → underscores. `diag.telnet` →
  `rpc_diag_telnet`. Nothing else registers it.
* There is **no** parameter schema validation layer: each handler validates its own
  input. A new handler that trusts its `params` is a defect.
* There is **no** per-method authorization at dispatch today (see §7). The plugin surface
  is the exception: `PLUGIN_CATALOG` resolves packages/units and refuses undeclared
  capabilities and protected packages/units.

### Result convention

Handlers return a dict that, for anything carrying an outcome, includes:

| Key | Meaning |
|---|---|
| `success` | bool — did the action succeed |
| `status` | `ok` \| `applied` \| `error` \| `blocked` \| `degraded` |
| `message` | operator-readable sentence on failure/refusal |
| `warnings` | list of strings — non-fatal issues the operator must see |
| domain payload | e.g. `interfaces`, `peers`, `neighbours`, `output`, `parsed` |

Do not return bare strings for status-bearing calls; the UI has no way to render them
consistently.

---

## 3. PHP client surface

`app/Libraries/IpcClient.php`

| Member | Notes |
|---|---|
| `call(string $method, array $params = []): array` | returns `['result' => …, 'error' => …]` |
| `callOrFail(...)` | throws `RuntimeException` on RPC or socket error |
| typed helpers | `getInterfaces()`, `getFirewallRules()`, `getWireguardPeers()`, `getIpsecStatus()`, `getSystemInfo()`, `applyNetworkConfig()`, `applyFirewallRules()`, `user*()`, `bond*()`, `bridge*()`, `wireless*()` |
| `isMock()` | true on Windows or when the socket is absent |
| `mockDispatch()` | canned responses for development; **must be updated when a new method is added** or the UI is undevelopable offline |

Controllers resolve it with `\Config\Services::ipc()` (a shared service) — tests inject
`Tests\Support\Libraries\FakeIpcClient` via `Services::injectMock('ipc', …)`.

**Sockets are not the only place a controller may reach the daemon.** Anything that
shells out from PHP instead of calling an RPC is a contract violation: it bypasses the
daemon's validation, its audit trail and its privilege model.

---

## 4. Method catalogue

Namespace counts as of 2026-10-13 (140 methods):

| Namespace | n | Representative methods |
|---|---|---|
| `plugin` | 32 | `plugin.catalog`, `plugin.status`, `plugin.install`, `plugin.*.apply/status` per plugin |
| `diag` | 18 | `diag.ping`, `diag.traceroute`, `diag.dns_lookup`, `diag.tcpdump`, `diag.port_probe`, `diag.dns_benchmark`, `diag.capture_*`, `diag.stream_*`, `diag.route_lookup`, `diag.routing_table`, `diag.ndp_table`, `diag.conntrack_states`, `diag.firewall_logs` |
| `system` | 10 | `system.ping`, `system.info`, `system.service`, `system.commit`, `system.rollback`, … |
| `vpn` | 8 | `vpn.wireguard.{sync,status,genkey,pubkey,pskgen}`, ipsec counterparts |
| `dhcp` | 7 | leases, status, relay |
| `user` | 5 | list/create/update/delete/sync |
| `pppoe` | 5 | server, users, redial |
| `webfilter`, `squid` | 4 each | status, cache, access log, reload |
| `network`, `interface`, `firewall`, `captive` | 4 each | list/apply |
| `ztna`, `ssl`, `sdwan`, `dns`, `bridge`, `bond` | 3 each | status/apply |
| `wireless`, `route`, `multicast`, `dnsbl` | 2 each | status/apply |
| `service`, `routing`, `nat`, `ids`, `connector` | 1 each | — |

**Regenerate, never hand-edit.** §9 defines the generator/contract test; the table above
is a human-readable snapshot and will be replaced by generated output.

### Known drift (must be closed — Programme G1/G2)

`vpn.wireguard.sync` executes:

```python
config_path = f"/etc/wireguard/{interface}.conf"
subprocess.run(["wg", "syncconf", interface, config_path], …)
```

but **no component writes that file**. `WireguardCompiler::compileServerConfig()` is only
ever attached to an HTTP download. Therefore:

* `vpn.wireguard.sync` on a real appliance is a no-op (or an error when the file is absent);
* the Web UI's "Tunnel enabled/disabled — interface brought up/torn down" messages are
  **false** (G2).

**Required fix:** the daemon materialises `/etc/wireguard/<iface>.conf` atomically from
the same compiled content the UI offers (enabled peers only per
`WireguardCompiler::enabledPeers()`), then `wg syncconf`s it; the controller's message
must reflect the daemon's actual `status`/`warnings`. Test: invoke the RPC, read the file
back, assert contents, assert the disabled peer is absent.

---

## 5. Route ⇄ permission contract

* Every route declares `['filter' => 'permission:<key>']`; the group's key is the
  *view* permission and mutating actions add a narrower one.
* Permission keys are catalogued in the `permissions` table and surfaced in the role
  editor. A key used in `Routes.php` but absent from the catalog silently denies
  everyone — a test must catch it.
* Controllers must not re-implement authorization.
* Read-only views never use a `*.manage`/`*.install` key.

---

## 6. CLI surfaces

| Surface | Path | Role | Current capability |
|---|---|---|---|
| `microctl` | `bin/microctl` | Break-glass console (`status`, `enable`, `disable`, `interface set-lan`, `firewall reset`, `backup create\|restore\|verify`, `config rollback`, `admin reset-password`, `help`) | No diag/VPN/plugin/route commands (G8) |
| `micron-menu` | `bin/micron-menu` | Interactive TUI on console/serial | Coverage unverified against the UI |
| `spark` | `spark` + `app/Commands/` | 17 commands (`acme:renew`, `backup:*`, `firewall:compile`, `plugin:manage`, `verify:system`, …) | No unified health report |

**Rules for CLI code:**

1. Call the daemon through the RPC client, never edit config files or shell out to the
   underlying tool directly — that is how the three surfaces diverge.
2. Every subcommand supports `--help` and, where it returns data, `--json`.
3. Exit non-zero on failure, with a message naming the failing step.
4. No subcommand may leave the appliance without a management path.

---

## 7. Trust model (state it honestly)

* The UNIX socket and its `0660` group membership are the authorization boundary for the
  daemon **today**. Any process running as `www-data` can invoke any handler; the
  capability declarations in plugin manifests bound what *plugins* may ask for, not what
  a caller may invoke.
* The Web UI's authorization is enforced in PHP (route filters + `PermissionModel`),
  which is correct for the operator-facing surface but is **not** a daemon-side control.
* **W1 hardening:** add a declared capability class per method — `read`, `configure`,
  `destructive` — to a method registry, and refuse invocations that the caller's granted
  class does not cover. Document the residual risk and the future per-caller identity
  (`SO_PEERCRED` pid/uid → mapped account) as an explicit follow-up rather than implying
  it already exists.

---

## 8. The alignment rule (adopt as policy)

> A capability is **not done** until it exists as: RPC method → controller action →
> route + permission key → UI control → CLI command → mock response → tests →
> parity-matrix row.

Corollaries:

* Adding a UI form field requires the controller to read it (G5: the peer modal posted
  `peer_address` while the controller derived the address from `allowed_ips`, so the
  operator's input was discarded).
* Adding a route requires the permission key to be in the catalog.
* Adding an RPC method requires a mock response and a CLI path (or a written exemption).
* Renaming is a migration: add the new name, keep the old working, then deprecate.

---

## 9. Contract tests (the enforcement)

Add to the suite (Programme W1.3 / W6):

1. **RPC existence** — every method string passed to `IpcClient::call()` in `app/` exists
   as `rpc_*` in `micrond.py` (exemptions require an explicit allow-list entry with a
   reason).
2. **Mock coverage** — every UI-reachable RPC has a `mockDispatch()` entry.
3. **Route integrity** — every route action class/method exists; every
   `permission:<key>` exists in the permission catalog; no duplicate route shadows a
   literal (the `plugins/(:segment)` vs `plugins/refresh` class of bug).
4. **View ⇄ controller contract** — every `name="…"` field (and `id` referenced by the
   modal JS) in a view is read by the controller that handles the form's `action`.
5. **Plugin menu resolution** — every installed plugin's `provides.menu[].path` resolves
   to a route; uninstalled plugins contribute no entries.
6. **Parity matrix generation** — emit `docs/` matrix data (namespace, method, UI page,
   CLI command, tests) and fail on a row with a UI entry and no CLI entry (or vice versa)
   unless explicitly exempted.
7. **Secrets scan** — assert no private key / PSK / password / token value appears in any
   rendered HTML or JSON response.
8. **Destructive-method classification** — every mutating handler is either classified
   `destructive` or explicitly justified as `configure`.

---

## 10. Worked example — adding `diag.telnet` end-to-end

```text
1. daemon      system/micrond/micrond.py
               async def rpc_diag_telnet(self, params) -> Dict[str, Any]
                 · validate host (literal or RFC-1123 name), port 1..65535, timeout 1..30
                 · build argv list [ "nc", "-z", "-w", str(timeout), host, str(port) ]
                   (never a shell string; no -e/-c; no user-supplied flags)
                 · run with a hard timeout, cap output, return
                   { success, status, output, parsed:{open: bool}, warnings }
2. pytest      tests/test_micrond_diag.py — separator port open/closed, invalid host,
               port 0/65536, timeout bounds, metacharacter injection, output parse
3. controller  app/Controllers/Diagnostics.php — telnet(): reuse targetInputError()
               + a new portInputError(); return JSON { success, output }
4. route       app/Config/Routes.php — diag group:
               $r->post('telnet', 'Diagnostics::telnet', ['filter'=>'permission:diag.telnet']);
5. permission  add diag.telnet to the catalog + role editor (new key, read-only class)
6. UI          app/Views/diag/index.php — tab "Port / Service Reachability" with host,
               port, timeout, live output, copy, cancel; disruptive-tool notice
7. mock        IpcClient::mockDispatch() — plausible simulated banner/timeout output
8. CLI         bin/microctl diag telnet <host> <port> [--timeout N] [--json]
               + a micron-menu entry
9. PHPUnit     tests/app/Controllers/DiagnosticsTelnetTest.php — 403 without the
               permission, 200 happy path in mock mode, invalid port, oversized host
10. shell      tests/installer or CLI suite: assert --help and --json exit codes
11. docs       row in MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md + parity matrix
12. audit      daemon logs operator, host, port, result
```

Any step skipped means the capability is not done (Programme §8).

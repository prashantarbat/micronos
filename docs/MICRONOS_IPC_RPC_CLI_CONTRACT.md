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
| `isMock()` | the development client: `MICRON_IPC_MOCK=1`, or off-appliance on Windows / with no socket. **Never on an appliance** (`ID=micronos` in `/etc/os-release`, or `MICRON_APPLIANCE=1`): there a missing socket is reported as an error and pages say "unavailable" instead of showing samples |
| `isAppliance()` | static; the check above |
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

### Drift closed in the 2026-10 completion pass

`tools/rpc_inventory.py` (and `tests/test_micrond_ops.py`, which fails the build on it)
found **54 method names called by `app/` that the daemon did not implement**. On an
appliance every one of them returned `-32601 Method not found`, while the Windows mock
answered a generic success — so the defect was invisible in development. All are now
implemented or the caller was corrected:

| Area | Methods now implemented | Behaviour |
|---|---|---|
| DNS | `dns.config.reload`, `dns.hosts.reload`, `dns.status` | managed config `unbound` |
| FRR | `frr.{bgp,ospf,rip,isis,bfd}.reload` | managed config `frr` (compiled by `App\Libraries\FrrCompiler`) |
| Routing | `network.vrf.sync`, `network.multiwan.sync` | VRF devices reconciled; gateways via the policy-routing apply |
| HA | `ha.sync`, `ha.failover`, `ha.status` | managed config `keepalived`; live keepalived/conntrackd state |
| IDS | `ids.apply`, `ids.status` | managed configs `suricata`, `suricata-rules` (checked with `suricata -T`) |
| QoS | `qos.apply`, `qos.status` | `tc -batch` script, every line pattern-checked |
| DPI | `dpi.apply`, `dpi.status`, `dpi.stats`, `dpi.live_flows` | reports honestly when no nDPI engine is installed |
| SNMP / SIEM | `snmp.reconfigure`, `snmp.sync_traps`, `snmp.sync_users`, `snmp.get_logs`, `snmp.test_query`, `snmp.send_test_trap`, `siem.reconfigure`, `siem.get_logs`, `siem.send_test` | managed configs `snmpd`, `rsyslog-siem`; journal reads |
| System | `system.health`, `system.services_status`, `system.service_logs`, `system.commit_watchdog`, `system.confirm`, `system.crontab_sync`, `system.cron_run`, `system.cron_logs`, `system.rpc_methods` | real `/proc`, `/sys`, systemd and journal data; each `system.health` interface also carries `kind` (ethernet / wireless / bridge / vlan / bond / tunnel / loopback / virtual), `wireless`, `driver`, `bus`, `slot` and, for Ethernet, `port_type` (`tp`, `fibre`, `da`, from `ethtool`, cached) |
| Network | `network.apply` (destructive), `network.confirm`, `network.rollback`, `network.status` (read), `system.ipv6.set` (destructive) | one transaction: plan + nftables + Kea → snapshot → apply → verify → commit or restore; confirm window for management-affecting changes. See [Network Configuration](MICRONOS_NETWORK_CONFIGURATION.md) |
| Hardware | `hardware.nics` (read) | the physical Ethernet ports and Wi-Fi radios in `/sys/class/net`, with real kernel names (`eno1`, `enp7s0`, `wlp2s0`); used by the dashboard front panel, the Interfaces page (Wi-Fi only when a radio exists) and the darkstat capture picker |
| DHCP | `dhcp.leases` (read) | current leases from Kea's memfile CSV (`/var/lib/kea/kea-leases4.csv` and rotations; newest record per address, default state, unexpired); empty when Kea has never run |
| Plugins | `plugin.darkstat.apply`, `plugin.darkstat.status` | drop-in that replaces Debian's SysV `Type=forking` wrapper (`Type=simple`, `--no-daemon`, `--user nobody`, chroot created); status probes the web port |
| VPN | `vpn.openvpn.sync`, `vpn.openvpn.status`, `vpn.wireguard.remove` | managed config `openvpn`; status file parsing |
| Certificates | `cert.install`, `cert.reload` | key/cert match check, `/etc/micron/ssl/webgui.{crt,key}` |
| Firewall | `firewall.list_rules`, `diag.conntrack_kill`, `diag.conntrack_kill_host` | live `nft -j` counters; real deleted-state counts |
| Web filter | `webfilter.av.status`, `webfilter.clamav.update` | live ClamAV / c-icap state, `freshclam` |

`App\Libraries\CommandDispatcher` used ten further names that never existed
(`firewall.flush`, `nat.list`, `route.table`, `interface.set`, `backup.*`, …);
those wrappers were removed rather than faked.

**WireGuard (G1/G2).** `vpn.wireguard.sync` now receives the compiled configuration
(`WireguardCompiler::compileServerConfig()` — enabled peers only), refuses `PreUp/PostUp/
PreDown/PostDown/SaveConfig` hooks (wg-quick would run them as root), writes
`/etc/wireguard/<iface>.conf` atomically with mode 0600, and then brings the interface
down, up, re-addresses it, or `wg syncconf`s the stripped config as appropriate. Every
WireGuard flash message in the UI is built from the daemon's answer; a failed sync is
reported as an error, never as "brought up / torn down".

### Managed configuration files

Configuration-bearing methods share one engine, `_apply_managed_config()` in the daemon.
The caller supplies only the file **content**; the daemon owns the path, the mode, the
syntax checker and the unit. The sequence is: validate (non-empty, ≤ 1 MiB, no NUL) →
atomic write → syntax check → reload → on failure restore the previous file (and, if the
reload had already run, reload it again). An apply without content changes nothing and
says so.

| Kind | Path | Mode | Checker | Reload |
|---|---|---|---|---|
| `unbound` | `/etc/unbound/unbound.conf.d/micron.conf` | 0644 | `unbound-checkconf` | `systemctl reload-or-restart unbound` |
| `frr` | `/etc/frr/frr.conf` | 0640 | `vtysh --dryrun --inputfile` | `systemctl reload-or-restart frr` |
| `keepalived` | `/etc/keepalived/keepalived.conf` | 0600 | `keepalived --config-test` | `systemctl reload-or-restart keepalived` |
| `snmpd` | `/etc/snmp/snmpd.conf` | 0600 | — | `systemctl restart snmpd` |
| `rsyslog-siem` | `/etc/rsyslog.d/60-micron-siem.conf` | 0644 | `rsyslogd -N1` | `systemctl restart rsyslog` |
| `openvpn` | `/etc/openvpn/server.conf` | 0600 | — | `systemctl restart openvpn@server` |
| `suricata` / `suricata-rules` | `/etc/suricata/micron.yaml`, `rules/micron.rules` | 0644 | `suricata -T` | `systemctl reload-or-restart suricata` |
| `qos` | `/etc/micron/qos.tc` | 0644 | line patterns | `tc -force -batch` |
| `cron` | `/etc/cron.d/micron` | 0644 | allow-list (below) | — |

The PHP side is `App\Libraries\ConfigApplier` (one method per subsystem), used through
`BaseController::applier()`; compilers live in `FrrCompiler`, `ServiceConfigCompiler`,
`UnboundDnsCompiler`, `SuricataCompiler`, `OpenvpnCompiler`.

**Cron allow-list.** `system.crontab_sync` / `system.cron_run` accept only `php spark …`
tasks and executables under `/usr/local/bin`, `/usr/local/sbin` plus a few named system
tools, only for users `root` and `www-data`, and refuse shell syntax
(`; | & $ ` ( ) < >` and quotes). A refused line rejects the whole sync and leaves the
installed file untouched.

### Transport detail: empty params

PHP's `json_encode([])` produces a JSON **list**. The client now sends `{}` for empty
params, and the daemon also accepts `[]` as "no parameters". (Found by running the Web UI
against a live daemon after the dispatcher began rejecting non-object params.)

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

## 7. Trust model (as implemented)

* **Outer boundary:** the socket is `0660`, group `www-data`.
* **Per-method classes:** every handler is `read`, `configure` or `destructive`
  (`RPC_READ_METHODS` / `RPC_DESTRUCTIVE_METHODS` in `micrond.py`; everything else is
  `configure`). `system.rpc_methods` publishes the catalogue with each class
  (`microctl rpc methods`).
* **Caller identity:** on each connection the daemon reads the peer's kernel-attested
  `SO_PEERCRED` (pid, uid, gid). `root` (console tools via sudo) and `www-data` (the web
  plane) may use every class. Any other uid in the socket group is limited to `read`
  unless `/etc/micron/rpc-peers.json` (`{"user": "configure"}`) grants more. A peer
  whose identity cannot be read is limited to `read`. Refusals return `-32003
  Permission denied` and are logged as `AUDIT rpc refused`.
* **Audit:** every non-read invocation is logged with method, class, uid and pid.
* **Dispatch hardening:** method names must match `^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$`
  (no dunder lookups), params must be an object (`-32602` otherwise).
* **Residual risk:** the web plane runs as `www-data` and therefore holds every class;
  the operator-level authorisation for the Web UI remains the PHP route filters
  (`PermissionFilter` → `PermissionModel`). Per-operator identity is not propagated to the
  daemon.

The Web UI's route permissions are checked by
`CompletionRegressionTest::testEveryRoutePermissionExistsInTheCatalogue`: five keys used by
routes but never catalogued (`vpn.ipsec.view`, `vpn.ipsec.manage`,
`captive.vouchers.revoke`, `captive.apply`, and `captive.sessions.terminate` — renamed to
the existing `captive.sessions.disconnect`) made those pages unreachable for every
delegated role; migration `2026-10-14-000118` catalogues and grants them.

## 7a. Console CLI (`microctl`) — daemon commands

These talk to micrond directly over the socket (same framing as the Web UI, **no
simulation fallback**). Every command accepts `--json`.

| Command | RPC | Notes |
|---|---|---|
| `microctl version` | — | reads `VERSION` |
| `microctl health` | `system.health` | uptime, load, memory, disks, conntrack, temperature, links |
| `microctl services` | `system.services_status` | live systemd state of managed units |
| `microctl service <id> <start\|stop\|restart> [--yes]` | `system.service` | `stop` needs `--yes` when not interactive |
| `microctl logs <service> [--lines 1-500]` | `system.service_logs` | journal of a managed unit only |
| `microctl vpn <wireguard\|openvpn\|ipsec> status [--interface wg0]` | `vpn.*.status` | interface name validated |
| `microctl plugins` | `plugin.catalog` | |
| `microctl rpc methods` | `system.rpc_methods` | handler + capability class |
| `microctl doctor` | several | socket, daemon, DB `quick_check`, disk, failed units |

Exit status: `0` success · `1` usage error (including an unknown command — it used to exit
0) · `2` daemon unreachable · `3` the daemon reported a failure / doctor found a failure.
`MICRON_SOCKET` overrides the socket path (used by the integration test).
`interface set-lan` now validates `ipv4/prefix` (8–30, not the network or broadcast
address) and keeps the generated DHCP pool inside the new subnet.

Verified end to end by `tests/test_cli_daemon_integration.py`, which starts the real
daemon on a temporary socket and drives `bin/microctl` against it.

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

## 11. Browser terminal control socket (not micrond)

`micron-terminald` has its own control socket, `/run/micron/terminal.sock`
(0660 root:www-data, SO_PEERCRED: root or the www-data group), with the same
framing as micrond (4-byte big-endian length + JSON-RPC 2.0). Only the Web UI
(`App\Libraries\TerminalClient`) and `php spark terminal` / `microctl terminal`
use it.

| Method | Params | Result |
|---|---|---|
| `terminal.issue` | `user_id`, `username`, `level` (`standard`/`admin`), `origin`, `client_ip`, `attach?` | `{ticket, expires_in}` or `{success:false, error, code?:"limit"}` |
| `terminal.list` | `user_id?` | `{sessions:[{id, user_id, username, level, pid, created, last_input, attached, cols, rows}]}` |
| `terminal.kill` | `session_id`, `user_id?` (must own it when given), `reason?` | `{success}` |
| `terminal.revoke_user` | `user_id`, `level?`, `reason?` | `{terminated}` |
| `terminal.status` | — | `{version, uptime, sessions, limits, standard_user_present}` |

The WebSocket side and the privilege model are in [Web Terminal](MICRONOS_WEB_TERMINAL.md).


# MicronOS Verification Report — 2026-10-14 — Completion pass

**Author:** Prashant Arbat · **Project:** https://github.com/prashantarbat/micronos
**Scope:** IPC/RPC ⇄ Web UI ⇄ CLI alignment, removal of fabricated data, security
fixes, shared UI components, console CLI, plugin menu integration, tests and docs.
**Companions:** [Completion Programme §3.1](MICRONOS_COMPLETION_PROGRAMME.md) (gap status),
[IPC/RPC/CLI Contract](MICRONOS_IPC_RPC_CLI_CONTRACT.md) (implemented contract).

---

## 1. Environment

| Item | Value |
|---|---|
| Host | Linux x86-64 build container (not the appliance image) |
| PHP | 8.3.6 (project requires ≥ 8.2; the appliance ships 8.4) |
| Python | 3.13 |
| Database | SQLite (migrated from scratch, then seeded) |
| VERSION | 0.1.23 |
| Daemon | `system/micrond/micrond.py` run as root on `/run/micron/micrond.sock` for the live checks |

Not available in this environment: the appliance ISO, systemd as PID 1, `wg`, `nft`,
`conntrack`, `vtysh`, `keepalived`, `suricata`, `snmpd`. Behaviour that needs those
binaries is covered by tests that capture the exact argv instead of running it, and is
listed in §6 as not verified live.

## 2. Suites

| Suite | Command | Baseline (before) | Now |
|---|---|---|---|
| PHPUnit | `php vendor/bin/phpunit --no-coverage` | 1,410 tests, **4 failures** | **1,434 tests, 9,243 assertions, OK** |
| Daemon harnesses | `python3 tests/test_*.py` | 13 of 16 passing | **17 of 17 passing** (1 opt-in live suite skips by design) |
| CLI ↔ daemon integration | `python3 tests/test_cli_daemon_integration.py` | — (new) | 17 / 17 |
| Installer / console menu | `bash tests/installer/*.sh` | 3 / 3 | 3 / 3 (51 + 59 + 26 assertions) |
| RPC drift | `python3 tools/rpc_inventory.py` | 54 methods called but not implemented | **0** (204 handlers, 151 called) |
| Strict-decoding guard | `python3 tests/test_strict_decoding_guard.py` | failing | passing |

Daemon harness detail (PASS counts): diag 286, plugins 360, phase0 112, toolkit 111,
ops 55, webfilter 45, dhcp_relay 34, dhcp 32, strict guard 31, users 22, repo_presets 22,
multicast 21, bonds/wireless 19, updates 14, resilience 13.

The four baseline PHPUnit failures and how they were resolved: daemon base64 decoded
without `validate=True` (fixed); a diagnostics test that only passed when the host lacked
the `ip` binary (the controller's PHP shell fallback was removed); captive-portal views
importing Google Fonts (now self-hosted); a packaging check needing `writable/` backup
directories (environment fixture). Baseline daemon failures: a time-zone-dependent key
date (now UTC), a missing `import socket` in a test, and the base64 guard.

## 3. Delivered

| Item | Where | Evidence |
|---|---|---|
| 54 missing daemon methods implemented; managed-config engine with check/reload/restore | `system/micrond/micrond.py` (`OpsMixin`, `_apply_managed_config`) | `tests/test_micrond_ops.py` |
| Compile-and-apply for DNS, FRR (BGP/OSPF/RIP/IS-IS/BFD), HA, SNMP, SIEM, QoS, IDS, OpenVPN, VRF, cron, Web GUI certificate | `app/Libraries/ConfigApplier.php`, `FrrCompiler.php`, `ServiceConfigCompiler.php` | `CompletionRegressionTest`, `ServiceCompilersTest` |
| WireGuard config materialised and applied; truthful messages; teardown on delete/rename | `micrond.py`, `VpnWireguard.php` | ops tests, `CompletionRegressionTest` |
| RPC capability classes, `SO_PEERCRED` caller limits, audit log, dispatch hardening | `micrond.py` | toolkit + ops tests |
| Real dashboard telemetry (no sample data on an appliance) | `app/Libraries/LiveTelemetry.php`, `Dashboard.php` | `DashboardModernizationTest`, live screenshot |
| Services page and dashboard service buttons drive systemd and show its real state | `Services.php`, `Dashboard.php` | `ServiceControlTest`, live run |
| Logs (SNMP, SIEM, cron, services) read from the journal; no invented history on an appliance | controllers | `CronPageTest` |
| Console CLI: `version, health, services, service, logs, vpn, plugins, rpc methods, doctor` with `--json` and exit codes | `bin/microctl`, completion | `test_cli_daemon_integration.py`, `TerminalCli*` tests |
| Installed plugins linked from their functional menus | `PluginNav::SECTIONS`, `NavGroups::owns()`, layout | `PluginMenuPlacementTest` |
| Shared footer with author attribution on every page (layout, sign-in, 2FA, error pages) | `app/Views/partials/app_footer.php`, `Config/Branding.php` | `CompletionRegressionTest` |
| Escaped flash messages with a warning level; confirm dialog; toasts; Ctrl/⌘+K page search; skip link; focus rings; WCAG-AA muted-text contrast in all 8 themes | `partials/flash.php`, `micronos-ui.js`, `micronos-palette.js`, `micronos.css`, `tools/contrast_fix.py` | screenshots (desktop 1440 px, phone 390 px) |
| Five uncatalogued route permissions added and granted | migration `2026-10-14-000118` | `testEveryRoutePermissionExistsInTheCatalogue` |

## 4. Security findings fixed

1. **Stored XSS:** flash messages were printed raw; any operator-entered name (gateway,
   tunnel, certificate) could carry script. Now escaped (`partials/flash.php`).
2. **Secrets in HTML:** the HA page rendered the VRRP password and the config-sync API key
   into visible and hidden form fields. Removed; empty means "keep". Keepalived preview masks
   the password.
3. **No daemon-side authorisation:** any process in the socket group could call any
   handler. Now limited by caller uid and method class (contract §7).
4. **Shell from PHP:** Diagnostics fell back to `shell_exec('ip …')`. Removed; only the
   daemon touches the kernel.
5. **Cron as a root shell:** the cron page could install any command line as root. The
   daemon now accepts only allow-listed commands and users and rejects shell syntax.
6. **WireGuard hooks:** a config containing `PostUp`/`PreUp` would have been run by
   wg-quick as root; refused.
7. **Key generation:** on failure the daemon returned a random public key unrelated to the
   private key; it now fails explicitly.
8. **Port inspector output** was written with `innerHTML`; now `textContent`.

## 5. Regression deltas found during this pass

* PHP `json_encode([])` sends a JSON list; the new params check rejected it, which would
  have broken every parameterless Web UI call. Found by running the UI against the live
  daemon; fixed on both ends with a test.
* Dashboard layout saves and port actions failed CSRF after the first request (rotating
  tokens); fixed and verified in a browser (layout save now 200).
* The test suite changed results when a real daemon was listening on the host; it now
  forces the development client via `MICRON_IPC_MOCK=1` in `phpunit.dist.xml`.

## 6. Failed, not delivered, or not verified

| Item | Status | Impact |
|---|---|---|
| Network Tools Web page and console commands for telnet/mtr/dig/whois/TLS/HTTP/iperf3 (and port scanning) | **Not delivered.** Daemon handlers exist and are unit-tested; no Web page, route or `microctl` command exposes them | Operators still have only the existing Diagnostics page (ping, traceroute, DNS lookup, port probe, capture, benchmark) |
| Live verification on the appliance image | Not run (no VM access from this environment) | Managed configs were tested with captured argv, not real `vtysh`/`keepalived`/`suricata`/`wg-quick` |
| Per-page visual restyle of every module | Not done | Shared components and contrast were improved globally; individual pages keep their existing layouts |
| Plugin workspace evidence table (spec §7) | Not filled | Menu placement is done and tested |
| HA "Sync to Peer" | Re-applies locally only; message says so | Peer config push is not implemented |
| DPI | Reports "no engine installed" unless `xt_ndpi`/`ndpid` exists | No live flow data without an engine |
| `docs/*.html` portals | Regenerated with `php docs/build_docs.php` | — |
| Development-mode simulations | 42 RPCs return a generic simulated answer in development mode | UI is still navigable offline; never shown on an appliance |

## 7. Commands to reproduce

```bash
composer install
php spark migrate --all
php vendor/bin/phpunit --no-coverage
for t in tests/test_*.py; do python3 "$t" > /dev/null || echo "FAILED: $t"; done
for t in tests/installer/*.sh; do bash "$t"; done
python3 tools/rpc_inventory.py
php docs/build_docs.php                     # regenerate the HTML portals

# on the appliance
sudo microctl doctor
sudo microctl health --json
sudo microctl rpc methods
```

Upgrade: copy the tree to `/usr/local/micron`, run `php spark migrate --all` (adds the
catalogued permissions), restart `micrond` and the web server. No schema change is
destructive; migration `2026-10-14-000118` has a `down()` that removes only the keys it added.

---

## 8. Follow-up pass — device view, sample data, widgets, Wi-Fi, darkstat

| Request | What changed | Evidence |
|---|---|---|
| Front panel shows the machine's real ports | The dashboard faceplate is built from `system.health` (`/sys/class/net`): WAN, copper Ethernet, SFP/fibre and Wi-Fi bays, each port labelled with its kernel name (`eno1`, `enp7s0`, `enp5s0f0`, `wlp2s0`), its assignment and link speed. The fixed 16-port matrix, placeholder `eth18`/`sfp0` ports, console jack and fixed PWR2/HA LEDs are gone; software devices sit on the virtual rail. `/dashboard/traffic` and the traffic graph use measured rates. | `PhysicalFaceplateTest` (2), live screenshot against micrond in the build container (`eth0`, virtio) |
| No development sample data on an ISO / installed system | `IpcClient` never uses the development client on an appliance (`ID=micronos` in `/etc/os-release` or `MICRON_APPLIANCE=1`, which the ISO hook and installer set). Migration 119 removes, on appliances only, the demonstration rows earlier migrations seeded — including live configuration: an enabled 1:1 NAT for 198.51.100.25, NPTv6, a PPPoE server on eth1, enabled LDAP/RADIUS servers mapped to Superadmin, an SNMPv3 user with a published passphrase, SNMP `public` with a trap receiver, SIEM forwarding, SD-WAN members, ZTNA apps — plus invented IDS alerts, proxy logs, PPPoE sessions, threat indicators and "running" service rows. DHCP leases now come from Kea (`dhcp.leases`), not a fixed list; the sample printer reservation is development-only. | `ApplianceModeTest` (6); fresh-database run with `MICRON_APPLIANCE=1`: every listed table empty, SNMP/SIEM/SD-WAN/ZTNA off; development database untouched |
| Dashboard widget cards | Six new cards, all in Add Widget: Physical Ports, Storage & Memory, DHCP Leases, Connection States, Installed Plugins, Quick Actions. VPN card badges come from systemd. Cards added after a layout was saved are appended after the saved ones. | `DashboardModernizationTest` (+2) |
| Wi-Fi options only with a wireless NIC | `hardware.nics` reports radios (`/sys/class/net/<dev>/wireless` or `phy80211`). Without one, the Interfaces page has no Wireless tab, card, button or dialog, the port picker no longer lists invented `wlan0`/`eno1`/`eth2`, saving Wi-Fi is refused, and `/interfaces/wireless/status/<dev>` is 404. With one, the dialog offers only the detected radios. The stored Wi-Fi passphrase is no longer written into the page. Configured devices the kernel does not have show "Not present", not "Link up". | `BondAndWirelessTest` (+6) |
| darkstat on port 666 | Two faults. (1) The input firewall drops everything it does not list, and port 666 was never listed: the port is now recorded on apply (`plugin_settings.web_port`) and `NftablesCompiler` opens it on LAN interfaces only while the plugin is enabled; the ruleset is reloaded on apply, disable and removal. (2) Debian ships darkstat as a SysV script, which systemd wraps as `Type=forking` + `RemainAfterExit`: with `--no-daemon` systemd killed darkstat at the start timeout while still reporting "active". The drop-in now resets the unit to `Type=simple`, clears `ExecStop`, creates the chroot for `nobody` and passes `--user nobody`. Install starts it on the LAN port with port 666; the page lists real interfaces and shows whether the port answers. | `test_micrond_plugins.py` (+4), `PluginsControllerTest` (+1) |

Suites after this pass: PHPUnit **1,450 tests, 9,291 assertions, OK**; 17/17 daemon harnesses; installer 3/3;
RPC drift 0 (206 handlers). Not verified here: a real darkstat binary and systemd, and
hardware with SFP or Wi-Fi ports (the classification code reads `/sys` and `ethtool`
exactly as shown, but this container has one virtio NIC).

---

## 9. System settings, IPv6, Web Terminal and address-change propagation

Specs: *System Configuration Improvements and Browser-Based Web Terminal* and
*WAN/LAN IP Address Changes Must Automatically Synchronize Firewall, NAT, and
Network Policies*. Details: [Network Configuration](MICRONOS_NETWORK_CONFIGURATION.md),
[Web Terminal](MICRONOS_WEB_TERMINAL.md).

| Item | Result | Evidence |
|---|---|---|
| IPv6 off by default (ISO, install, unsaved settings), OS-level, persistent, transactional with rollback | Done: `60-micron-ipv6.conf` shipped off; `micron_netcfg` writes all/default/lo/per-device keys and the running kernel, stops DHCPv6/RA, firewall drops IPv6, Unbound has no IPv6 listener; System › Networking applies through the network transaction and keeps the old value when the daemon refuses | `test_micron_netcfg.py`, `NetworkTransactionTest`, `micron_network_boot_test.sh` |
| Language selection removed (English only) | Done; stored values are pinned to `en` and ignored on save | `SystemSettingsCleanupTest` |
| Solar Amber + Top nav defaults (server-rendered on first paint) | Done; a saved preference is kept | `SystemSettingsCleanupTest` |
| System Information section removed | Done (System & Misc tab) | `SystemSettingsCleanupTest` |
| Serial console / baud rate UI removed, 115200 fixed | Done: GRUB (`--speed=115200`, `console=ttyS0,115200n8`) in the installer and slot menu; ISO menus already 115200; `console_speed` pinned | `SystemSettingsCleanupTest` |
| Web Terminal (PTY, WebSocket, tickets, standard/root, limits, audit, reattach) | Done: `micron-terminald` + `Terminal` controller/page; xterm.js vendored; systemd unit, `micron-term` account, Apache `proxy_wstunnel` in hook and installer | `test_micron_terminald.py` (real service), `TerminalControllerTest`, browser run (Playwright: `id`, `uname`, `TERM`, Ctrl+C, resize, root re-auth, End session) |
| WAN/LAN change propagation | Done: one transaction for Web UI, CLI, IPv6 switch and boot; dependents updated or listed for review; confirm window with automatic rollback; boot builds everything from the database; factory literals migrated to dynamic references | `NetworkTransactionTest` (14), `test_micron_netcfg.py` (49), `micron_network_boot_test.sh` (18) |
| Compiler fault found on the way | Fixed: rules with the schema default `src_interface = 'any'` were compiled as structured policies without their address/port matchers — the factory LAN rules became unconditional accepts | `micron_network_boot_test.sh` ("no open accept"), `NetworkTransactionTest` |

**Not executed (no hardware, VM or live-build in this environment):** building
the ISO with live-build, booting it (UEFI and BIOS), a clean install and reboot,
real NICs/`ip`/`nft`/`kea`/systemd, and disruptive management-address changes
on a live box. The boot script was exercised in a mount namespace with stubbed
kernel tools (`tests/installer/micron_network_boot_test.sh`); everything that
reaches the kernel is covered by argv-capturing tests. These must be repeated
on a disposable VM before release.

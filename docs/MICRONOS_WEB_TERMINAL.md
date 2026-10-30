# Micron OS — Web Terminal (Terminal / Console)

**Author:** Prashant Arbat · **Project:** https://github.com/prashantarbat/micronos

The **Terminal** menu gives an interactive Linux shell in the browser: a real
pseudo-terminal on the appliance, rendered with xterm.js, with colours, cursor
keys, Ctrl+C / Ctrl+D, resizing and full-screen programs (`top`, `vi`, `less`,
`mtr`, …).

## 1. Architecture

```
browser (xterm.js, /terminal)
   │  1. POST /terminal/session  (session cookie + CSRF)  ──►  PHP Controllers/Terminal.php
   │                                                            · permission terminal.access / terminal.admin
   │                                                            · root: password re-entered (≤ 5 min ago)
   │                                                            · transport policy (HTTPS or private client address)
   │                                                            │ 2. terminal.issue  (unix socket, JSON-RPC,
   │                                                            ▼    /run/micron/terminal.sock 0660 root:www-data)
   │                                                   micron-terminald  (root, systemd unit)
   │  ◄── one-time ticket (30 s) ───────────────────────────────┘   ticket = {user, level, Origin, client IP, attach?}
   │
   │  3. WebSocket  /terminal/ws?ticket=…   ──►  Apache mod_proxy_wstunnel  ──►  127.0.0.1:7681
   │                                                   · ticket single-use, Origin and client IP must match
   │                                                   · standard: shell as `micron-term` + rlimits
   │                                                   · admin: root shell, hard lifetime
   ▼
 PTY  ⇄  bash -l   (session = its own process group; killed as a whole)
```

| Piece | File |
|---|---|
| Service (stdlib Python, PTY, WebSocket, control socket, audit) | `system/micron-terminald` |
| systemd unit (`KillMode=control-group`, `TasksMax`, `MemoryMax`) | `system/micron-terminald.service` |
| Authorisation, tickets, re-authentication | `app/Controllers/Terminal.php` |
| Control-socket client | `app/Libraries/TerminalClient.php` (`Services::terminalClient()`) |
| Page (xterm.js, tabs, reattach, status) | `app/Views/terminal/index.php` |
| Vendored assets (offline) | `public/assets/vendor/xterm/` (xterm.js 5.5.0, addon-fit 0.10.0, MIT) |
| Settings | `app/Config/Terminal.php`, `/etc/micron/terminal.json` |
| Permissions | migration `2026-10-14-000121_TerminalPermissions` |
| Console | `php spark terminal …`, `microctl terminal …` |

The WebSocket protocol: text frames `{"t":"i","d":…}` (input), `{"t":"r","c":cols,"r":rows}`
(resize), `{"t":"p"}` (ping), `{"t":"x"}` (end session); binary frames carry output;
the server sends `hello`, `exit`, `closed`, `notice`.

## 2. Privilege model

| Level | Who | Runs as | Extra checks |
|---|---|---|---|
| Standard | `terminal.access` (granted to NetworkAdmin, SecurityAdmin; SuperAdmin has all) | `micron-term` (system account, `/var/lib/micron-term`) with RLIMIT_NPROC 256, AS 2 GiB, FSIZE 1 GiB, NOFILE 1024, no core files, umask 077 | — |
| Admin (root) | `terminal.admin` (no role by default; SuperAdmin has it) | root | password re-entered within 5 minutes (local accounts only; 5 failures lock it for 5 minutes); session ends after 60 min and on sign-out |

Every privilege decision is made on the server: the browser cannot choose an
account, a command or a shell — it holds only a ticket that names them.

A root terminal is marked in the tab (`ROOT`), with a red banner line and a red
pane outline.

## 3. Security controls

* **Authentication:** only a signed-in Web UI session can obtain a ticket; the
  WebSocket endpoint accepts nothing but an unused, unexpired ticket.
* **Cross-site WebSocket hijacking:** the ticket is bound to the page's Origin;
  any other Origin is refused (HTTP 403) and audited.
* **Session theft:** the ticket is bound to the client address (X-Forwarded-For
  from the local proxy only), is single-use, and expires in 30 s. A session can
  be reattached only by the user who owns it.
* **Transport:** HTTPS is always accepted. Plain HTTP is accepted only from
  loopback / private / CGNAT / link-local / ULA client addresses (the management
  networks the firewall restricts the GUI to). `TERMINAL_REQUIRE_TLS=1` (or
  `Config\Terminal::$requireTls`) refuses plain HTTP entirely.
* **No command injection:** the service never runs a browser-supplied command;
  it starts the account's login shell (`/bin/bash -l`) with a fixed environment.
* **Resource limits:** per-user (3) and total (16) session caps; idle timeout
  (15 min, root 10 min, measured on keyboard input); hard lifetime (8 h, root
  1 h); cgroup caps for the whole service (`TasksMax=1024`, `MemoryMax=1536M`).
* **Malformed input:** oversized frames close the connection (1009); invalid
  JSON, sizes outside 2–500 × 2–300 and unknown messages are ignored.
* **Isolation:** the service is independent of `micrond` and the Web UI. If it
  stops, only the Terminal page reports "service not running".

## 4. Lifecycle

* Closing a browser tab **detaches**; the shell keeps running for 120 s and can
  be reattached (recent output is replayed). After that it is ended.
* A network drop reconnects automatically to the **same** session (up to 3
  attempts) — reconnecting never creates a duplicate shell. Opening the same
  session in a second window takes it over and tells the first window.
* Ending a session (button, `exit`, timeout, revoke, service stop) hangs up the
  whole process group (SIGHUP → SIGTERM → SIGKILL) and reaps it: no orphans.
* Sign-out ends that user's root sessions.

## 5. Audit

`/var/log/micron/terminal-audit.log` (0600 root, JSON lines) and the journal:
`ticket_issued`, `auth_failed` (missing/used ticket, Origin or address
mismatch), `attach_denied`, `limit_reached`, `session_start` / `admin_session_start`,
`session_attach`, `session_detach`, `session_end` / `admin_session_end` (reason,
exit code, duration, unexpected exit), `connection_timeout`, `spawn_failed`,
`control_denied`, `service_start` / `service_stop`. The Web UI also writes
`terminal.open`, `terminal.admin.open`, `terminal.admin.elevated`,
`terminal.reauth_failed`, `terminal.denied`, `terminal.kill` to the audit log
table.

**Keystrokes and output are never recorded** — they routinely contain
passwords and keys.

## 6. Differences from SSH

* Identity: you act as `micron-term` (standard) or `root` (admin), not as a
  per-person Unix account; the audit log ties the shell to your Web UI user
  (`MICRON_TERMINAL_WEBUSER` is set in the shell).
* No port forwarding, agent forwarding, X11 or file transfer.
* Sessions end on idle (keyboard) timeout; long jobs belong in `tmux`/`screen`
  (which keep running only until the session is ended) or a systemd unit.
* `sudo` is not configured for `micron-term`; use a root terminal.

## 7. Configuration (`/etc/micron/terminal.json`)

Optional; keys and defaults: `listen_host` 127.0.0.1, `listen_port` 7681,
`control_socket` /run/micron/terminal.sock, `control_group` www-data,
`standard_user` micron-term, `shell` /bin/bash, `max_sessions_per_user` 3,
`max_sessions_total` 16, `ticket_ttl` 30, `idle_timeout` 900,
`admin_idle_timeout` 600, `max_lifetime` 28800, `admin_max_lifetime` 3600,
`detach_grace` 120, `replay_bytes` 65536, `rlimit_*`. Restart the service
after editing.

## 8. Packaging (ISO and installed systems)

* `stage-overlay.sh` ships `system/micron-terminald` (mode 0755) and the vendored
  xterm assets with the application.
* The ISO hook and `install.sh` install `micron-terminald.service`, enable it,
  create the `micron-term` account, enable `proxy`, `proxy_wstunnel`, and add
  `ProxyPass /terminal/ws ws://127.0.0.1:7681/ws` to the management vhost.
* Works offline (no CDN), on the live ISO and after installation. On the live
  ISO, sessions and the audit log live in RAM and are lost on reboot.

## 9. Operations and troubleshooting

```bash
systemctl status micron-terminald
journalctl -u micron-terminald
microctl terminal status            # or: php spark terminal status
microctl terminal sessions
microctl terminal kill <session-id>
microctl terminal revoke <web-user-id>
```

* "Terminal service is not running" — `systemctl restart micron-terminald`.
* WebSocket closes immediately with 403 — Origin or client address mismatch
  (a proxy in front of the appliance must forward the original Host and Origin);
  see `auth_failed` in the audit log.
* Standard terminals fail to start — `getent passwd micron-term`; the status
  command warns when the account is missing.

## 10. Tests

* `tests/test_micron_terminald.py` (root): real service on a private port —
  tickets, Origin / address binding, single use, PTY I/O, resize, Ctrl+C,
  privilege separation, detach/reattach with replay, cross-user refusal, limits,
  idle timeout, process-group cleanup, revoke, malformed input, audit content
  and permissions, clean SIGTERM shutdown.
* `tests/app/Controllers/TerminalControllerTest.php`: permissions, root
  re-authentication and lockout, transport policy, ticket binding, owner-scoped
  kill, sign-out revocation, navigation visibility, offline assets.

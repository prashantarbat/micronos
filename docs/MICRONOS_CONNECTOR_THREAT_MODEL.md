# Micron Firewall OS (MicronOS) — Connector Abstraction Threat Model

## Adversarial Analysis of the Connector Design
### Master-Key Bootstrap & Backup, SSRF Boundaries on Feed URLs, and the Blast Radius of a Compromised Connector

Companion to the [Connector Abstraction Specification](MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.html).

---

## Table of Contents
1. [Scope, Method & Out of Scope](#1-scope-method--out-of-scope)
2. [Assets & Trust Boundaries](#2-assets--trust-boundaries)
3. [Adversary Model](#3-adversary-model)
4. [Focus 1 — Master Key: Bootstrap, Storage & Backup](#4-focus-1--master-key-bootstrap-storage--backup)
5. [Focus 2 — SSRF Boundaries on Feed URLs](#5-focus-2--ssrf-boundaries-on-feed-urls)
6. [Focus 3 — Blast Radius of a Compromised Connector](#6-focus-3--blast-radius-of-a-compromised-connector)
7. [Cross-Cutting: Integrity, Availability & Supply Chain](#7-cross-cutting-integrity-availability--supply-chain)
8. [Findings & Required Changes](#8-findings--required-changes)
9. [Residual Risk Register](#9-residual-risk-register)
10. [Verification Evidence](#10-verification-evidence)

---

## 1. Scope, Method & Out of Scope

**In scope:** the connector abstraction — manifest loader, credential store, sync engine, target adapters, and the Phase 0 RPCs (`system.service`, `dnsbl.*`) that the pipeline depends on.

**Method:** asset-driven adversarial analysis. For each adversary we enumerate what they can influence, what they gain, and what the design stops them from reaching. Severity uses a simple **impact × ease** judgement (High / Medium / Low) rather than a full CVSS scoring, because several threats are architectural rather than single-bug.

**Out of scope (pre-existing, not introduced here):**
- Web-application compromise via unrelated modules. This is treated as an *adversary capability* (§3, A6), not a connector defect.
- Kernel, systemd, Debian package and hardware compromise.
- The three pre-existing ad-hoc secret mechanisms (TOTP base64, `PppoeCompiler::encrypt`, backup password). This model covers the **new** `SecretsVault` only.
- Physical access to the appliance.

**One framing principle runs through this document:** the connector abstraction and its Phase 0 RPCs must not *widen* the privileged surface. Almost every High finding below is a case where that principle needs an explicit control rather than a property that already holds.

---

## 2. Assets & Trust Boundaries

### 2.1 Assets

| Asset | Why it matters |
| :--- | :--- |
| **Connector credentials** (Q-Feeds token, ZT/NetBird keys) | Third-party account takeover; may cost money and enable feed poisoning or tunnel hijack |
| **Master key** (`/etc/micron/secrets.key`) | Compromise ⇒ *all* stored credentials decrypt |
| **`connector_secrets` ciphertext** | Useless alone; valuable with the key or with a code-execution primitive in PHP |
| **DNSBL blocklist + address groups** | The security control itself — poisoning is a denial-of-service on the protected network |
| **Management plane** (CI4 app, session, SQLite DB) | Full appliance administration |
| **`micrond`** (root, on a Unix socket) | Privileged execution: nftables, systemd, config writes |
| **Appliance network position** | A firewall is a privileged outbound vantage point (multi-homed, often near management networks) |

### 2.2 Trust boundaries

```
   [ OPERATOR BROWSER ]                 untrusted network
            │  HTTPS + session
            ▼
   ┌──────────────────────────┐
 B1│  MANAGEMENT PLANE (PHP)  │  ← runs as www-data; holds the master key
   │  • manifest loader       │
   │  • SecretsVault          │
   │  • SyncScheduler/Fetcher │  ── B2 ──►  [ EXTERNAL FEED / VENDOR API ]
   └───────────┬──────────────┘             (fully untrusted input)
               │  JSON-RPC over Unix socket (0660 root:www-data)
          B3   │
               ▼
   ┌──────────────────────────┐
   │  DATA PLANE (micrond)    │  ← root; argv-only; whitelists
   └───────────┬──────────────┘
               │  B4
               ▼
      Unbound · nftables · systemd · filesystem
```

- **B1 — operator → management plane.** Enforced by session + `permission:` RBAC filters.
- **B2 — management plane → internet.** The SSRF boundary (§5). Untrusted data crosses back inbound.
- **B3 — management plane → micrond.** Notably, anything that can run code as `www-data` can invoke **any** micrond RPC — this predates connectors.
- **B4 — micrond → system.** Root; constrained only by the whitelists in the handlers.

---

## 3. Adversary Model

| # | Adversary | Capability | Primary interest |
| :--- | :--- | :--- | :--- |
| **A1** | Remote unauthenticated | Can reach the appliance's WAN; can host URLs | Probe, exploit SSRF, DoS |
| **A2** | Authenticated low-privilege operator | `connectors.view`, no `manage`/`secrets.manage` | Read config/status, no secrets or fetches |
| **A3** | Authenticated admin with `connectors.manage` | Configure connectors, set credentials, sync | No plaintext secret read-back |
| **A4** | Malicious / compromised feed provider | Controls the **content** of a fetched feed (and possibly the vendor host) | Poison policy; probe the fetch path |
| **A5** | Network attacker (on-path) | Intercept/modify fetch traffic | Feed poisoning via MITM |
| **A6** | Attacker with code execution as `www-data` | Full management-plane code exec (unrelated vuln) | Steal the key ⇒ all credentials; call any RPC |
| **A7** | Local unprivileged user | Read files not owned by root-only | Read key if perms are wrong |

---

## 4. Focus 1 — Master Key: Bootstrap, Storage & Backup

### 4.1 Design as specified

Key at `/etc/micron/secrets.key`, mode `0600` root:www-data, generated on first boot, **not** stored in the database; per-row `key_version` for rotation; AES-256-GCM with a fresh nonce per record.

### 4.2 Threats

| ID | Threat | Impact | Notes / Required control |
| :--- | :--- | :--- | :--- |
| **T1.1** | **The web user must be able to read the key**, so any PHP code execution (A6) yields *every* connector credential. | High | Inherent to fetching in the control plane. Reduce with: `0640 root:www-data` (no world/group-write), a dedicated key per deployment, and consider moving decryption into a narrow helper (see F1). |
| **T1.2** | **Key in backup.** If excluded, a restored appliance loses all credentials (connectors bricked). If included in a password-encrypted backup, a weak operator password leaks everything. | High | **Decided — see Spec §7.6.** The key travels *only* inside the password-encrypted payload; plaintext backups exclude the key **and** the `connector_secrets` rows, so an unencrypted archive is never a credential set. Password is credential-grade (min length, no login-password reuse). |
| **T1.3** | **Stolen disk.** Key and ciphertext sit on the same filesystem; without FDE/TPM binding the ciphertext is recoverable offline. | Medium | Recommend TPM-sealed key or operator passphrase unlock; state clearly if neither exists. |
| **T1.4** | **Bootstrap race / umask.** Creating the file then `chmod`-ing leaves a window where it is world-readable; a pre-existing attacker-owned symlink could redirect the write. | Medium | Create atomically with `O_EXCL` and `O_NOFOLLOW` semantics and mode `0600` at creation time; never write-then-chmod. |
| **T1.5** | **Rotation leaves the old key live.** `key_version` design keeps old ciphertext decryptable, so the old master key must be retained — a lingering secret. | Medium | Eagerly re-encrypt all rows during rotation, then destroy the old key; keep only a short overlap window. |
| **T1.6** | **Key loss ⇒ unrecoverable credentials.** | Medium | Operational runbook; document that recovery = re-issue every credential at the vendor. |
| **T1.7** | **AES-GCM nonce reuse.** Nonce reuse is catastrophic for GCM (authentication key recovery). A counter or derived nonce across multiple writers/restores can repeat. | High if it occurs | Mandate a CSPRNG 96-bit nonce per encryption; store it alongside the ciphertext; never derive it from the row id. |
| **T1.8** | **Plaintext key/secrets in swap or core dumps.** | Medium | On the appliance, disable swap or encrypt it; zero secret buffers after use. |
| **T1.9** | **Secret leakage through logs/errors.** Debug dumps, exception messages, or `audit_logs` capturing the settings payload could include a token. | Medium | Redaction list derived from manifest `secret: true` fields, applied centrally; ensure exception handlers never serialize the vault. |
| **T1.10** | **Mask round-trip.** The settings form shows a masked placeholder; if it is ever submitted verbatim it silently replaces the real credential with `••••`. | Low (availability) | Treat "unchanged" as an explicit client signal, not a value comparison. |
| **T1.11** | **No read-back path.** `connectors.secrets.manage` must never decrypt to the browser, and there should be no "reveal" endpoint at all. | — | Assert by test: no route returns a decrypted value. |

### 4.3 Residual risk (accepted)

A6 (PHP RCE) ⇒ all connector credentials, **provided the key file permissions are correct**. This is accepted because fetching must happen in the control plane. The mitigating architectural option — having `micrond` decrypt and return a single derived value — was **rejected in the spec** (§7) because it puts plaintext credentials into the root daemon, which is a worse trade. If that trade is revisited, it must be revisited explicitly.

---

## 5. Focus 2 — SSRF Boundaries on Feed URLs

### 5.1 The two entry points, and why both are mitigated today

- **Manifest-defined URLs (new connectors):** authored first-party, validated against a manifest allowlist at load. Good.
- **Operator-supplied URLs (existing `dns_feeds`, and any future "custom feed" UI):** **now guarded too.** A typed feed URL is rejected at entry by `ConnectorEgressGuard::checkSyntax()` (https-only, no literal internal address, no internal hostname) and is then fetched only through the full resolve-and-pin pipeline in `ManualFeedIngestor`, so the operator URL field is no longer an SSRF surface and no longer relies on the manifest allowlist to be safe.

A firewall is a *maximally* useful SSRF vantage point: it is multi-homed, often adjacent to management networks, and frequently able to reach cloud metadata endpoints.

### 5.2 Threats

| ID | Threat | Impact | Required control |
| :--- | :--- | :--- | :--- |
| **T2.1** | Request to internal/loopback/metadata targets (`http://127.0.0.1:8080/`, `http://169.254.169.254/…`, `http://10.x/`) — blind SSRF; success/timing/error text leak internal state. | High | Resolve the host, validate **all** returned addresses against a denylist (loopback, RFC1918, link-local incl. `169.254.0.0/16`, CGNAT `100.64.0.0/10`, multicast, reserved), then **connect to the validated IP** (pin it). |
| **T2.2** | **DNS rebinding** — benign resolution at validation, internal address at connect. | High | Pin the resolved IP for the actual connection; never validate-then-re-resolve. |
| **T2.3** | **Redirect pivoting** — an allowed host 302s to an internal address. | High | Cap redirects (e.g. 5) and re-validate **every** hop with the same rules. |
| **T2.4** | Dangerous schemes (`file:`, `gopher:`, `dict:`, `ftp:`, `data:`). | High | Scheme allowlist: `https` only (optionally `http`), applied before any client call. |
| **T2.5** | **Egress origination.** The fetch may leave via an internal interface and reach management networks. | Medium | Bind fetch to the WAN source or constrain the appliance's own outbound with a firewall rule limited to known feed hosts. |
| **T2.6** | **Credential leakage on redirect.** Bearer/basic auth forwarded to a redirect target controlled by someone else. | Medium | Strip `Authorization` on cross-origin redirects; refuse cross-origin redirects outright for `basic`/`header` auth. |
| **T2.7** | **Decompression bomb / huge body** exhausting PHP memory. | Medium | Size cap on the *compressed* and *decompressed* stream, plus a decompression-ratio cap; stream to the staging file rather than buffering. |
| **T2.8** | **TLS downgrade / disabled verification.** | Medium | Verification mandatory; no `-k`/`verify=false` escape hatch. Optionally pin vendor certificates. |
| **T2.9** | **Body/error echo.** Returning the fetched body or upstream error text to the UI turns blind SSRF into a read primitive. | Medium | Surface only status code, byte count and entry count — never response bodies. |
| **T2.10** | **Time-of-check on manifest hosts.** A permitted hostname's DNS can change after validation. | Low | Re-validate at each fetch (same guard as T2.1). |

### 5.3 Required component: an egress guard

All connector fetches — manifest-defined **and** operator-supplied — must pass through one guard with a fixed pipeline:

```
 1. scheme ∈ {https}                      (reject file:, gopher:, dict:, data:, ftp:)
 2. resolve host once → validate EVERY address against the denylist
 3. connect to the pinned validated address (no re-resolution)
 4. redirects: max N, each hop re-runs steps 1–3; strip auth cross-origin
 5. stream caps: bytes-in, decompressed bytes, compression ratio
 6. TLS verification mandatory; no insecure fallback
 7. never return the body — only counts and status
```

**Status: implemented.** `App\Libraries\ConnectorEgressGuard` is the single fetch path, and `App\Connectors\Targets\FetchTarget` is the only connector code that reaches the network — so every feed fetch (`dnsbl`, `addressgroup`, `pki`) passes through the pipeline above. The transport is pinned with `CURLOPT_RESOLVE`; the resolver and transport are injectable so the redirect/cap/pinning logic is verified without network access (`tests/app/Libraries/ConnectorEgressGuardTest.php`).

Two controls remain caller responsibilities and are noted rather than enforced here: the WAN-origination firewall rule (T2.5), and the manifest host allowlist (T2.10), which is a second line of defence, not a substitute for this guard.

---

## 6. Focus 3 — Blast Radius of a Compromised Connector

"Compromised connector" is two distinct situations with very different severities. They must not be conflated:

- **(a) Poisoned data** — the attacker controls feed *content*, not code. The connectors are running correctly.
- **(b) Compromised runtime/credential** — the attacker controls the connector's credential or the code path that executes it.

### 6.1 Containment matrix

| Vector | What the attacker gains | What the design prevents |
| :--- | :--- | :--- |
| **(a) Malicious feed content** | Inject arbitrary *validated* hostnames into the DNSBL; inject *validated* IPs/CIDRs into the group it owns | **Cannot execute code** — parser set is a closed enumeration. **Cannot inject config syntax** (see §10, verified). **Cannot choose a target** — fixed blocklist path and a namespaced group. **Cannot escape its group** — `managed_by` scoping. |
| **(b) Stolen vendor token** | Read the vendor's feed; possibly change vendor-side account settings | Reaches only the third-party account; no local privilege. Rotation is independent of the master key. |
| **(b) Compromised PHP code path** | Read the master key ⇒ **all** connector credentials | No *new* privilege beyond what PHP already had: **B3 already grants every `micrond` RPC** to `www-data`. Connectors do not widen this. |
| **(b) Malicious manifest** | — | **Not reachable in Phases 1–3**: manifests are first-party, never fetched, validated at boot. This is why §16 defers any dynamic/remote descriptor. |
| **(b) Compromised `micrond`** | Full root | Out of scope — root compromise is game over regardless of connectors. |

### 6.2 What a connector can reach — the containment argument

A connector instance, even fully malicious, cannot:

- **Name an arbitrary file:** `_dnsbl_artifacts` requires the path to `realpath`-resolve inside `/var/lib/micron/dnsbl`.
- **Name an arbitrary command:** all execution is argv-only through `_run`; never a shell, never interpolation.
- **Name an arbitrary service:** `system.service` resolves the id through the static `SERVICE_UNITS` map (`openvpn` → `openvpn@server`), and only `start`/`stop`/`restart` are accepted.
- **Invent a route or permission:** manifests are static and pages are static routes; nothing is registered at runtime.
- **Read another connector's secret:** secrets are per-instance rows, and no endpoint decrypts to the browser.

### 6.3 The `service` target can disable the packet filter — **fixed**

`SERVICE_UNITS` includes `nftables` and `system.service` permits `stop`, so a connector whose manifest names `nftables` could stop the firewall — an availability catastrophe reachable purely from poisoned configuration. The original handler emitted a *warning* but did not refuse.

**Resolution.** The connector path now uses a separate RPC, `connector.service`, held to a strict `CONNECTOR_SAFE_UNITS` allowlist (`squid`, `c-icap`, `clamav`, `igmpproxy`, `mdns-repeater`) — **default deny**, so `nftables`, `unbound`, `strongswan`, `wireguard`, `keepalived`, FRR and every other infrastructure daemon are refused *with no command run*. The admin path (`system.service`, driven by the authenticated Services UI) keeps the full whitelist, so an operator can still restart `nftables`.

A separate method was chosen over a `scope` flag deliberately: a caller able to set a flag can also clear it, so only a distinct method name makes the restriction non-bypassable by connector-supplied data. The connector adapter selects the method; the manifest only selects the service id. Verified by the `connector.service` checks in `tests/test_micrond_phase0.py`, including a regression guard that the admin path still controls critical units.

**Now on a live path.** `App\Connectors\Targets\ServiceTarget` holds the RPC name as a class constant (never manifest-derived) and calls `connector.service`; `Manifest::validateSource()` refuses any key on a `service` source other than `service`/`action`, so a manifest cannot name the RPC at all. `tests/app/Libraries/ConnectorEngineTest.php` asserts the engine emits `connector.service` and never `system.service`, and that a micrond allowlist refusal is surfaced rather than swallowed.

### 6.4 Finding: cross-connector overwrite

If `connector_targets.target_ref` is not namespaced by instance id, connector B could name connector A's feed/group. The spec already scopes membership with `managed_by`; make ref naming (`feed_<instance>`, `group_<instance>`) enforced by the adapter, not by convention.

---

## 7. Cross-Cutting: Integrity, Availability & Supply Chain

| ID | Threat | Impact | Notes |
| :--- | :--- | :--- | :--- |
| **X1** | **Integrity of the security control.** Feed poisoning is a *denial*, not a *redirection*: the compiled form is `local-zone "<domain>" always_nxdomain`. The dangerous inversion is an **allow-list** set: if a connector ever populates a set referenced by an *allow* rule, poisoning becomes a bypass. | High | Policy: connector-populated sets may only be referenced from *block* rules; enforce in the rule editor. |
| **X2** | **Silent staleness.** An attacker (or an outage) that makes every fetch fail leaves the operator protected by old data, believing it is current. | Medium | Freshness is explicit and surfaced (badge/alert); consider an SNMP trap per architecture §24. |
| **X3** | **Resource exhaustion.** A large feed exhausts PHP memory during parsing or bloats the blocklist. Caps exist in `micrond` (2M entries, 8 MB/artifact); the PHP side needs its own cap and streaming. | Medium | Add PHP-side streaming + caps in Phase 1. |
| **X4** | **`unbound-checkconf` is the only correctness gate.** A structurally valid but semantically harmful blocklist (e.g. blocking the appliance's own management domain) passes. | Medium | Post-apply sanity check: refuse to block the appliance's own hostname/domain and its configured DNS names. |
| **X5** | **Conflict with the second ingest path.** The `dnsbl-update` cron job and the new RPCs are two mechanisms; divergent behaviour is itself a security risk (one may bypass the validation the other enforces). | Medium | Reconcile before Phase 1 (see Findings). |
| **X6** | **Audit blind spots.** Connector actions must land in `audit_logs` using its real columns — there is **no `details` column**, and several controllers silently swallow inserts into it. | Medium | **Closed.** The six writers that used `details` (Cron, Services, Siem, Snmp, Dashboard, RepoDriftCommand), the two that inserted a hex `id` into an integer key (Roles, Users), and the eleven that omitted the table's NOT NULL columns now write the real columns — `tests/app/Models/ModelSchemaConsistencyTest.php` fails on any writer that regresses, and on any insert that omits a required column. |

---

## 8. Findings & Required Changes

Ordered by severity. "Spec §" points at where the design should be amended.

| # | Severity | Finding | Required change |
| :--- | :--- | :--- | :--- |
| **F1** | High | Any PHP RCE yields **all** connector credentials, because the key must be readable by `www-data`. | Accept explicitly, document it, and harden: `0640 root:www-data`, `O_EXCL`+`O_NOFOLLOW` atomic create, per-deployment key. Revisit the rejected "decrypt in micrond" trade in writing. (Spec §7) |
| **F2** | High | ~~The `service` target can **stop `nftables`** (and other safety-critical units), disabling the packet filter.~~ | **Implemented** — `connector.service` enforces the strict `CONNECTOR_SAFE_UNITS` allowlist (default deny); the admin path keeps the full whitelist. (Spec §9.3) |
| **F3** | High | ~~**Operator-supplied feed URLs are a live SSRF surface**; the manifest allowlist mitigates only new connectors.~~ | **Implemented** — the `ConnectorEgressGuard` (§5.3) is the single fetch path for all connector sources, with an https-only scheme allowlist, resolve-and-pin, per-hop redirect validation and stream/ratio caps. Manual `dns_feeds` URLs are checked with `checkSyntax()` at entry (rejected if non-https or internal) and fetched on sync only through `ManualFeedIngestor`, so operator input shares the manifest path's controls. (Spec §13) |
| **F4** | High | AES-256-GCM **nonce reuse** would be catastrophic and is easy to introduce. | Mandate CSPRNG 96-bit nonce per encryption; store it; add a test that two writes of the same plaintext produce different nonces/ciphertext. (Spec §7) |
| **F5** | Medium | ~~DNS rebinding and redirect pivoting defeat naive URL validation.~~ | **Implemented** — the host is resolved once and every address is validated against the denylist; the connection is pinned to the validated IP via `CURLOPT_RESOLVE`; every redirect hop re-runs the full check; auth is stripped cross-origin (and refused when sensitive). (Spec §13) |
| **F6** | Medium | ~~**Master key in backup** is unresolved: either leak-by-weak-password or unrecoverable credentials.~~ | **Implemented (Spec §7.6)** — `BackupEngine` carries the key and `connector_secrets` only inside the password-encrypted envelope; plaintext backups exclude both; restore installs/matches/only-with-confirmation replaces the key, skips connector ciphertext when no usable key exists, and decryption-verifies every restored secret. Covered by `tests/app/Libraries/BackupConnectorPolicyTest.php`. |
| **F7** | Medium | Cross-connector overwrite if `target_ref` is not namespaced. | Namespace refs by instance id in the adapter, not by convention. (Spec §6.4, §9.2) |
| **F8** | Medium | Silent staleness misleads the operator. | Surface freshness prominently and consider an SNMP trap. (Spec §14) |
| **F9** | Medium | Two DNSBL ingest paths with different validation guarantees. | Reconcile `dnsbl-update` vs the new RPCs; one validator, one path. (Spec §17.1) |
| **F10** | Low | Swap/core-dump exposure of plaintext keys and secrets. | Disable/encrypt swap; zero buffers. (Spec §7) |
| **F11** | Low | Log/error leakage and mask round-trip. | Central redaction from manifest `secret` flags; explicit "unchanged" signal. (Spec §7, §12) |
| **F12** | Low | Masked-blocklist content is only syntactically validated. | Refuse to block the appliance's own identity. (Spec §9.1) |

---

## 9. Residual Risk Register

| Risk | Rationale for acceptance | Owner action |
| :--- | :--- | :--- |
| A6 ⇒ all credentials | Fetch must occur in the control plane; moving decryption to `micrond` is worse | Accept + document; F1 hardening |
| A5 on-path feed tampering | TLS verification + vendor authenticity; no pinning by default | Accept; optional pinning per connector |
| Poisoned data ⇒ selective DoS | Inherent to any external feed; block-only semantics bound it | Accept; policy rule X1 |
| No Linux data plane in this repo | Cannot live-test `systemctl`/Unbound integration | Integration testing on the appliance |

---

## 10. Verification Evidence

The following claims were checked against the implementation rather than asserted:

1. **Feed content cannot become Unbound config syntax.** The compiled directive interpolates a token that must match a strict hostname pattern, and line parsing takes only the last whitespace-delimited token. Injection attempts in a staged artifact —
   `evil.example.com" always_nxdomain`, `good.example.com local-zone: "x" always_nxdomain`, and `local-zone: "injected.example.net" always_nxdomain` — all fail validation and are dropped, while a benign entry still compiles. Covered by three checks in `tests/test_micrond_phase0.py`.
2. **Path containment and refusal behaviour.** A path outside the staging directory is refused with no file written; a missing artifact is refused; unknown services and unsupported actions run **no** command. Covered by the same harness.
3. **F2 hardening holds.** `connector.service` refuses `nftables`, `unbound`, `strongswan`, `wireguard`, `keepalived` and `frr` with **no command executed**, while allowing the connector-safe daemons; and the admin `system.service` path still controls those units, so the hardening introduces no operator regression. Covered by the same harness.
4. **Plaintext backups are reachable today.** `BackupEngine::createBackup(?string $encryptionPassword = null)` accepts a null password and emits a plaintext JSON payload with an `is_encrypted` flag, which is why the backup policy (Spec §7.6, R2) must actively strip the key and the ciphertext rows from unencrypted archives rather than assuming every archive is encrypted.
5. **The egress guard refuses internal targets and cannot be re-resolved or pivoted.** With the resolver and transport injected, `ConnectorEgressGuardTest` shows: `file:`/`gopher:`/`dict:`/`data:`/`ftp:`/`http:` never reach the transport; a host resolving to any internal address (loopback, RFC1918, link-local `169.254.169.254`, CGNAT, multicast, IPv6 ULA/link-local, IPv4-mapped) is refused outright; the host is resolved exactly once and the connection is pinned to the validated IP; a redirect to an internal host is refused before it is contacted; redirect loops are capped; `Authorization` is stripped on cross-origin hops and refused outright when marked sensitive; decompressed/compressed/ratio caps abort the stream and leave no partial artifact; and the response body is never returned.

Everything else in this threat model is design-level reasoning. **F2, F3, F5 and F6 are implemented**; the remaining findings (F1, F4, F7–F12) concern the `SecretsVault` CRUD/rotation and the connector engine, which do not exist yet, so they are **requirements to implement**, not defects observed in running code.

---

*This is a threat model. No implementation code is included, and no application files are modified by this document.*

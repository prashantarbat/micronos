# Micron Firewall OS (MicronOS) — Connector Abstraction Specification

## Subscribed Feed, Cloud Service & Integration Connectors
### Manifest Format, Encrypted Secret Store, Scheduled Sync Engine, and Target Application (DNS Blocklists & Firewall Address Groups)

---

## Table of Contents
1. [Executive Summary](#1-executive-summary)
2. [Relationship to the Existing Architecture](#2-relationship-to-the-existing-architecture)
3. [Design Goals, Non-Goals & Principles](#3-design-goals-non-goals--principles)
4. [Architecture Overview](#4-architecture-overview)
5. [Connector Manifest Format](#5-connector-manifest-format)
6. [Data Model](#6-data-model)
7. [Encrypted Secret Store](#7-encrypted-secret-store)
8. [Sync Engine & Lifecycle](#8-sync-engine--lifecycle)
9. [Target Application](#9-target-application)
10. [IPC Surface (micrond RPCs)](#10-ipc-surface-micrond-rpcs)
11. [Permissions & RBAC](#11-permissions--rbac)
12. [Management UI & Registration](#12-management-ui--registration)
13. [Security Model](#13-security-model)
14. [Failure Handling, Idempotency & Limits](#14-failure-handling-idempotency--limits)
15. [Reference Connectors](#15-reference-connectors)
16. [Phased Implementation Roadmap](#16-phased-implementation-roadmap)
17. [Risks & Open Questions](#17-risks--open-questions)
18. [Appendix A — Manifest JSON Schema](#appendix-a--manifest-json-schema)
19. [Appendix B — DDL](#appendix-b--ddl)

---

## 1. Executive Summary

MicronOS already downloads public threat and service feeds and distributes them locally: the **Internet Service Database** (§13 of the architecture document) resolves vendor-published prefix lists and emits nftables feed files, and the **DNSBL Threat Shield** (§22) syncs domain blocklists through the `dnsbl-update` job. Both follow one principle — **resolve once, distribute locally**.

That pipeline, however, only handles **anonymous, unauthenticated, public-URL** feeds, and it only knows two targets (`firewall_addresses` / nftables sets, and the Unbound blocklist). What is missing is a general way to attach a **subscribed, credentialed, or protocol-speaking external service** — Q-Feeds, abuse.ch, a ZeroTier/NetBird controller, ACME directories — and to apply its data into *any* local primitive.

This document specifies the **Connector Abstraction**: a manifest-driven registry of connectors, an encrypted secret store, a cron-driven sync engine, and a pluggable set of **targets** (DNS blocklist, firewall address group, systemd service, TLS certificate). It is the generalisation of the existing feed pipeline, not a parallel subsystem.

Two seams are already stubbed in the codebase and are completed by this work:

- `dnsbl.update` — called by the DNS controller, **not implemented** in `micrond`.
- `system.service` — called by the Services controller, **not implemented** in `micrond`.

Both currently fail as *Method not found* while the UI reports success. Any connector that applies to DNS or restarts a daemon depends on these two RPCs existing.

---

## 2. Relationship to the Existing Architecture

The connector layer is assembled almost entirely from primitives that already exist. This table is the inventory that the design builds on, including the verified gaps.

| Concern | Existing primitive | Status |
| :--- | :--- | :--- |
| Control/data plane split | CI4 management plane ↔ `micrond` (root, argv-only `_run`, no shell) | ✅ Exists |
| Feed *subscription* record | `dns_feeds` (`id`, `name`, `url`, `category`, `entries_count`, `enabled`, `last_updated`) | ✅ Exists |
| DNSBL ingest/compile | `dnsbl.update` RPC + `/usr/local/bin/dnsbl-update --reload-unbound` | ⚠️ RPC **missing** in `micrond`; script not in tree |
| Unbound config | `UnboundDnsCompiler`, `dns_settings` (DoT upstreams), `dns_overrides` | ✅ Exists (overrides only — does **not** consume feeds) |
| IP/domain objects | `firewall_addresses` (`type` = ipmask/iprange/fqdn/internet-service/geoip), `firewall_address_groups` (`members` JSON) | ✅ Exists |
| Scheduled work | `cron_jobs` (`minute`..`day_of_week`, `user`, `command`, `enabled`, `last_run_at`, `last_status`, `last_output`) | ✅ Exists |
| Daemon lifecycle | `system_services` registry, `Services` controller, `ServiceStatus::widget()` | ✅ Exists; `system.service` RPC **missing** |
| ACL | `permissions` (`key` unique, `label`, `module`, `description`) | ✅ Exists |
| Audit | `audit_logs` (`action`, `action_type`, `target`, `old_state`, `new_state`, `user_id`, `ip_address`) | ✅ Exists (there is **no `details` column**; the writers that used one are fixed and the shape is now enforced by `tests/app/Models/ModelSchemaConsistencyTest.php`) |
| Transactional rollback | `SafeTransactionManager` | ⚠️ nftables-only today |
| Secret storage | ad-hoc: TOTP base64, `PppoeCompiler::encrypt()`, `Backup` AES-256-GCM | ⚠️ **No single store** |

The conclusion: the connector abstraction is roughly 70% assembly and 30% new machinery. The new machinery is a registry, a secret store, a sync engine, and a target adapter interface.

---

## 3. Design Goals, Non-Goals & Principles

### Goals
- Attach external services and feeds by **manifest**, not by bespoke controller code.
- Support **authenticated** sources (API tokens, HTTP basic, bearer) with credentials held in one encrypted store.
- Apply fetched data into **multiple target types**, starting with DNS blocklists and firewall address groups.
- Be **fully scheduled** (cron-driven), **audited**, and **idempotent**.
- Remain **offline-safe**: the appliance must keep filtering with the last successfully applied data if the internet is unreachable.
- Keep the **privilege boundary**: PHP never executes privileged work directly; all writes and reloads go through `micrond`.

### Non-Goals
- Installing arbitrary Debian packages. This document deliberately excludes a generic `apt`-driven package manager; that is a separate, higher-risk capability (§16, Phase 4) and must not be the first step.
- Kernel-module integrations and commercial agents requiring a licensed daemon. These are vendor integrations, not connectors.
- Storing or proxying user traffic. Connectors move *policy data*, not packets.

### Principles
1. **Resolve once, distribute locally.** (Inherited from §13.) Never block packet filtering or DNS resolution on an external request.
2. **The control plane fetches and normalises; the data plane applies.** PHP owns HTTP, parsing, and validation; `micrond` owns privileged writes and reloads.
3. **Data is staged, then atomically promoted.** A sync either produces a complete new artifact or leaves the previous one untouched.
4. **Fail closed on secrets, fail open on freshness.** A missing credential blocks *configuration*, but a stale feed keeps serving its last good data.
5. **Everything is attributed.** Every apply writes an `audit_logs` row with the connector instance id.

---

## 4. Architecture Overview

```
                     ┌──────────────────────────────────────────────────────┐
                     │              MANAGEMENT PLANE (CI4 / PHP)             │
                     │                                                       │
  Cron tick ────────►│  SyncScheduler ──► ConnectorRunner                    │
 (cron_jobs row)     │                       │                              │
                     │        ┌──────────────┼──────────────┐               │
                     │        ▼              ▼              ▼               │
                     │   Fetch (HTTP)   Normalise      Validate             │
                     │   + Secret ◄── SecretsVault                       │
                     │        │              │              │               │
                     │        └──────────────┴──────────────┘               │
                     │                       ▼                              │
                     │             Staging artifact (JSON/text)             │
                     │                       │                              │
                     │        connector_sync_log (status, counts)           │
                     └───────────────────────┼──────────────────────────────┘
                                             │ IPC (JSON-RPC over Unix socket)
                                             ▼
                     ┌──────────────────────────────────────────────────────┐
                     │              DATA PLANE (micrond, root)               │
                     │                                                       │
                     │   Target adapters:                                    │
                     │     dnsbl.apply        → Unbound blocklist + reload   │
                     │     addressgroup.sync  → local feed file + nft set    │
                     │     system.service     → systemctl start/stop/restart  │
                     │     pki.deploy         → cert write + service reload   │
                     └──────────────────────────────────────────────────────┘
                                             │
                                             ▼
                        Unbound · nftables · systemd units · service reloads
```

**Fetch location decision.** The authenticated HTTP fetch happens in the management plane (PHP), because: (a) it keeps tokens out of the root daemon's process; (b) parsing/validation is easier to unit-test in PHP alongside the existing compilers; (c) the control plane is already the component that talks to the outside world for service sync (`ServiceDatabase`). `micrond` receives *already-normalised* data and performs only whitelisted, privileged application.

---

## 5. Connector Manifest Format

A connector is described by a static `manifest.json`. Manifests are **first-party, shipped with the appliance** (Phase 1–3). They are never fetched at runtime — fetching executable descriptors over the network would defeat the security model (§13).

```jsonc
{
  "schema": 1,
  "id": "q-feeds",
  "name": "Q-Feeds Threat Intelligence",
  "vendor": "Q-Feeds",
  "version": "1.0.0",
  "description": "Curated EU IP and domain threat-intelligence feeds.",
  "category": "Threat Intelligence",
  "icon": "shield-alert",
  "includes": [],                        // related system_services ids, if any

  "auth": {
    "type": "bearer",                 // none | bearer | basic | header | query
    "fields": [
      { "key": "api_token", "label": "API Token", "secret": true,
        "help": "Create a key at tip.qfeeds.com → Manage API Keys." }
    ]
  },

  "options": [
    { "key": "register_domain_feeds", "type": "bool", "default": true,
      "label": "Publish domain feeds to the DNS blocklist" },
    { "key": "allowlist_domains", "type": "list", "default": [],
      "label": "Allowlist domains (regex supported)" },
    { "key": "source_nets", "type": "list", "default": [],
      "label": "Restrict policy to client networks (empty = all)" }
  ],

  "schedule": { "default": "0 */6 * * *" },   // written into cron_jobs

  "sources": [
    { "id": "malware-ip", "target": "addressgroup",
      "url": "https://tip.qfeeds.com/api/v1/feeds/malware-ip",
      "format": "plain-ip-list",
      "group_name": "__qfeeds_malware_ip" },
    { "id": "malware-domain", "target": "dnsbl",
      "url": "https://tip.qfeeds.com/api/v1/feeds/malware-domain",
      "format": "plain-domain-list",
      "feed_name": "Q-Feeds Malware Domains",
      "category": "High-Risk Cyber Threats" }
  ],

  "status_url": "https://tip.qfeeds.com/api/v1/subscription",
  "docs_url": "https://docs.opnsense.org/manual/qfeeds.html",
  "permissions": ["connectors.manage", "connectors.secrets.manage"]
}
```

### Manifest field semantics

| Field | Meaning |
| :--- | :--- |
| `id` | Stable slug; becomes the connector namespace and permission prefix. |
| `auth` | Declarative credential form. `secret: true` fields land in the secret store, never the settings row. |
| `options` | Non-secret configuration rendered as a form. |
| `schedule` | Default cron expression; copied into a `cron_jobs` row on enable. |
| `sources[].target` | One of `dnsbl`, `addressgroup`, `service`, `pki`. Selects the data-plane adapter. |
| `sources[].format` | A **closed enumeration** of parsers: `plain-ip-list`, `plain-domain-list`, `hostfile`, `json-ip-list`, `json-domain-list`, `csv`, `rfc1035-spf`. Unknown formats are rejected at load time. All are implemented in `App\Connectors\FeedParser`; each yields a canonical domain or CIDR set with per-entry validation and drop counts. |
| `includes` | Advisory: related `system_services` ids (for the page-header widget). |

Manifests are loaded from `app/Connectors/definitions/<id>/manifest.json`, validated against the schema in Appendix A, and cached. A manifest that fails validation is refused at boot, not at first use.

---

## 6. Data Model

Four new tables plus two small extensions. DDL is in [Appendix B](#appendix-b--ddl).

### 6.1 `connector_instances`
One row per configured connector. Holds non-secret configuration and enablement.

- `id` (VARCHAR 36, PK)
- `connector_id` (VARCHAR 64) — manifest id
- `name` (VARCHAR 128) — operator label
- `enabled` (TINYINT)
- `settings` (TEXT/JSON) — the `options` values
- `schedule_override` (VARCHAR 64, nullable)
- `last_sync_at`, `last_status` (`success`/`failed`/`running`/`never`), `last_message`
- `created_at`, `updated_at`

### 6.2 `connector_secrets`
One row per secret *field per instance*, so rotation is per-field.

- `id` (VARCHAR 36, PK)
- `instance_id` (VARCHAR 36, FK → `connector_instances.id`, ON DELETE CASCADE)
- `field_key` (VARCHAR 64)
- `ciphertext` (TEXT) — base64 of AES-256-GCM
- `nonce` (VARCHAR 32) — base64, 12 bytes
- `tag` (VARCHAR 32) — base64, 16 bytes
- `key_version` (INT) — supports master-key rotation
- `created_at`, `rotated_at`

Unique index on `(instance_id, field_key)`.

### 6.3 `connector_sync_log`
Append-only audit of sync runs (distinct from `audit_logs`, which records *operator* actions).

- `id` (INTEGER PK autoincrement)
- `instance_id` (VARCHAR 36)
- `source_id` (VARCHAR 64, nullable — per-source granularity)
- `started_at`, `finished_at` (DATETIME)
- `status` (`success`/`failed`/`partial`)
- `items_in` (INT), `items_out` (INT), `bytes_in` (INT)
- `message` (TEXT)

### 6.4 `connector_targets`
Binds a connector source to the concrete local object it manages, so the object is marked as externally-owned and not clobbered by manual edits.

- `id` (VARCHAR 36, PK)
- `instance_id` (VARCHAR 36)
- `source_id` (VARCHAR 64)
- `target_type` (`dnsbl`/`addressgroup`/`service`)
- `target_ref` (VARCHAR 128) — e.g. `feed_qfeeds_domains` or `group_qfeeds_ip`
- `managed` (TINYINT, default 1) — 1 = connector owns membership
- `created_at`, `updated_at`

### 6.5 Extensions to existing tables
- `dns_feeds` — add `connector_instance_id` (VARCHAR 36, nullable, FK) and `source_id` (VARCHAR 64, nullable). Existing manual feeds keep both NULL.
- `firewall_address_groups` — add `managed_by` (VARCHAR 36, nullable). Rows created by a connector carry the instance id; the UI hides or locks manual member editing for them.

---

## 7. Encrypted Secret Store

A single canonical store replaces today's three ad-hoc approaches.

**Algorithm.** AES-256-GCM (AEAD), matching the existing `Backup` engine, so no new crypto dependency is introduced.

**Master key.** A 32-byte key at `/etc/micron/secrets.key`, mode `0600`, owner `root:www-data`, generated on first boot. It is **not** stored in the SQLite database — a database dump or backup must not yield plaintext credentials. The key is read by the management plane at request time.

**Envelope & versions.** Each secret stores `key_version`. Rotation writes a new master key, re-encrypts rows lazily (or eagerly via a `connectors.secrets.rotate` task), and bumps `key_version`. Old ciphertext remains decryptable by version until re-encrypted.

**Access path.**
```
ConnectorRunner
    └─ SecretsVault::get(instanceId, 'api_token')
           ├─ SELECT ciphertext, nonce, tag, key_version
           ├─ key = loadMasterKey(key_version)
           └─ openssl_decrypt(AES-256-GCM)  → plaintext (in-memory only)
```

**Rules.**
- Secrets are **never** returned to the browser. The settings form shows a masked placeholder and submits a value only when the operator types a new one.
- Secrets are **never** written to `audit_logs`, `connector_sync_log`, or application logs. The redactor strips any key listed as `secret: true` in the manifest.
- Secrets are **never** passed to `micrond`. The data plane receives normalised data only; this is why the fetch lives in the control plane.
- Compromise response: rotate the master key and the provider credential; both are independent operations.

**Note on the current state.** TOTP secrets are base64 (`Auth::verifyTotp`), PPPoE passwords use `PppoeCompiler::encrypt()`, and backups use an operator-supplied password. This specification does **not** retro-fit those (out of scope), but the `SecretsVault` is defined so they can adopt it later without a schema break.

### 7.6 Backup, Restore & Key Recovery Policy

**Decision (threat-model finding F6): the connector master key travels *only* inside the password-encrypted backup payload. It is never present in a plaintext backup.**

#### Why

A backup contains the whole database, including the `connector_secrets` **ciphertext**. If the key travelled alongside that ciphertext in a plaintext backup, the archive would be a complete, self-contained credential leak. Equally, excluding the key from *every* backup would make disaster recovery impossible without re-issuing every credential. The policy resolves this by binding the key to the encryption password: the archive alone is useless, and the archive plus the password is a full, restorable recovery set.

This matters because the current `BackupEngine` accepts a **null** password (`createBackup(?string $encryptionPassword = null)`) and writes a plaintext JSON payload — so an unencrypted backup is a supported, reachable case today.

#### Rules

| # | Rule |
| :--- | :--- |
| **R1** | **Password-encrypted backup:** include the connector master key *and* the `connector_secrets` rows, all inside the AES-256-GCM envelope. `is_encrypted = 1`. |
| **R2** | **Plaintext backup (no password):** exclude the master key **and** the `connector_secrets` rows entirely, and record that credentials were omitted so the restore path can say so plainly. A plaintext backup therefore never contains a connector credential or the means to read one. |
| **R3** | The key is **never** persisted into the database, written to logs, or embedded in a filename, description, or `system_backups` row. Restore writes it to the key-file path only. |
| **R4** | The key is never returned to the browser and never sent to `micrond` (see §7). |
| **R5** | The backup password is **credential-grade**. Enforce a minimum length, reject reuse of a login password, and state this in the UI at the point of entry. |

#### Payload shape

The key travels in a dedicated top-level `secrets` object, deliberately **outside** `tables`:

```jsonc
{
  "format": 2,
  "created_at": "2026-10-05T12:00:00Z",
  "is_encrypted": true,
  "credentials_included": true,
  "secrets": {                 // present ONLY inside the encrypted envelope
    "master_key": "<32 bytes, hex>",
    "key_version": 1,
    "created_at": "2026-10-05T12:00:00Z"
  },
  "tables": { "connector_instances": [], "connector_secrets": [] }
}
```

Keeping `secrets` out of `tables` means a restore can never insert key material into a database table by accident — the restore path must handle it explicitly or ignore it.

#### Restore-time key handling

| Appliance state | Payload carries a key | Action |
| :--- | :--- | :--- |
| No key present | yes | Install it (mode `0600`, owner `root:www-data`, atomic `O_EXCL` create) |
| Same key | yes | No-op |
| **Different key** | yes | Require an explicit *“replace the connector master key”* confirmation. Without it, **skip** the connector tables rather than writing undecryptable ciphertext, and mark those connectors `needs_credentials` |
| Any | **no** | Restore configuration normally, mark every connector `needs_credentials`, and report it plainly |

**Post-restore verification is mandatory.** Attempt to decrypt every `connector_secrets` row; any failure marks that connector `needs_credentials` and is surfaced in the UI. A restore must never leave a connector that silently fails to authenticate.

**Cloning.** Restoring onto a second production appliance makes two hosts share one master key. Rotate the master key after cloning so each is independent.

#### Runbook

**A. Create a backup (connectors configured)**
1. Enter a strong encryption password (R5). Confirm the UI's statement that it protects connector credentials.
2. Create the backup; confirm `is_encrypted = 1` and `credentials_included = true` in the metadata.
3. Store the archive and the password **separately** — never in the same location, ticket, or password manager entry (see Retention).
4. Optionally verify the recorded `sha256_checksum`.

**B. Disaster recovery onto new hardware**
1. Install MicronOS on the replacement appliance (no connector credentials yet).
2. Upload the archive and enter the password.
3. Restore installs the master key at `/etc/micron/secrets.key` (`0600 root:www-data`), then the configuration.
4. Run post-restore decryption verification; every connector must report healthy.
5. Trigger one connector sync to confirm outbound fetch and apply.
6. If this appliance is a clone of a live one, **rotate the master key**.

**C. Restore onto an appliance that already has connectors**
- Same key → proceed.
- Different key → the operator must confirm key replacement; otherwise connector tables are skipped and those connectors are marked `needs_credentials` for re-entry from each vendor.

**D. Restore a backup with no key (plaintext or pre-feature)**
- Configuration restores; connectors are marked `needs_credentials`; re-issue tokens at each vendor. Expect this and plan for it — a plaintext backup is **not** a credential recovery set by design.

**E. Rotate the master key**
- Re-encrypt all rows eagerly, then destroy the old key (§7, T1.5). Take a fresh encrypted backup immediately; it carries only the new key.

**F. Key lost, no encrypted backup**
- No recovery is possible. Re-issue every connector credential at the vendor; there is no back door by design.

#### Retention & transport

The archive is credential-bearing whenever `credentials_included` is true. Offsite copies inherit that sensitivity, the password must never be stored with the archive, and retention/deletion of old archives must be deliberate.

#### Implementation status — **implemented**

- `BackupEngine` (with `ConnectorKeyStore` at `/etc/micron/secrets.key` and `SecretsVault`) now stamps `format: 2`, `is_encrypted` and `credentials_included` into the payload, adds the top-level `secrets` object (hex key + `key_version`) **inside the encrypted envelope only**, includes `connector_secrets` rows only when encrypted, and returns `credentials_included` in its metadata. A plaintext run carries neither the key nor the rows.
- `BackupEngine::restore()` returns a structured report and implements the restore-time table above: `installed` / `unchanged` / `replaced` (only with explicit confirmation) / `skipped_conflict` / `absent`. When no usable key can be established, connector ciphertext tables are skipped (not written undecryptable) and the instance `last_status` is set to `needs_credentials` where the table exists.
- Post-restore decryption verification is mandatory: every restored `connector_secrets` row is decrypted with the installed key; failures set `needs_credentials` and are surfaced in the restore flash message.
- `system_backups.credentials_included` records the marker (migration `2026-10-06-000089`), and the Backup page shows a **Creds** badge, a “replace the connector master key” confirmation on restore, and the R5 password guidance.
- R5 is enforced on the create path: a minimum length of 12 characters and rejection of the operator's current login password.

Verified by `tests/app/Libraries/BackupConnectorPolicyTest.php` (key/row inclusion and exclusion, install/match/conflict/replace, verification failure, absent tables) and `tests/app/Controllers/BackupPolicyTest.php` (password policy and persisted marker).

Outstanding (Phase 2, blocked on the connector tables): full `SecretsVault` CRUD and key rotation, and the `connector_instances`/`connector_secrets` migrations themselves — the backup code already guards on their presence, so it is correct before and after they land.

---

## 8. Sync Engine & Lifecycle

### 8.1 Scheduling
Enabling a connector writes a `cron_jobs` row:

- `id` = `connector_<instance_id>`
- `command` = `php spark connector:sync <instance_id>` (a CLI command, not a web route — syncs must run without a session)
- `user` = `www-data` (the fetch is unprivileged; only `micrond` is root)
- `enabled`, `description`, `last_run_at`, `last_status`, `last_output` — populated as today.

This reuses the existing cron engine and its run history rather than inventing a second scheduler.

Until `connector_instances` exists, the skeleton's entry point is the manifest-scoped `php spark micron:connector-sync <connector-id>` (following the repository's `micron:` command convention). The instance-scoped `connector:sync <instance_id>` above supersedes it in Phase 2.

### 8.2 Run sequence

```
 1. Guard        — instance exists, enabled, credential present
 2. Acquire lock — per-instance advisory lock (prevents overlapping runs)
 3. For each source:
       a. Fetch      HTTP(S) with timeout, size cap, redirect cap, TLS verify
       b. Authenticate  from SecretsVault (header/query/basic as declared)
       c. Parse      via the manifest's declared format (closed enum)
       d. Normalise  to a canonical typed list (domains | CIDRs | addresses)
       e. Validate   syntax per type; drop invalid entries with a count
       f. Stage      write to a staging artifact (temp file / temp table)
 4. Apply        single IPC call per target with the staged artifact
 5. Promote      on success, swap staging → live, update counters + audit
 6. Log          connector_sync_log row; update instance last_* fields
 7. Release lock
```

### 8.3 Why stage-then-promote
Replacing a live blocklist or address group in place means a mid-run failure (network drop, malformed file) can leave a **partially applied** policy — for a security control that is worse than stale data. Staging guarantees the live artifact is only ever the last complete, validated result.

### 8.4 Schedule vs. on-demand
The UI offers a **Sync now** action that enqueues the same CLI path (optimistically via a queue row or synchronously with a short timeout). Manual sync is audited identically.

---

## 9. Target Application

Each target is a data-plane adapter with a fixed contract: **receive a validated artifact, apply atomically, reload if required, report what changed.**

### 9.1 Target: DNS blocklist (`dnsbl`)
This is the target that completes the stubbed `dnsbl.update`.

**Current reality.** `Dns` controller calls `$this->ipc->call('dnsbl.update')` in six places; `micrond` has no `rpc_dnsbl_update`. `UnboundDnsCompiler` compiles the resolver config and `local-data` overrides but does **not** ingest feeds. The `cron_dnsbl_update` job references `/usr/local/bin/dnsbl-update`, a script not present in the tree.

**Operator feeds are already guarded.** The manual `dns_feeds` path does **not** wait for the connector engine: `Dns::storeFeed()` rejects any URL with `ConnectorEgressGuard::checkSyntax()` (https-only, no literal internal address, no internal hostname), and `Dns::syncFeeds()` fetches every enabled feed through `App\Connectors\ManualFeedIngestor`, which uses the same resolve-and-pin `fetch()` pipeline as the manifest path. So operator-supplied URLs already share the manifest connector's SSRF controls (threat model F3).

**Proposed contract.**
- PHP fetches and normalises all enabled domain sources into a deduplicated, sorted domain set (staged).
- PHP calls `dnsbl.apply` with either the full set or a content hash + a staged file path readable by `micrond`.
- `micrond` writes a blocklist include file (Unbound `local-zone`/`local-data` directives or an `include:` referenced file), validates with `unbound-checkconf`, and only then reloads.
- On validation failure, the previous include file is restored and the call returns an error — no reload occurs.

The existing `dns_feeds` rows remain the subscription UI; a connector simply *populates* a `dns_feeds` row (`connector_instance_id` set) instead of the operator typing a URL. A hand-typed URL is validated with `checkSyntax()` on save and fetched through `ManualFeedIngestor` on sync, so it passes through the same guard as the connector path.

### 9.2 Target: Firewall address group (`addressgroup`)
- PHP normalises IP/CIDR sources into a typed set.
- PHP calls `addressgroup.sync` with `{ group_name, entry_count, artifact_path }`.
- `micrond` writes a **local feed file** (the pattern already defined in architecture §13.4) and emits/refreshes an nftables named set, then reloads the ruleset.
- `firewall_address_groups.managed_by` marks the group; its `members` are materialised as `firewall_addresses` rows owned by the connector (so they are visible and reusable in the rule editor).
- Because a set is refreshed by rewriting the include file, updating a threat feed **never re-emits the whole ruleset** (the same optimisation §13.4 relies on).

### 9.3 Target: Systemd service (`service`)
Needed by connectors that toggle a daemon on apply (e.g. enabling mDNS reflection in a future integration). The contract: `{ service, action }`; `micrond` runs `systemctl <action> <unit>` and returns the resulting `is-active` state.

**Connectors must use `connector.service`, never `system.service`.** The two paths exist because connectors are driven by *untrusted* manifest data while the admin path is driven by an authenticated operator:

| RPC | Caller | Unit set |
| :--- | :--- | :--- |
| `system.service` | the Services page / page-header widget | full `SERVICE_UNITS` whitelist (an operator may legitimately restart `nftables`) |
| `connector.service` | connector engine only | strict `CONNECTOR_SAFE_UNITS` allowlist — default deny; `nftables`, `unbound`, `strongswan`, `wireguard`, `keepalived`, FRR and every other infrastructure daemon are refused with no command run |

This is a separate method rather than a `scope` flag, because a caller that can set a flag can also clear it — only a distinct method name makes the restriction non-bypassable by connector-supplied data. The connector adapter chooses the method; the manifest only chooses the service id.

**Manifest form.** A `service` source declares exactly two target-specific keys:

```jsonc
{ "id": "daemon", "target": "service", "service": "mdns-repeater", "action": "restart" }
```

`action` defaults to `restart`. Any other key on the source — `method`, `rpc`, `unit`, `url` — is refused at load time, so manifest data can never select or influence the RPC.

**Status: engine skeleton implemented.** `App\Connectors\Manifest` validates manifests against the closed enums above; `App\Connectors\ManifestRegistry` reads them from `app/Connectors/definitions/<id>/manifest.json`; `App\Connectors\ConnectorRunner` dispatches each source to its target adapter; and `App\Connectors\Targets\ServiceTarget` is the adapter that calls `connector.service` (its RPC is a class constant, never manifest-derived). The path is reachable through `php spark micron:connector-sync <connector-id>`. Fetch/parse/stage/promote and the `dnsbl` / `addressgroup` / `pki` adapters remain Phase 1+.

### 9.4 Target: PKI deployment (`pki`)
Write an issued certificate/key to the service's configured path and reload the dependent daemon. Specified here for completeness; implemented in Phase 3.

**The issuance half now exists, but not as a connector.** ACME / Let's Encrypt is implemented in the PKI module itself — `app/Libraries/Acme.php` (RFC 8555 client) and `app/Libraries/AcmeManager.php` (account, orders, renewal, storage), driven from `/certificates` and by the scheduled `micron:acme-renew`; see the Module Reference §2.24. It writes its result into `pki_authorities` / `pki_certificates` and reloads through the existing `cert.reload` RPC, which is where every other consumer of a certificate already reads from.

What remains unimplemented here is the *connector* form: a manifest-declared source whose data is deployed to an arbitrary service path as a target. A connector is the right shape when the certificate comes from a subscribed external feed and its destination is declared in a manifest; it is the wrong shape for the case above, where the appliance is the ACME client and the destination is the PKI store.

---

## 10. IPC Surface (micrond RPCs)

All RPCs follow the existing dotted-namespace convention and dispatch (`rpc_<method with dots as underscores>`).

| RPC | Purpose | Priority |
| :--- | :--- | :--- |
| `dnsbl.apply` | Write/validate/reload the Unbound blocklist include file | **P0** (completes stub) |
| `dnsbl.update` | Alias/back-compat for the six existing call sites | **P0** (completes stub) |
| `system.service` | Whitelisted `systemctl start/stop/restart` (admin path) | **P0** (completes stub) |
| `connector.service` | `systemctl start/stop/restart` limited to `CONNECTOR_SAFE_UNITS` (connector path) | **P0** (F2 hardening) |
| `addressgroup.sync` | Write local feed file + refresh nftables set | **P1** |
| `connector.status` | Report applied feed freshness and set sizes | **P1** |
| `pki.deploy` | Install a certificate/key and reload its consumer | **P2** |

**Hardening rules for all new RPCs** (matching `_run`'s existing design):
- argv lists only — never a shell, never string interpolation into a command.
- Artifact paths are fixed or validated against a whitelist; a connector-supplied filename is never used as a path component.
- Service names are validated against `system_services`; feed/group names against `^[A-Za-z0-9_]{1,64}$`.
- Every RPC returns structured `{ success, steps, warnings }` and reports partial failures rather than masking them (the pattern already used by `multicast.apply`).

---

## 11. Permissions & RBAC

New permission keys, seeded in the `permissions` catalog under a **Connectors** module, following the existing `module` grouping:

| Key | Label |
| :--- | :--- |
| `connectors.view` | View Connectors & Their Status |
| `connectors.manage` | Configure & Enable Connectors |
| `connectors.sync` | Trigger a Connector Sync |
| `connectors.secrets.manage` | Manage Connector Credentials |
| `connectors.rotate` | Rotate the Connector Master Key |

Routes use the existing `permission:` filter, e.g. a group filter of `permission:connectors.view` with per-action overrides — identical to the multicast and services groups. The **Sync now** action requires `connectors.sync`, so an operator can view status without being able to cause outbound fetches.

---

## 12. Management UI & Registration

### 12.1 Pages
- **Connectors** (`/connectors`) — catalog of available connectors, install/enable state, last-sync status per instance.
- **Connector detail** (`/connectors/<instance>`) — manifest-driven form: options from `options`, secret inputs from `auth.fields` (masked), sync schedule, sources with per-source freshness and entry counts, and a **Sync now** button.

### 12.2 Registration (the dynamic part)
Today routes (`app/Config/Routes.php`) and navigation (`app/Views/layouts/main.php`) are static. Connectors do **not** need dynamic route registration: the two pages above are static, and a connector is *data*, not a new page. What is dynamic is narrower:

- the **catalog** (which manifests exist) is read from `app/Connectors/definitions/`,
- the **instance list** is a table,
- the **page-header service widget** can render related daemons using the now-supported label override, e.g. `ServiceStatus::widget(['igmpproxy','frr'], ['igmpproxy' => 'IGMP', 'frr' => 'PIM'])`.

This keeps the security surface small: no plugin can introduce a route or a permission at runtime.

### 12.3 Audit
Every state change writes an `audit_logs` row (`action`, `action_type`, `target`, `old_state`, `new_state`). **Do not use a `details` column** — it does not exist. The controllers that did (`Cron`, `Services`, `Siem`, `Snmp`, `Dashboard`, `RepoDriftCommand`) silently swallowed the failure and recorded nothing; they now write `new_state`, insert a database-generated key rather than a hex `id`, and supply the `admin_user`/`client_ip`/`action_type`/`target_resource` columns the table requires — all of it enforced by `tests/app/Models/ModelSchemaConsistencyTest.php`.

---

## 13. Security Model

| Risk | Mitigation |
| :--- | :--- |
| **Arbitrary code execution via a "plugin"** | Manifests are static, first-party, and contained to a closed parser enumeration. No dynamic route, no dynamic permission, no executable descriptor fetched at runtime. No package installation. |
| **Credential leakage** | Secrets encrypted at rest, key outside the DB, never returned to the browser, never logged, never sent to `micrond`. |
| **SSRF via a feed URL** | **Implemented:** every connector fetch passes through `App\Libraries\ConnectorEgressGuard` — https-only scheme allowlist, resolve-once-and-pin against an internal-address denylist, per-hop redirect validation (auth stripped cross-origin, refused when sensitive), and compressed/decompressed/ratio caps. `App\Connectors\Targets\FetchTarget` is the only connector code that reaches the network. Applies to manifest-defined *and* operator-supplied URLs: a manual `dns_feeds` URL is rejected at entry with `checkSyntax()` and fetched on sync only through `ManualFeedIngestor` (same guard), so an operator cannot reintroduce the surface the manifest allowlist closes. |
| **Root daemon abuse** | `micrond` receives normalised data and fixed arguments; artifact paths are whitelisted; service names come from `system_services`. |
| **Poisoned / malformed feed** | Parse → validate → stage → promote. Invalid entries are dropped and counted; a wholly failed parse leaves the previous artifact live. |
| **Supply chain (later package manager)** | Deferred to Phase 4, and if ever built, restricted to a curated signed repository with pinned versions — never arbitrary package names. |
| **Resource exhaustion** | Per-source size caps, timeouts, redirect caps, and a single-flight lock per instance. |
| **Stale threat data mistaken for current** | Freshness is explicit: `last_updated`, per-source counters, and a UI staleness badge. Filtering fails *open on freshness* but the operator is told. |

> **See also:** [Connector Threat Model](MICRONOS_CONNECTOR_THREAT_MODEL.html) — adversarial analysis of the master key, the SSRF boundary, and the blast radius of a compromised connector. It raises several **requirements** that must be implemented before the corresponding phases ship. The connector-safe systemd unit allowlist (F2), the fetch egress guard (F3/F5) and the backup key policy (F6) are now implemented; a fresh GCM nonce per secret write (F4) is guaranteed by `SecretsVault`, whose full CRUD/rotation still lands with the connector engine.

---

## 14. Failure Handling, Idempotency & Limits

- **Single-flight:** one advisory lock per instance prevents overlapping cron ticks.
- **Idempotent apply:** targets are keyed by name (`feed_*`, `group_*`); re-applying the same artifact is a no-op detected by content hash.
- **Partial success:** one failing source does not fail the whole instance; the run is logged `partial` and the failing source is marked, while other sources promote normally.
- **Retry:** bounded (e.g. 3 attempts with backoff) inside a run; no unbounded retry loop across ticks.
- **Limits (defaults):** 32 MB per source, 30 s connect / 120 s total, 5 redirects, 2,000,000 entries parsed per source, TLS verification mandatory.
- **Rollback:** DNS and address-group targets keep the previous artifact file; invalid new content triggers restore-then-report. Extending `SafeTransactionManager` beyond nftables is part of Phase 2.
- **Offline behaviour:** an unreachable source is not an error for filtering — the previous dataset remains in force and only the freshness badge changes.

---

## 15. Reference Connectors

### 15.1 Q-Feeds (Threat Intelligence) — Phase 2 pilot
- **Auth:** bearer API token (`tip.qfeeds.com` → Manage API Keys).
- **Targets:** IP feeds → `addressgroup` (`__qfeeds_malware_ip`); domain feeds → `dnsbl`.
- **Options:** register domain feeds, allowlist domains, source networks, destination address / NXDOMAIN.
- **Why first:** it exercises the credential store, the scheduler, and both principal targets, while reusing `dns_feeds` and `firewall_address_groups` — and it completes the DNSBL ingest path that is currently stubbed.
- **Note:** requires a (free or paid) subscription; the connector must not attempt to auto-provision an account. Per-list licence/update status is displayed, read-only.

### 15.2 abuse.ch / URLhaus — Phase 1 (validation only)
Already seeded as a manual `dns_feeds` row pointing at `https://urlhaus.abuse.ch/downloads/hostfile/`. Modelling it as a manifest connector (`format: hostfile`, target `dnsbl`) is the **cheapest possible integration test** of the engine, with no credentials required.

### 15.3 Non-connector contrast: LLDP, iperf3, darkstat
These are **daemon apps**, not connectors — they contribute a service and a config file, not fetched policy data. They belong to the same eventual plugin surface but are served by a different (service-install) mechanism, and are listed here to keep the two categories from being conflated. That mechanism is now specified in the [Plugin & Package Manager Specification](MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.html): LLDP, iperf3, darkstat and Lightsquid are Class B (distro/first-party) plugins, while ZeroTier and NetBird are Class C (third-party repository/agent).

---

## 16. Phased Implementation Roadmap

**Phase 0 — Close the stubs (prerequisite).** — **status: implemented** (`tests/test_micrond_phase0.py`)
`system.service` and `dnsbl.update` / `dnsbl.apply` now exist in `micrond`:
- `system.service` resolves the `system_services` id through a static `SERVICE_UNITS` whitelist, accepts only `start` / `stop` / `restart`, and reports the real post-action `systemctl is-active` state.
- `dnsbl.*` reads only from the staging directory `/var/lib/micron/dnsbl` (a caller-supplied path must resolve inside it), validates hostnames, compiles `/etc/unbound/micron-blocklist.conf`, runs `unbound-checkconf`, restores the previous blocklist on failure, and then reloads Unbound. An empty result that was not explicitly requested is a **no-op** so a missing staging directory can never wipe live data.

Still outstanding for the control plane (Phase 1): PHP does not yet stage blocklist artifacts or pass `domains`/`overrides`, so on real hardware `dnsbl.update` currently resolves to the safe `noop` path; and `unbound.conf` does not yet `include:` the compiled blocklist file.

**Phase 1 — Engine + unauthenticated pilot.** — **status: skeleton implemented**
Manifest loader + schema validation and the `service` target adapter exist (`App\Connectors\*`, exercised by `tests/app/Libraries/ConnectorEngineTest.php` and reachable via `php spark micron:connector-sync`), which puts the F2 `connector.service` allowlist on a live code path. The `dnsbl` / `addressgroup` / `pki` sources are fetched and staged through the egress guard (`App\Libraries\ConnectorEgressGuard`, `App\Connectors\Targets\FetchTarget`), which puts the F3/F5 SSRF controls on a live code path too.
**Parsing is implemented** (`App\Connectors\FeedParser`): every format in the closed enum (`plain-domain-list`, `plain-ip-list`, `hostfile`, `json-ip-list`, `json-domain-list`, `csv`, `rfc1035-spf`) turns the staged artifact into a canonical typed set (domains or CIDRs) with per-entry validation, accurate drop counts and an entry cap, and `FetchTarget` refuses a target/type mismatch (domains for `dnsbl`, addresses for `addressgroup`) and retains only the validated `.set`. Verified by `tests/app/Connectors/FeedParserTest.php` and `tests/app/Connectors/FetchTargetTest.php`.
Still to do: `connector_instances`, `connector_sync_log`, `connector_targets`; **apply/promote** of the typed set (`dnsbl.apply`, `addressgroup.sync`, `pki.deploy`) and the health/freshness UI; pilot with abuse.ch URLhaus (no credentials). Proves fetch → parse → stage → apply → audit end to end.

**Phase 2 — Secrets + address groups.**
`SecretsVault` (AES-256-GCM, key at `/etc/micron/secrets.key`, rotation), `addressgroup.sync`, `firewall_address_groups.managed_by`, and the Q-Feeds pilot.

**Phase 3 — More targets and connectors.**
`pki.deploy` (the connector target; ACME issuance itself shipped earlier in the PKI module — §9.4); Lightsquid (report/cron, no daemon); status/freshness UI; extend `SafeTransactionManager` rollback to DNS and sets.

**Phase 4 — (Separate decision) Package manager.**
Only if desired later: a curated, signed repository with pinned versions, explicit capability grants, and full audit — explicitly **not** arbitrary `apt install` from the web UI. Tier-C connectors (ZeroTier, NetBird) land here because they require third-party apt repositories and account provisioning. The full design — plugin bundles, the curated/pinned package manager, per-plugin capability grants, transactional install/rollback, and the ZeroTier/NetBird/Lightsquid/iperf3/darkstat/LLDP catalogue — is specified in the [Plugin & Package Manager Specification](MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.html).

---

## 17. Risks & Open Questions

1. **`dnsbl-update` divergence.** The `cron_dnsbl_update` job points at `/usr/local/bin/dnsbl-update`, which is not in this repository. Phase 0 must reconcile the intended ingest path (external script vs. native `dnsbl.apply` RPC) rather than adding a third variant.
2. **Master-key bootstrap.** Where exactly the key is created, backed up, and restored is a real operational decision; losing it means re-entering every credential. Recommend including it in the encrypted backup payload, excluded from plaintext exports.
3. **`dns_feeds` vs. connector ownership.** Manual feeds and connector-owned feeds share a table; the `connector_instance_id` marker must be enforced by the UI so a connector does not silently overwrite a hand-written feed.
4. **Unbound blocklist scale.** Very large domain sets need the include-file/bloom approach; the exact Unbound directive form should be settled in Phase 1 against a large real feed.
5. **Freshness semantics.** "Stale but applied" is the safe default; the notification/escalation policy (badge, dashboard alert, SNMP trap) is undecided.

---

## Appendix A — Manifest JSON Schema

```jsonc
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": ["schema", "id", "name", "version", "sources"],
  "properties": {
    "schema":      { "const": 1 },
    "id":          { "type": "string", "pattern": "^[a-z0-9][a-z0-9-]{1,63}$" },
    "name":        { "type": "string" },
    "vendor":      { "type": "string" },
    "version":     { "type": "string" },
    "description": { "type": "string" },
    "category":    { "type": "string" },
    "icon":        { "type": "string" },
    "includes":    { "type": "array", "items": { "type": "string" } },

    "auth": {
      "type": "object",
      "properties": {
        "type": { "enum": ["none", "bearer", "basic", "header", "query"] },
        "fields": {
          "type": "array",
          "items": {
            "type": "object",
            "required": ["key", "label"],
            "properties": {
              "key":    { "type": "string" },
              "label":  { "type": "string" },
              "secret": { "type": "boolean", "default": false },
              "help":   { "type": "string" }
            }
          }
        }
      }
    },

    "options": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["key", "type"],
        "properties": {
          "key":     { "type": "string" },
          "type":    { "enum": ["bool", "string", "int", "list", "select"] },
          "default": {},
          "label":   { "type": "string" },
          "choices": { "type": "array" }
        }
      }
    },

    "schedule": { "type": "object", "properties": { "default": { "type": "string" } } },

    "sources": {
      "type": "array",
      "minItems": 1,
      "items": {
        "type": "object",
        // Required keys are target-dependent: fetch targets (dnsbl,
        // addressgroup, pki) need `url` + `format`; a service target needs
        // `service` (+ optional `action`). Keys outside the target's own set
        // are refused, so a service source can never carry `url`, and no
        // source may name an RPC.
        "required": ["id", "target"],
        "properties": {
          "id":      { "type": "string" },
          "target":  { "enum": ["dnsbl", "addressgroup", "service", "pki"] },
          "url":     { "type": "string", "format": "uri" },
          "format":  { "enum": ["plain-ip-list", "plain-domain-list", "hostfile",
                                "json-ip-list", "json-domain-list", "csv", "rfc1035-spf"] },
          "service": { "type": "string", "description": "system_services id; target=service only" },
          "action":  { "enum": ["start", "stop", "restart"], "description": "target=service only; default restart" },
          "group_name": { "type": "string" },
          "feed_name":  { "type": "string" },
          "category":   { "type": "string" }
        }
      }
    },

    "status_url": { "type": "string", "format": "uri" },
    "docs_url":   { "type": "string", "format": "uri" },
    "permissions": { "type": "array", "items": { "type": "string" } }
  }
}
```

## Appendix B — DDL

Illustrative SQLite-compatible DDL; the implementation ships as CI4 forge migrations, matching the existing migration style.

```sql
-- 1. Configured connector instances ----------------------------------------
CREATE TABLE connector_instances (
    id                VARCHAR(36)  PRIMARY KEY,
    connector_id      VARCHAR(64)  NOT NULL,
    name              VARCHAR(128) NOT NULL,
    enabled           TINYINT(1)   NOT NULL DEFAULT 0,
    settings          TEXT,                          -- JSON: manifest option values
    schedule_override VARCHAR(64),
    last_sync_at      DATETIME,
    last_status       VARCHAR(16)  NOT NULL DEFAULT 'never',
    last_message      TEXT,
    created_at        DATETIME,
    updated_at        DATETIME
);
CREATE INDEX idx_connector_instances_connector ON connector_instances (connector_id);

-- 2. Encrypted secrets (one row per secret field per instance) --------------
CREATE TABLE connector_secrets (
    id          VARCHAR(36) PRIMARY KEY,
    instance_id VARCHAR(36) NOT NULL,
    field_key   VARCHAR(64) NOT NULL,
    ciphertext  TEXT        NOT NULL,   -- base64(AES-256-GCM)
    nonce       VARCHAR(32) NOT NULL,   -- base64, 12 bytes
    tag         VARCHAR(32) NOT NULL,   -- base64, 16 bytes
    key_version INTEGER     NOT NULL DEFAULT 1,
    created_at  DATETIME,
    rotated_at  DATETIME,
    UNIQUE (instance_id, field_key)
);

-- 3. Sync run history -------------------------------------------------------
CREATE TABLE connector_sync_log (
    id          INTEGER     PRIMARY KEY AUTOINCREMENT,
    instance_id VARCHAR(36) NOT NULL,
    source_id   VARCHAR(64),
    started_at  DATETIME,
    finished_at DATETIME,
    status      VARCHAR(16) NOT NULL DEFAULT 'running',  -- success | failed | partial | running
    items_in    INTEGER     NOT NULL DEFAULT 0,
    items_out   INTEGER     NOT NULL DEFAULT 0,
    bytes_in    INTEGER     NOT NULL DEFAULT 0,
    message     TEXT
);
CREATE INDEX idx_connector_sync_log_instance ON connector_sync_log (instance_id, started_at DESC);

-- 4. Bindings from a connector source to the local object it owns -----------
CREATE TABLE connector_targets (
    id          VARCHAR(36)  PRIMARY KEY,
    instance_id VARCHAR(36)  NOT NULL,
    source_id   VARCHAR(64)  NOT NULL,
    target_type VARCHAR(16)  NOT NULL,   -- dnsbl | addressgroup | service
    target_ref  VARCHAR(128) NOT NULL,   -- feed id / group id
    managed     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME,
    updated_at  DATETIME,
    UNIQUE (instance_id, source_id)
);

-- 5. Extensions to existing tables ------------------------------------------
ALTER TABLE dns_feeds
    ADD COLUMN connector_instance_id VARCHAR(36);
ALTER TABLE dns_feeds
    ADD COLUMN source_id VARCHAR(64);

ALTER TABLE firewall_address_groups
    ADD COLUMN managed_by VARCHAR(36);   -- connector instance id, NULL = manual

-- 6. Permissions to seed (permissions table, module "Connectors") ----------
--   connectors.view            | View Connectors & Their Status
--   connectors.manage          | Configure & Enable Connectors
--   connectors.sync            | Trigger a Connector Sync
--   connectors.secrets.manage  | Manage Connector Credentials
--   connectors.rotate          | Rotate the Connector Master Key
```

---

*This is a design specification. No implementation code is included, and no application files are modified by this document.*

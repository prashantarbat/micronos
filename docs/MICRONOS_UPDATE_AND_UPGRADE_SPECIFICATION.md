# MicronOS — Update & Upgrade Mechanism Specification

## Design Specification — Not Yet Implemented

### Three-Plane Lifecycle: Content, Application and Platform Updates

**Status:** Design specification. Nothing in this document is built yet — §3 records exactly what exists in the repository today.
**Applies to:** Micron Firewall OS 2.x appliances (Debian 13 data plane + CodeIgniter 4 management plane)
**Companion documents:** [Architecture Blueprint](MICRON_FIREWALL_OS_ARCHITECTURE.html) §6 & §20 · [ISO & Installer Guide](ISO_CREATION_AND_INSTALLER_GUIDE.html) §9 · [Module Reference](MICRONOS_MODULE_REFERENCE.html)

---

## 1. Purpose & Scope

This document defines how a MicronOS appliance receives changes after it has been installed: how update artifacts are published and verified, how they are applied, how a failed update is detected and reversed, and how the three very different kinds of change are kept from interfering with one another. **It describes a design target, not current behaviour** — the audit in §3 is the authoritative statement of what the appliance does today.

It covers the **push/pull update mechanism only**. It does not redefine configuration commit semantics (see Architecture §6, *Transactional Commit Engine*), backup/restore (`BackupEngine`, `RestoreManager`), or first-boot provisioning (`micron-firstboot.sh`).

> **Read this first — two corrections to existing documents.** Sections 3.2 and 3.3 record claims in the Architecture Blueprint and Module Reference that do not match the repository, and which this specification supersedes.

---

## 2. Design Principles

1. **Three planes, never one pipeline.** Content, application, and platform changes have different cadences, blast radii and rollback costs. Collapsing them forces every PHP bugfix through an image rebuild and a reboot.
2. **The running system is never mutated in place.** An update is written beside the running version and activated by an atomic switch (symlink flip or boot slot).
3. **Every update is verifiable and reversible.** Signed artifacts, a recorded pre-update snapshot, and an automatic fall back to the previous known-good state.
4. **Health is asserted, not assumed.** A new version only becomes permanent after an explicit post-update verification step passes. Silence is treated as failure.
5. **The database is the source of truth; the daemon is the only privileged actor.** All update actions are driven from the web UI through `micrond` over the existing Unix-socket JSON-RPC bridge. The PHP layer remains unprivileged.
6. **An appliance that cannot reach the internet must still be updatable.** Signed bundles are equally valid from a USB device as from a channel endpoint.

---

## 3. Current-State Audit

Everything below was verified against the repository, not inferred.

### 3.1 What exists today

| Capability | Reality | Location |
| :--- | :--- | :--- |
| Spark command layer | 8 commands, three of which are directly reusable as update primitives | `app/Commands/` |
| Post-update verification primitive | `VerifySystem` — verifies compilers, daemons and engines | `app/Commands/VerifySystem.php` |
| Pre-migrate snapshot primitive | `BackupCreate` — atomic configuration backup archive | `app/Commands/BackupCreate.php` |
| Migration integrity guard | `MigrateResync` — repairs migration-history rows | `app/Commands/MigrateResync.php` |
| Content feed synchronisation | `SyncServices` (public prefix/geo feeds), `ResolveObjects` | `app/Commands/` |
| Content auto-update flags | `auto_update` columns on `ids_settings` and the webfilter category tables | migrations `000071`, `000072` |
| Signature update (in-app) | ClamAV signature update endpoint on the Web Filter antivirus panel | `app/Controllers/WebFilter.php` |
| Config rollback engine | Commit Watchdog Pattern — stage → compile-test → apply → 60 s confirm-or-revert | Architecture §6 |
| Privileged transport | `IpcClient` → `/run/micron/micrond.sock`; mock layer for Windows dev | `app/Libraries/IpcClient.php` |
| Reboot RPC | `system.reboot` exists (mock: 3 s delay) and is wrapped by `CommandDispatcher` | `IpcClient.php:311` |

### 3.2 Correction — the A/B scheme was documented but not built

Architecture §20 and Installer Guide §9 present **dual A/B root slots as an operating feature**. Three separate realities had to be reconciled:

- **The guide's embedded installer** ([ISO & Installer Guide](ISO_CREATION_AND_INSTALLER_GUIDE.html) §9) created a `ROOT_B` partition but never formatted, mounted or referenced it, and installed GRUB with no slot selector.
- **The repository's actual installer** — `installer/install.sh`, the file that runs on an appliance — was further from A/B still: a **single** root partition (`ESP` + swap + `MicronOS Root`) and **no data partition at all**, so neither a second slot nor a persistent layer existed.
- Architecture §20's "three-minute bootloader fallback" therefore had nothing to fall back from or to.

> **Update — Phase 0 (see §13) is implemented.** `installer/install.sh` now provisions the full A/B layout: slot B is created, formatted and labelled, listed in `fstab` by UUID as `noauto`, seeded into a GRUB environment block, and adopted after a verified boot by `micron-healthcheck`. The audit above is retained because it is what the pre-Phase-0 code did, and because the guide's embedded script remains an older variant — **`installer/install.sh` is authoritative**. What is *not* yet built is everything downstream of the slot: no updater writes an image into slot B (Phase 3), and no daemon-side logic consumes the slot state, so slot B stays empty until that lands.

### 3.3 Correction — there is no Updates settings surface

The Module Reference lists, under *System Settings & Appearance* (2.27):

> `| Updates | Firmware update channel, auto-update window |`

**No such settings exist.** There is no `update_channel`, `auto_update_window`, `os_version` or `app_version` column in any migration, no Updates tab or field in `app/Views/system/index.php`, no update route in `app/Config/Routes.php`, and no `system.update` method in `IpcClient`. The only `auto_update` columns that exist belong to the **content plane** (`ids_settings`, webfilter category tables) and are unrelated to firmware.

The claim has been corrected in the Module Reference to read as *planned*, pointing here.

### 3.4 The coupling problem

The installer deploys the application to **`/usr/local/micron`**, with the web server rooted at `/usr/local/micron/public` ([ISO guide §4.1](ISO_CREATION_AND_INSTALLER_GUIDE.html), [§6.1](ISO_CREATION_AND_INSTALLER_GUIDE.html)) — that is, on the **read-only root filesystem**. Consequently an application-only fix (a template typo, a controller bug) today requires building and shipping a complete platform image and rebooting the branch appliance. This is the single strongest justification for splitting plane B from plane C, and is why §5 relocates the application tree.

---

## 4. The Three Planes

| | **Plane A — Content** | **Plane B — Management** | **Plane C — Platform** |
| :--- | :--- | :--- | :--- |
| **Changes** | ClamAV signatures, Suricata rules, blocklists, GeoIP/prefix feeds, tzdata, CA bundles | CodeIgniter application code, views, assets, DB schema | Kernel, base OS packages, FRR, nftables, Squid, Kea, Unbound, php-fpm/nginx |
| **Artifact** | Signed ZIP/tarball per feed | Signed application tarball | Signed RAUC bundle (`.raucb`) containing a SquashFS root image |
| **Cadence** | hourly → daily | weekly → on demand | quarterly, or on CVE |
| **Activation** | atomic file swap | symlink flip + `php-fpm` reload | write inactive slot, reboot, mark good |
| **Rollback** | re-download previous set | symlink flip back + DB restore | boot the previous slot |
| **Reboot** | no | no | yes |
| **Privilege** | `micrond` | `micrond` | `micrond` |
| **Blast radius** | low | medium (UI/API only) | total |

Ordering rule: **platform (C) → management (B) → content (A)**, and never run two planes concurrently. A plane C update implies a known-good plane B release; the appliance refuses to boot a slot whose recorded plane B version is incompatible with the platform's declared minimum.

---

## 5. Plane B — Management-Plane (Application) Updates

### 5.1 Relocating the application tree

The application moves off the read-only root into the persistent DATA partition, versioned by directory, and is addressed through a stable symlink:

```
/var/lib/micron/                     ← DATA partition (persistent, writable)
├── app/
│   ├── releases/
│   │   ├── 2.4.0/                   ← complete application tree, immutable once activated
│   │   ├── 2.4.1/
│   │   └── 2.4.2/                   ← staged, not yet active
│   ├── current -> releases/2.4.1    ← atomic activation target
│   └── previous -> releases/2.4.0   ← one-step rollback target
├── backups/                         ← BackupCreate archives, pre-update snapshots
├── bundles/                         ← staged download area for all three planes
├── updates.db                       ← plane A/B/C history (see §8)
└── micron_config.db                 ← the configuration database
```

nginx's `root` and php-fpm's `chdir` are pointed at `/var/lib/micron/app/current/public`. Activation is `ln -sfn` + `systemctl reload php8.3-fpm` — a single atomic operation with no window in which the tree is partially written.

**Retention:** keep the running release plus the previous two; prune older ones after a successful verification, never during an update window.

### 5.2 Update flow

| # | Step | Detail | On failure |
| :--- | :--- | :--- | :--- |
| 1 | **Check** | `micron-update check --plane=app` fetches `manifest.json`, verifies its signature, compares semver to the running version and gates on `min_platform` / `min_schema` / `max_schema`. | Log, surface "check failed", no state change. |
| 2 | **Stage** | Download the tarball into `bundles/`, verify SHA-256 and signature, unpack into `releases/<version>/`. The running release is untouched. | Delete the partial directory; report. |
| 3 | **Verify tree** | Compare the unpacked file list against the manifest; run `php spark` boot check and `VerifySystem`. | Discard the staged release. |
| 4 | **Snapshot** | `php spark micron:backup:create --tag=pre-2.4.2` → `backups/`. Also record the current release symlink targets in `updates.db`. | Abort before any switch. |
| 5 | **Migrate** | `php spark migrate` executed against the *staged* release while the old one serves traffic, using the schema gates from step 1. | Restore the snapshot, discard, abort. |
| 6 | **Activate** | `current` symlink flipped to the new release; `php-fpm` reloaded. | Flip back immediately. |
| 7 | **Smoke test** | Authenticate, load the dashboard, confirm the compiled ruleset matches the pre-update output, confirm `micrond` IPC round-trips. | Auto-rollback (§5.3). |
| 8 | **Commit** | Record success in `updates.db`, prune old releases, emit an audit entry. | — |

Steps 4–7 are the existing Commit Watchdog Pattern (Architecture §6) applied to a code deployment rather than a config change: same stage / verify / activate / confirm shape, with `BackupCreate` and `VerifySystem` supplying the snapshot and the health assertion.

### 5.3 Automatic rollback

A rollback is triggered when any of these occur inside the smoke-test window:

- the health endpoint does not answer within 30 s of the `php-fpm` reload;
- `VerifySystem` reports a failure;
- any request returns a 500 in the first 60 s of traffic;
- the operator does not confirm within the commit deadline (same 60 s convention as §6).

Rollback = restore the DB snapshot from step 4, re-point `current` at `previous`, reload `php-fpm`, record a `rolled_back` history row, and raise an alert. Because the previous release directory was never modified, this is a symlink flip and a restore — seconds, not minutes.

### 5.4 Schema compatibility rules

- Migrations must remain **immutable and idempotent** — the existing migration set already satisfies this, which is what makes an N-1 rollback safe without ever needing a `down()` in anger.
- An application release declares `min_schema` and `max_schema`; the updater refuses to activate a release whose schema requirements exceed the platform's bundled migration runner.
- Support **N-1 compatibility**: a release must run against the schema of its predecessor and its successor's predecessor. Enforce with a `schema_lock` table row written by every migration batch.
- Never roll back across a schema boundary without restoring the snapshot. A symlink rollback alone is only safe when `min_schema`/`max_schema` overlap; the updater checks this and falls back to the full restore path when they do not.

### 5.5 Semantic Versioning & Release Channels (`MAJOR.MINOR.PATCH`)

Micron OS strictly follows a 3-part versioning standard:

$$\mathbf{MAJOR}.\mathbf{MINOR}.\mathbf{PATCH}$$

*(e.g., `2.4.56`, `0.1.23`, `2.2.11`)*

1. **Second Digit (Minor) — Release Channel Rule:**
   - **Odd number (`1, 3, 5, ...`)** $\rightarrow$ **Development Version** (e.g. `0.1.23`, `1.1.24`, `2.3.56`). Used for active development, nightly builds, and testing.
   - **Even number (`0, 2, 4, ...`)** $\rightarrow$ **Stable Version** (e.g. `0.2.0`, `2.2.11`, `2.4.12`). Used for production-hardened releases and enterprise LTS deployments.
2. **Third Digit (Patch) — Incremental Edits:**
   - Represents incremental edits, builds, or configuration revisions performed on the project (`.56`, `.12`, `.11`, `.24`, `.23`).
   - Increments with each confirmed commit/edit via `AppVersion::incrementPatch()`.
3. **Project Starting Baseline:**
   - The initial version starts at **`0.1.23`** (Development version, since minor digit `1` is odd).
4. **Project Name:** Strictly **"Micron OS"** (with space), never "MicronOS".
5. **Reference Specification:** See [Semantic Versioning & Release Lifecycle Specification](MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION.html) for complete architectural and runtime details.

---

## 6. Plane C — Platform (Image) Updates

### 6.1 What the installer must provide first

Three prerequisites. **All three are implemented** (Phase 0, §13); the design of each is recorded here because Phase 3's updater depends on their exact contract.

1. **Slot B formatted and addressable** — `mkfs.ext4 -F -L MICRON_ROOT_B`, plus an `fstab` entry by UUID at `/mnt/micron-slot-b` marked `noauto,nofail`. `noauto` is deliberate: an image being written into the slot must never be half-mounted by the running system. *(implemented in [install.sh](../installer/install.sh))*
2. **Boot-slot selector** — a GRUB environment block holding `micron_active_slot`, `micron_a_ok`, `micron_b_ok`, `micron_a_fail`, `micron_b_fail`, read by `/etc/grub.d/09_micron_slots` (so it sorts before `10_linux` and its menuentry is the default). *(implemented in [09_micron_slots](../installer/grub.d/09_micron_slots))*. Two deliberate design decisions: the block is GRUB's own `grubenv` format written by a plain shell helper rather than `grub-editenv`, so the installer can seed it before GRUB exists in the target; and the selector performs **no arithmetic**, because GRUB script has no reliable integer maths. Counters therefore live in the health check, and GRUB only answers *"is the preferred slot proven, and if not, is the other one?"*.
3. **Health-check unit** — `micron-healthcheck.service`, running once per boot, is the *only* code permitted to mark a slot good. *(implemented in [micron-healthcheck](../installer/micron-healthcheck))*

A slot with no good mark is never promoted, and when nothing is marked good the selector still boots the active slot — so a fresh install and a doubly-failed appliance both stay reachable while the failure is recorded.

**Still outstanding on the daemon side:** `micrond` must begin pinging the hardware watchdog only *after* the health check passes (§6.2). That ordering lives in the daemon and is not part of Phase 0.

### 6.2 Boot and activation flow

```
                    PUBLISH (CI, offline signing key)
                    rootfs → squashfs → signed .raucb
                                │
                                ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ micron-update stage --plane=platform                         │
  │  verify signature → hash → write to the INACTIVE slot        │
  │  record desired version + attempts_left = 3 in slot env      │
  └───────────────────────────┬──────────────────────────────────┘
                              ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ micron-update apply → set active_slot=other → reboot         │
  └───────────────────────────┬──────────────────────────────────┘
                              ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ GRUB bootchooser reads slot env, boots the marked slot       │
  └───────────────────────────┬──────────────────────────────────┘
                              ▼
        ┌─────────────────────────────────────────────┐
        │ micron-healthcheck.service (new slot)       │
        │  • nginx answers /healthz                   │
        │  • micrond IPC round-trips                  │
        │  • frr + nftables rulesets loaded           │
        │  • VerifySystem passes                      │
        │  • schema migration completed               │
        │  • management connectivity preserved        │
        └───────────────┬─────────────────────────────┘
                pass ───┴─── fail
                  │            │
      slot_X_ok=true│            │ attempts_left-- ; on reaching 0,
      commit        │            │ clear slot_X_ok → GRUB falls back
      release notes │            │ to the previous slot automatically
                    ▼            ▼
```

Design notes:

- The health check reuses the **WAN-preservation rule from Architecture §6**: the appliance must still be reachable on the management interface after the update, otherwise the new version is treated as failed even if its own services started. A platform update that isolates the branch is exactly the failure this exists to prevent.
- `attempts_left` starts at 3 so a transient crash-loop does not permanently discard a good image.
- The `micrond` hardware-watchdog ping (Architecture §20, every 15 s to `/dev/watchdog`) must begin **only after** `micron-healthcheck` passes; pinging early would let a broken slot look alive.
- The read-only root means a slot can be verified byte-for-byte before it is ever booted.

### 6.3 RAUC bundle contents

```ini
# manifest.raucm (indicative)
[update]
compatible=micronos-2-x86-64
version=2.5.0
description=MicronOS 2.5.0 platform image

[bundle]
format=verity

[image.rootfs]
filename=rootfs.squashfs

[image.bootloader]
filename=bootloader.tar          # GRUB bootchooser env defaults
```

Additional metadata travels in a signed sidecar consumed by `micron-update`: `min_platform`, `min_schema`, `app_version_recommended`, `release_notes_url`, `rollback_safe=true|false`.

### 6.4 Why RAUC

The installer already uses **GRUB2** and already sketches two equal-sized root partitions, which is precisely the shape RAUC targets; it is the mainstream choice for this class of device and supports GRUB `bootchooser` natively. Alternatives considered:

| Option | Verdict |
| :--- | :--- |
| **SWUpdate** | Viable, but its strength is dual-copy handler configuration; it brings no boot-slot semantics we get free from GRUB `bootchooser`. |
| **Mender / Balena** | End-to-end platform including a hosted server. More operational surface than a branch firewall needs, and it introduces a third-party dependency in the update trust path. |
| **OSTree / rpm-ostree** | Attractive image model, but a fundamental change to how the base OS is composed and booted — too large a step for a design already shipping SquashFS + GRUB. |

RAUC adds only the bundle format, signature verification, and the slot bookkeeping — the boot machinery is ours and already planned.

---

## 7. Plane A — Content Updates

Content updates are frequent, small, and never reboot. They are consolidated behind one library and one timer so that signing, retry, telemetry and history are shared instead of re-implemented per feed.

| Feed | Source | Consumer | Existing hook |
| :--- | :--- | :--- | :--- |
| Public prefix feeds / geo | Internet Service DB providers | `ResolveObjects`, address objects | `SyncServices` |
| Suricata rulesets | Emerging Threats / custom | `SuricataCompiler` | `ids_settings.auto_update` |
| Web category blocklists | Shallalist / UT1 / URLhaus | Squid ACLs | webfilter category `auto_update` |
| Antivirus signatures | ClamAV mirrors | `c-icap` / `clamav-daemon` | Web Filter AV panel |
| GeoIP databases | MaxMind | address objects | — |
| Timezones | IANA tzdata | base OS | — |

Rules:

- Download to `bundles/<feed>-<timestamp>.<ext>` on DATA, verify checksum and (where available) signature, **then** swap the live file into place with a rename — consumers never observe a partial file.
- Keep the previous set for one cycle so a corrupt feed can be reverted without re-downloading.
- A content update must never require a service restart beyond the consumer itself (`squid -k reconfigure`, `suricatasc -c reload-rules`).
- Record every run in `updates.db` and honour a maintenance window / bandwidth ceiling, because branch links are the scarce resource.

---

## 8. Data Model

New table, owned by the update subsystem and read by the System UI:

```
update_history
  id                INTEGER PK
  plane             TEXT     -- content | app | platform
  target_version    TEXT
  from_version      TEXT
  slot              TEXT     -- NULL | a | b
  status            TEXT     -- checking | staged | applying | active
                             -- | rolled_back | failed
  artifact_sha256   TEXT
  signature_state   TEXT     -- verified | unsigned | invalid
  started_at        TEXT
  finished_at       TEXT
  detail            TEXT     -- JSON: gate results, health-check output, error
```

Plus two small files rather than tables, because they must be readable before the database is mounted: the **slot env file** (§6.1) and `schema_lock` (§5.4).

---

## 9. Trust Model

| Concern | Rule |
| :--- | :--- |
| **Signing** | Ed25519 or RSA-4096 over the manifest; the private key never exists on an appliance. |
| **Verification key** | Public key baked into the read-only root at image build time. Rotating it is itself a platform update. |
| **Distinct keys** | The update signing key is **separate** from the TLS/interception PKI (`pki_authorities`) and from the config-backup encryption key. Compromise of one must not grant the other. |
| **Transport** | HTTPS with certificate pinning; a LAN mirror is supported but must present the same signed artifacts. |
| **Downgrade** | Refused unless `--allow-downgrade` **and** the target's `min_schema`/`max_schema` admit the current database. |
| **Replay** | Manifest carries `issued_at`; the updater rejects manifests older than the applied version's issue date. |
| **Audit** | Every check, stage, apply and rollback writes an `audit_logs` row. |
| **Privilege** | The web layer never writes to a slot, never formats, never reboots; it emits RPC and renders state. |

---

## 10. Control Interface

### 10.1 RPC (daemon side, over `/run/micron/micrond.sock`)

| Method | Purpose |
| :--- | :--- |
| `system.update.check` | Fetch + verify manifest, return available versions per plane. |
| `system.update.stage` | Download and verify; write the inactive target. |
| `system.update.apply` | Activate (symlink flip + reload, or slot flag + reboot). |
| `system.update.rollback` | Revert the last activation and restore the snapshot. |
| `system.update.status` | Current plane versions, slot state, `attempts_left`, history tail. |
| `system.content.sync` | Trigger a content-plane refresh for named feeds. |

`system.update` is the name already reserved in the Architecture command table; `system.reboot` (implemented) is used by `apply` for the platform plane.

### 10.2 Web surface

- **System → Updates** (the settings surface the Module Reference currently describes but does not exist): installed versions per plane, channel selector, maintenance window, check/stage/apply/rollback actions, live progress, history table.
- **Dashboard**: an update-available badge tied to the existing banner mechanism.
- Permissions: a new `system.updates.manage` for actions; `system.updates.view` to see status. Both gated by the existing `permission:` route filter.

---

## 11. Failure Modes

| Failure | Detection | Response |
| :--- | :--- | :--- |
| Corrupt download | SHA-256 mismatch | Discard, retry up to 3× with backoff, then report. |
| Invalid/absent signature | Verification step | Hard refuse; never fall back to unsigned. |
| Schema gate mismatch | Manifest vs `schema_lock` | Refuse activation; tell the operator which platform version is required. |
| Migration failure | `spark migrate` non-zero | Restore snapshot; leave the running release active. |
| Bad application release | Smoke test / 500s / timeout | Automatic symlink + snapshot rollback (§5.3). |
| Bad platform image | `micron-healthcheck` failure | Attempts decrement; GRUB falls back to the previous slot. |
| Loss of management connectivity | Post-update reachability probe | Treated as failure even if services report healthy. |
| Power loss mid-write | Slot written then marked; activation is a single flag | Slot is never marked good, so it is discarded on next boot. |
| Watchdog reboot loop | Boot counter | After 3 failed attempts the slot is abandoned and support is notified. |
| Air-gapped branch | No channel reachable | USB bundle path uses the identical verification chain. |

---

## 12. Delivery, Channels & Fleet

- **Channels:** `stable`, `candidate`, `lab`. An appliance pins one; the channel selector in System → Updates changes it (audited).
- **Staged rollout:** the fleet controller may command cohorts — canary → 10 % → 50 % → 100 % — with automatic halt on the first health-check failure. Canary appliances are expected to be lab units, never the only uplink at a critical site.
- **Offline:** a `.raucb` or app tarball on USB is verified against the same baked-in key; the operator is shown the version, signature state and hash before they can apply it.
- **Fleet reporting:** appliances POST `{uuid, channel, plane versions, slot state, health}` to a controller; the controller never pushes arbitrary commands, only "which version to pull".
- **Factory reset** is distinct from rollback: it clears the DATA partition and returns to the factory slot image, and it is never an implicit consequence of a failed update.

---

## 13. Implementation Roadmap

Each phase is independently useful and independently testable.

**Phase 0 — Make A/B real (prerequisite). ✅ Implemented.**
`installer/install.sh` partitions `ESP` + swap + `ROOT_A` + `ROOT_B` + `DATA`, formats and labels every slot, mounts `DATA` at `/var/lib/micron`, writes the five-entry `fstab`, installs the slot selector as `/etc/grub.d/09_micron_slots`, seeds the `grubenv` block, and installs + enables the health-check service. Slot B is deliberately left **empty** — it is the target an update writes to, not a clone of slot A.
*Acceptance:* covered by [tests/installer/phase0_slot_b_test.sh](../tests/installer/phase0_slot_b_test.sh) (49 assertions, wired into PHPUnit as `Phase0SlotBTest`): the scripts parse, the slot helper's invariants hold (including a 1024-byte block after every write), the installer provisions and formats slot B and lists it as `noauto`, the emitted GRUB fragment boots both slots by UUID with no arithmetic and falls back when the preferred slot is unproven, and the health check promotes a verified slot, counts failures, abandons a slot after three failed boots, and starts an activated slot unverified.
*Not verified here:* the actual partition/mkfs/grub-install run and a real reboot cycle, which need appliance hardware or a VM. The arithmetic of the disk-size preflight and the layout is checked, but no disk was partitioned.

**Phase 1 — Application-plane updater.**
Relocate the app tree to `/var/lib/micron/app/releases/`, add `micron-update check|stage|apply|rollback --plane=app`, wire `BackupCreate` / `MigrateResync` / `VerifySystem` into the flow.
*Acceptance:* a deliberately broken release is automatically rolled back within 60 s with the database restored; a good release activates without a reboot and with no dropped requests.

**Phase 2 — Health check + rollback proof.**
`micron-healthcheck.service`, watchdog-ping ordering, three-attempt exhaustion.
*Acceptance:* an intentionally unbootable slot falls back on the first boot; a slot that boots but fails the reachability probe also falls back.

**Phase 3 — Platform-plane updater.**
RAUC integration, bundle signing in CI, `system.update.*` RPC, `updates.db`.
*Acceptance:* a signed bundle upgrades a QEMU appliance across a minor version and rolls back cleanly when the health check is forced to fail; an unsigned bundle is rejected.

**Phase 4 — Content consolidation + fleet.**
Unify feed updaters, add the maintenance window, channel selector, System → Updates UI, and optional fleet reporting.

Phases 0–2 deliver the resilience the Architecture document already claims. Phases 3–4 deliver the mechanism.

---

## 14. Document Corrections Applied

This specification supersedes the following statements in the existing documents, which have been amended to match reality:

| Document | Previous claim | Corrected to |
| :--- | :--- | :--- |
| Architecture §20 | Dual A/B slots and a 3-minute bootloader fallback described as operating | Stated as the target design, with the installer gap and a cross-reference here |
| Module Reference §2.27 | "Updates — firmware update channel, auto-update window" listed as an existing settings category | Marked planned, pointing here; the only `auto_update` columns that exist are content-plane |
| ISO & Installer Guide §9 | Partition table presented as a working dual-slot scheme | Annotated with the actual installer behaviour (`ROOT_B` partitioned but not formatted, mounted or selected) |

---

*Micron Firewall OS — Update & Upgrade Mechanism Specification*

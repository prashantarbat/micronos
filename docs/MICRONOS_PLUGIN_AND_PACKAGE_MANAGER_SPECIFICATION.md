# Micron Firewall OS (MicronOS)
## Plugin & Package Manager Specification
### The OPNsense-/pfSense-class Extension Surface: Daemon Plugins, Curated Packages, and Third-Party Integrations

---

## Table of Contents
1. [Purpose & Scope](#1-purpose--scope)
2. [Design Principles, and What We Refuse to Build](#2-design-principles-and-what-we-refuse-to-build)
3. [The Three Extension Classes](#3-the-three-extension-classes)
4. [Plugin Anatomy: The `.mplug` Bundle](#4-plugin-anatomy-the-mplug-bundle)
5. [The Package Manager](#5-the-package-manager)
6. [Lifecycle: Browse → Install → Configure → Enable → Upgrade → Remove](#6-lifecycle-browse--install--configure--enable--upgrade--remove)
7. [Capability Grants & Privilege Model](#7-capability-grants--privilege-model)
8. [Daemon-Side Enforcement (`micrond`)](#8-daemon-side-enforcement-micrond)
9. [Management Plane: Libraries, Controllers & UI](#9-management-plane-libraries-controllers--ui)
10. [Data Model](#10-data-model)
11. [Integration Catalogue](#11-integration-catalogue)
12. [Security Model & Threat Analysis](#12-security-model--threat-analysis)
13. [Lifecycle Coupling: Updates, A/B Slots & Backup](#13-lifecycle-coupling-updates-ab-slots--backup)
14. [CLI & RPC Reference](#14-cli--rpc-reference)
15. [Phased Roadmap](#15-phased-roadmap)
16. [Risks & Open Questions](#16-risks--open-questions)

---

## 1. Purpose & Scope

MicronOS ships as a **closed, opinionated appliance**: the data plane (nftables, Unbound, Squid, FRR, strongSwan, Kea, Suricata …) is compiled from database state and controlled through a single privileged daemon, `micrond`. That model is what makes the appliance auditable and avoids the classic "admin web UI that can `apt install` anything" trap.

It also means that anything *not* in the core image — LLDP neighbour discovery, an iperf3 speed-test server, a ZeroTier or NetBird overlay, a commercial threat-feed connector — has no supported way in today's tree. This document specifies how those capabilities enter the appliance **without** breaking the control/data-plane separation that the rest of the architecture depends on.

**In scope**
- A first-class **plugin** mechanism (what OPNsense calls *plugins* and pfSense calls *packages*).
- A **curated package manager** that installs the Debian packages those plugins depend on, from signed, pinned repositories.
- The **integration catalogue**: ZeroTier, NetBird, Lightsquid, iperf3 (server + client), darkstat, and LLDP (`lldpd`).
- The management surface, data model, capability/privilege model, and lifecycle "glue" to the Update and Backup specifications.

**Out of scope (deliberately)**
- A generic, operator-facing `apt` console. Arbitrary package names never come from the browser (see §2, §5).
- Anything that lets a plugin register a new HTTP route or RBAC permission **at runtime** from untrusted data. Plugins are static artefacts, authored and signed by Micron Network Systems; the surface they may add is declared at build time and allowlisted in `micrond` (§7–§8).

> **Relationship to the Connector Specification.** *Connectors* (see the [Connector Abstraction Specification](MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.html)) are the **data-plane policy** extension class: they fetch and stage typed feed data (DNSBL, address groups, PKI material). Plugins and packages are the **service/daemon** extension class: they add a running process, a config file, and a status surface. The two share a governance model (signed, static, capability-granted, audited) but not an execution mechanism. §3 keeps the line sharp.

---

## 2. Design Principles, and What We Refuse to Build

| # | Principle | Consequence |
| :-- | :-- | :-- |
| P1 | **One privileged broker.** Only `micrond` runs as root and only it touches the filesystem outside `/var/lib/micron`. | The Package Manager does **not** shell out from PHP. Every install/remove/reload is an RPC. |
| P2 | **No arbitrary package names from the web UI.** | The UI references a *plugin id* or a *catalogue entry*; the daemon resolves that to a fixed apt package set. A caller can never name `curl` or `openssh-server`. |
| P3 | **Signed and pinned.** | Repositories are curated; every Debian package is signature-verified against a key whose **fingerprint the operator states in advance** (image-shipped for presets, declared out of band for operator-registered ones), and every curated repository is pinned below the distribution (§5.2). Plugin bundles are GPG-signed. |
| P4 | **Declarative, not executable, extension.** | A plugin contributes a manifest (menu, config schema, required packages, declared RPCs, lifecycle hooks as *typed steps*) — never an arbitrary `run_this.sh` that the appliance executes as root on install. |
| P5 | **Capability grants, least privilege.** | A plugin declares capabilities (e.g. `system.service:zerotier-one`, `net.raw`, `dns.upstream`, `firewall.rules`). The operator approves them individually; `micrond` enforces the approved set. |
| P6 | **Reversible.** | Every install/upgrade can be rolled back; uninstall offers *retain data* vs *purge*; a broken daemon must not take the firewall with it. |
| P7 | **Fail closed, and say so.** | If a dependency, signature, or capability is unmet, the plugin is *not* installed — the UI reports exactly which check failed, mirroring the connector guard's error style. |

**What we refuse to build.** A form that accepts a **package name**, or raw `sources.list`/option syntax (§5.2 allows a repository to be *registered*, never apt options, and never a hand-typed package). A plugin that ships PHP that is `include`d into controllers at runtime. A plugin that can restart `nftables`, `wireguard`, `strongswan`, `unbound`, `keepalived` or `frr` (the same units the connector allowlist already refuses). A plugin install that runs arbitrary shell from the manifest.

---

## 3. The Three Extension Classes

The appliance's extension surface is intentionally split so that risk is proportional to what an extension does.

| Class | What it is | What it adds | Reach | Examples |
| :-- | :-- | :-- | :-- | :-- |
| **A — Connector** | Declarative feed adapter (manifest only, no new code) | Fetched, typed policy data (domains, CIDRs, certs) | `dnsbl`, `addressgroup`, `pki`, `service` targets | abuse.ch/URLhaus, Spamhaus |
| **B — Service/Daemon plugin** | Signed bundle + curated Debian packages | A systemd unit, a config file, a status page, an nftables integration | Starts/stops its own daemon; may add rules through a declared target | `lldpd`, `iperf3`, `darkstat`, Lightsquid |
| **C — Third-party overlay / agent** | Bundle that vendors a *third-party* repository and account | A full overlay stack, tunnel agent, or kernel-side engine | Higher: third-party repo, outbound registration, sometimes a kernel module | ZeroTier, NetBird |

Only Class A is fully implemented today (the Connector engine). This document specifies B and C.

---

## 4. Plugin Anatomy: The `.mplug` Bundle

A MicronOS plugin is a signed archive. It is **not** a tarball of shell scripts; it is a manifest plus static assets, and an explicit, closed list of *typed* lifecycle steps.

```
zerotier.mplug  (tar, gzip, GPG-signed)
├── plugin.json          # the manifest (authoritative; schema below)
├── signature.asc        # detached signature over plugin.json + payload hashes
├── payload/
│   ├── menu/            # optional declarative menu additions
│   ├── views/           # optional CI4 view partials (static, escaped)
│   └── config/          # template config files copied into place
└── checksums.txt        # sha256 of every payload file
```

### 4.1 `plugin.json` schema

```json
{
  "schema": 1,
  "id": "zerotier",
  "name": "ZeroTier One",
  "version": "1.14.0-1",
  "vendor": "Micron Network Systems",
  "description": "ZeroTier virtual network overlay agent.",
  "class": "C",
  "min_appliance": "2.0.0",
  "max_appliance": "2.x",
  "provides": {
    "services": ["zerotier-one"],
    "menu": [
      { "path": "/plugins/zerotier", "label": "ZeroTier", "permission": "plugins.zerotier.view" }
    ],
    "settings_schema": "payload/settings.schema.json",
    "status_source": "rpc:zerotier.status"
  },
  "requires": {
    "packages": ["zerotier-one"],
    "repos": ["zerotier"],
    "kernel_modules": [],
    "binaries": ["/usr/sbin/zerotier-one"]
  },
  "capabilities": [
    "system.service:zerotier-one",
    "root.rpc:zerotier.status",
    "root.rpc:zerotier.join",
    "firewall.ifgroup:zt+",
    "net.raw"
  ],
  "lifecycle": [
    { "step": "install",   "action": "apt.install",  "packages": ["zerotier-one"] },
    { "step": "enable",    "action": "system.enable", "unit": "zerotier-one" },
    { "step": "configure", "action": "template.render", "src": "payload/config/zerotier.local.conf", "dst": "/var/lib/zerotier-one/local.conf" },
    { "step": "uninstall", "action": "apt.remove",   "packages": ["zerotier-one"],
      "data": { "paths": ["/var/lib/zerotier-one"], "policy": "ask" } }
  ]
}
```

**Closed vocabularies** (validated at load, exactly like the connector manifest's closed enums):

| Field | Allowed values |
| :-- | :-- |
| `class` | `A` \| `B` \| `C` |
| `action` (lifecycle) | `apt.install` \| `apt.remove` \| `system.enable` \| `system.disable` \| `system.start` \| `system.stop` \| `template.render` \| `file.copy` \| `dir.ensure` \| `pki.issue` \| `dns.setupstream` \| `nft.set` |
| `capabilities` | a fixed grammar: `system.service:<unit>`, `root.rpc:<name>`, `net.raw`, `kernel.module:<name>`, `dns.upstream`, `firewall.rules`, `firewall.ifgroup:<prefix>`, `fs.write:<path>` |

Anything outside those vocabularies fails manifest validation *before* any install step runs. There is no `shell.run`, no `exec`, and no dynamic code-loading action.

**`provides.menu` is validated too.** Each entry is an object with the closed key set `path` / `label` / `permission` only:

| Key | Rule |
| :-- | :-- |
| `path` | Must match `^/plugins/[a-z0-9][a-z0-9/-]*$` and must not contain `..` — a bundle can contribute a page inside the plugin namespace, never a route into the core UI |
| `label` | Non-empty; this is the human text shown in the navigation |
| `permission` | Optional; when present the entry is only shown to a session holding it |

A manifest whose menu declares an unknown key, an empty label, or a path outside `/plugins/` is rejected before install. The management plane reads these entries for the navigation (see §9.1).

### 4.2 Settings schema

`settings_schema.json` is a JSON-Schema subset (string/int/bool/enum/secret, `required`, `default`, `pattern`) that the management plane renders into a settings form. **`secret` fields are encrypted with the same `SecretsVault` (AES-256-GCM) the connector engine uses** and are never returned to the browser or written to logs — the plugin's daemon receives them at `template.render` time only.

---

## 5. The Package Manager

### 5.1 Model

Under the hood the Package Manager is apt, but it is **never** driven by an operator-typed package name. It is a *hermetic* front end over one or more **curated, signed** repositories:

```
┌───────────────────────────────────────────────────────────────────────┐
│  Plugins page (Browse / Installed / Updates)                          │
│  "Available: ZeroTier One 1.14.0-1  [Install]"                        │
└───────────────────────────────┬───────────────────────────────────────┘
                                │ RPC: plugin.catalog  /  plugin.install {id}
┌───────────────────────────────▼───────────────────────────────────────┐
│  micrond  →  PackageManager                                           │
│    1. resolve plugin id → packages (from the signed catalogue)       │
│    2. apt-get update against ONLY the curated source list            │
│    3. dry-run: dependency + conflict resolution                       │
│    4. verify signatures against pinned keys                           │
│    5. install into the running slot; snapshot for rollback            │
│    6. run the manifest's typed lifecycle steps                        │
│    7. reload/refresh only the affected daemons                        │
│    8. emit plugin.audit event                                         │
└───────────────────────────────────────────────────────────────────────┘
```

### 5.2 Repository curation

A repository is a row in `plugin_repos`, managed on the **Repositories** screen (§9.5). It is *registered*, never written by hand: `micrond` generates every file, from a closed grammar, and the operator supplies only validated fields.

| Property | Rule |
| :-- | :-- |
| **Source list** | `/etc/apt/sources.list.d/micron-<id>.sources`, `deb822`, generated by the daemon. `Types: deb` only — a `deb-src` is never emitted, so a curated repository cannot feed the build toolchain. There is **no field for apt options**, so `[trusted=yes]`, `allow-insecure` and friends are unexpressible; the URL grammar (`https://`, no credentials, no query, no whitespace, no brackets) is the injection boundary. |
| **Signing keys** | `/etc/apt/keyrings/micron-<id>.gpg`, referenced by `Signed-By`. The operator declares the **fingerprint out of band** (from the vendor's documentation — never from the key file); the daemon imports the supplied or fetched key into a private keyring, compares fingerprints, refuses a mismatch, and exports **only the verified key**. A key URL is resolved by the management plane through the shared `ConnectorEgressGuard`, so it inherits the SSRF defence and byte caps; `micrond` never fetches a key itself. |
| **Pinning** | Two layers. Each repository is `Pin: origin "<host>"` + `Pin-Priority: 100`, below the distribution's own 500, so a package the distribution ships is always taken from the distribution. Independently, **every protected core package is pinned to the appliance's own release** (`Pin: release o=<Origin>, n=<codename>`, priority 990) from `/etc/os-release` — so a curated repository can supply packages the distribution lacks but can never supply, let alone upgrade, `nftables`, `unbound`, `kea-*` or any other core dependency. If the distribution identity cannot be read the core pin is *not* written and the apply fails closed. |
| **Reconcile** | `plugin.repo.apply` is declarative, like `dhcp.relay_apply`: the enabled set is applied and any file `MicronOS` previously generated for a repository that is now disabled or removed is retired. Only files named `micron-*` are ever listed or deleted; the appliance's own sources are untouchable, and the protected-core pin is explicitly excluded from retirement. |
| **Verify** | `plugin.repo_test` — a **read-only** check of one repository, offered as a *Test* action per row. The management plane fetches the release metadata (`InRelease`, or the detached `Release` + `Release.gpg`) through the egress guard; the daemon imports the signing key into a **private** keyring, verifies the signature against it, and compares gpg's `VALIDSIG` signer to the declared fingerprint. Because that keyring holds only the key whose fingerprint was already checked, one call proves three things at once: the repository is reachable, its metadata is signed, and it is signed by the key the operator named. No source, pin, keyring or apt list is written, nothing is cached and no package operation runs — so a vendor's repository can be checked *before* it is trusted. The release is also compared with what was declared (suite/codename, component, architecture, `Valid-Until`) and any divergence is reported as a warning rather than a failure, since it is the misconfiguration a bare reachability probe misses. The same read-only check is offered for a **preset** (`plugins/repositories/preset/<id>/test`, permission `plugins.repo.refresh`), and it deliberately proves the *image-shipped* trust data (`App\Libraries\RepoPresets`) rather than a stored row: the preset's own URL, suite, armor and declared fingerprint are the inputs, and the preset need not be registered at all. The answer is therefore about what *this image* would install from, independent of any row the operator has registered — and of a row they may have re-pointed at a different key after a rotation. It leaves `plugin_repos` untouched, including `last_verified_at`: proving shipped trust data is not adopting it, and a check the operator ran against the image must not stamp a verification onto a repository it never examined. |
| **Register / enable** | Both are gated on verification. Saving a repository (registering or editing) and enabling a disabled one fetch the release metadata and check the signing key against the declared fingerprint **first**, and refuse a source that cannot be reached or does not match — the failure is shown on the page rather than stored. The check runs from the controller (`Packages::store()` / `Packages::toggle()`) against the *candidate* definition, since a register has no row to look up until after it has decided, via `RepoRegistry::verify()`. `RepoRegistry::save()` deliberately stays ungated, so restore, image-shipped presets and reconciliation are never blocked by a network condition. A deliberately offline check is an **explicit override** (`skip_verify`), never a silent fallback, and the success message says the row was saved *unverified*; disabling a repository is never gated, because retiring a source must always be possible. |
| **Refresh** | `plugin.repo.refresh` — operator-triggered or scheduled; metadata only, never an install. |
| **Drift** | A scheduled sweep (`micron:repo-drift`, the `cron_plugin_repo_drift` task, daily 04:45) refreshes metadata and, for every **enabled** repository, compares one observation against the last. A curated repository can be perfectly configured and still be quietly dead, so the things it watches for are exactly the ones a single check cannot see: a metadata `Date:` that never advances (the vendor stopped publishing), a signing key whose *own* validity is running out (independent of the metadata's `Valid-Until`), a `Valid-Until` approaching or past, or metadata now signed by a different key — a rotation, which the daemon reports as a machine-readable `reason` so it is told apart from an unreachable CDN. The verdict is written back onto the row (`drift_kinds`, `drift_since`, `drift_detail`, `last_check_at`) beside the raw inputs it was drawn from (`metadata_date`, `metadata_seen_at`, `key_created_at`, `key_expires_at`), because none of it is visible from a single reading; `drift_since` deliberately survives a sweep so the age of a problem does not reset each time it is noticed. The same sweep runs on demand from the screen. Metadata refresh and verification are both read-only with respect to what apt may install, so the task cannot widen the package surface however long it runs. Every sweep also **re-verifies** each repository in service — the same read-only check the *Test* action runs — so a source that verified before and no longer does is the event an operator most needs to hear about. That regression is flagged **Verification lost** (a warning, carrying the daemon's reason), and it is told apart from a repository that never verified by `last_verified_at`, the last **successful** verification, which a failure deliberately leaves in place (`2026-10-06-000096_AddRepositoryVerificationTracking`); a successful register or enable stamps it too. The command exits **non-zero** on a critical finding, so a monitor watching cron sees what a person reading it would. |
| **Registry** | At most 16 repositories. Duplicate sources, an enabled repository with no key, and a URL pointing at the appliance's own distribution host are **conflicts** that block an apply (and are refused at save time); two repositories sharing a signing key is a warning. |
| **Presets** | Image-shipped trust data for a known vendor lives in `App\Libraries\RepoPresets`: the id, name, URL, suite/components/architectures, the **declared fingerprint**, and the vendor's **armored public key inline**. Registering one (`plugins/repositories/preset/<id>`, or `micron:plugin repos --seed <id>`) passes through the *identical* validation as a hand-entered row, so a preset can only pre-fill what the operator could have typed; an existing definition is never rewritten, so re-seeding cannot silently re-point a row the operator has changed. A preset arrives **disabled**, and because the key travels inline an `apply` needs no vendor fetch. The shipped claim is re-checkable from the screen: each preset card carries a **Verify against vendor** action (`plugins/repositories/preset/<id>/test`) that runs the read-only check described under **Verify** above — reachability, signature, declared fingerprint — *before* the preset is registered, and renders the verdict in the page's verification panel. |
| **Manifest binding** | A manifest names repositories in `requires.repos`. Resolution is appliance state: a plugin whose repository is not registered **and enabled** is *blocked* with the repository named, never half-installed. |
| **Version catalogue** | A signed JSON feed (§9.4) is the only source of "a newer plugin version exists". It is verified against a key shipped in the image and is *descriptive* — it cannot widen what apt is permitted to install. |
| **Backup** | The registry travels in configuration backups and a restore **reconciles the daemon with it** (`Backup::applyRestore()` → `apply()`), so a rebuilt appliance gets its sources, keyrings and pins back out of the archive — no vendor fetch, because the archived rows carry the armored key inline. Restoring only the *Package Repositories & Signing Keys* section restores just this registry. |
| **Offline / air-gap** | Import a signed catalogue envelope and/or upload a `.mplug` + a `.deb` bundle; the same signature and dry-run gates apply. A key fetched once is cached inline, so a restored repository does not depend on the vendor URL being reachable. |

**Third-party repositories are the reason Class C exists.** ZeroTier and NetBird publish their own apt repos. The Package Manager permits a repository only through the mechanism above: the operator may *register* one, but cannot express apt options, cannot supply a key whose fingerprint they did not state in advance, and cannot make the repository outbid the distribution.

**Shipped preset — NetBird.** `RepoPresets::PRESETS['netbird']` carries the vendor's real trust data for `https://pkgs.netbird.io/debian` (suite `stable`, component `main`, `amd64`/`arm64`), with the signing key's fingerprint `EFE37DF047DF7CCDF1FC54FA83F79AD029778355` and the vendor's 2440-byte armored public key embedded verbatim. `2026-10-06-000093_SeedNetbirdPresetRepository` seeds the row **disabled**, so the Class C plugin `netbird` is *blocked with the repository named* until an operator enables and applies it — the third-party-repo path is therefore exercised end to end, not merely specified. `tests/app/Libraries/RepoPresetsTest.php` and `tests/test_repo_presets.py` recompute the fingerprint from the embedded key, so a corrupted paste fails in CI rather than on the appliance. The shipped claim is re-checkable on demand, too: `tests/test_repo_presets_live.py --live` is an **opt-in** harness that fetches each preset's `dists/<suite>/InRelease` (falling back to `Release` + `Release.gpg`), verifies it against the shipped key in a private keyring, and requires gpg's `VALIDSIG` signer to equal the declared fingerprint — the same proof the daemon's `plugin.repo_test` makes, without an appliance. It is opt-in precisely because it reaches the network: a bare invocation fetches nothing and says so, so the default test run never depends on a vendor being up.

The same proof is available on the appliance, from the page: each preset card on the Repositories screen carries a **Verify against vendor** action (`plugins/repositories/preset/<id>/test`, the read-only `plugin.repo_test` path) that fetches the vendor's metadata and requires the shipped fingerprint to be the signer, without registering the preset or writing anything. The report is rendered in the same panel a repository *Test* uses — labelled a **preset** verification, naming the shipped fingerprint, its provenance (`key_source_url`) and the `verified_at` the image records — so a verdict about the image's trust data is never mistaken for one about a registered row.

### 5.3 Dependency & conflict resolution

Resolution is a dry-run first: `apt-get -s install <resolved set>`. The result is surfaced to the operator as a **plain-language plan** ("will install `zerotier-one`, 3 dependencies; will not remove anything; 1.4 MiB"). Conflicts that would remove a core package (anything in the protected set: `nftables`, `unbound`, `wireguard-tools`, `strongswan`, `frr`, `keepalived`, `suricata`, `squid`, `kea-*`, `chrony`, `rsyslog`, `snmpd`) are refused outright — the plugin is marked **uninstallable on this appliance** with the blocking package named.

### 5.4 Transaction & rollback

An install/upgrade is a transaction:

```
begin  → snapshot dpkg selections (dpkg --get-selections) + plugin rows
       → install/upgrade
       → run typed lifecycle steps
       → health-check the declared service (systemctl is-active + a plugin-declared probe)
commit → keep; record in plugin_install_log
rollback → apt reinstall previous versions from the dpkg cache, restore plugin rows,
           re-run the inverse of the typed steps, restart the pre-existing daemons
```

This mirrors the existing `SafeTransactionManager` pattern used by the firewall compiler. A failed health-check after upgrade triggers an automatic rollback and an alert.

---

## 6. Lifecycle: Browse → Install → Configure → Enable → Upgrade → Remove

| State | Meaning | UI affordance |
| :-- | :-- | :-- |
| `available` | In the catalogue, not installed. | **Install** (shows the plan + capability grants) |
| `installing` | Transaction in flight. | Progress; cancel = rollback |
| `installed` | Files present, service not yet enabled. | **Configure** / **Enable** / **Remove** |
| `enabled` | Service enabled + started, config rendered. | **Disable** / **Restart** / **Update** / **Remove** |
| `update_available` | A newer signed version exists. | **Update** (same transaction) |
| `broken` | Last transaction failed or health-check failed. | **Repair** (re-run steps) / **Rollback** / **Remove** |
| `blocked` | Conflicts with a core package or appliance version. | Shows the blocking reason; no install button |
| `removing` | Uninstall in flight; asks retain vs purge. | — |

**Configure** renders `settings_schema.json`; **Enable** renders config templates (with decrypted secrets) via typed `template.render`/`file.copy` steps and then `system.enable`+`system.start`. **Remove** always offers *retain configuration and data* (default) vs *purge*, matching the Backup spec's "credentials excluded unless encrypted" philosophy — the destructive path is explicit.

---

## 7. Capability Grants & Privilege Model

A plugin is inert until the operator grants capabilities. Grants are recorded per plugin and enforced by `micrond` at every call.

| Capability | Grants | Enforcement point |
| :-- | :-- | :-- |
| `system.service:<unit>` | control *that one* unit (start/stop/restart/enable/disable) | `plugin.service` RPC checks the per-plugin allowlist; the unit must also exist in the catalogue's pinned set |
| `root.rpc:<name>` | call a **named** plugin RPC that the daemon compiles in | `micrond`'s plugin RPC registry |
| `net.raw` | create overlay interfaces / raw sockets | gate before any `ip`/`wg`/tun operation |
| `kernel.module:<name>` | load a specific out-of-tree module | refused unless the module is signed and on the image's allowlist |
| `dns.upstream` | change Unbound forwarders (resolver-upstream modes) | `dns.setupstream` step; conflicts flagged as an either/or mode |
| `firewall.rules` / `firewall.ifgroup:<prefix>` | add rules / named sets for its own interfaces only | nftables compiler; namespace bounded by the prefix |
| `fs.write:<path>` | write under a declared path | path must resolve inside `/etc/micron/plugins/<id>` or `/var/lib/micron/plugins/<id>` |

**Default deny.** A capability not listed in `plugin_grants` (or listed as revoked) means the corresponding operation is refused with **no command run** — the same posture as `CONNECTOR_SAFE_UNITS`.

**Protected units.** Even with a grant, a plugin can never control the protected set (`nftables`, `wireguard`, `strongswan`, `unbound`, `keepalived`, `frr`, `kea-*`, `suricata`, `chrony`, `rsyslog`, `snmpd`). It may *read* their state and may ask the core to reload through an existing admin RPC; it may not stop them. This is the plugin analogue of the connector/`system.service` split.

---

## 8. Daemon-Side Enforcement (`micrond`)

New RPC families, all resolved by id rather than by caller-supplied unit/package/path:

| RPC | Params | Notes |
| :-- | :-- | :-- |
| `plugin.catalog` | `{}` | Returns the signed catalogue index (id, version, class, capabilities). |
| `plugin.repo.refresh` | `{}` | `apt-get update` against the curated sources only — metadata, never an install. |
| `plugin.repo.apply` | `{ repos: [{ id, name, url, suite, components, architectures, fingerprint, key_armor }] }` | Declarative reconcile (§5.2): verifies each fingerprint, generates `micron-<id>.sources` / `.pref` / keyring, writes the protected-core pin, retires undeclared `micron-*` files, then `apt-get update`. Every field re-validated; a bare `key_url` is refused (the management plane resolves it through the egress guard). |
| `plugin.repo_status` | `{}` | What is actually applied: one entry per `micron-*` source on disk, plus whether the protected-core pin exists. |
| `plugin.repo_test` | `{ repo: { id, url, suite, components, architectures, fingerprint, key_armor }, release, signature }` | **Read-only** verification: imports the key into a *private* keyring, checks the fingerprint, verifies the release metadata against it and compares the `VALIDSIG` signer to the declared fingerprint. Writes no source, pin, keyring or apt list and runs no package operation. A bare `key_url` is refused (the management plane resolves it through the egress guard); the release body is capped at 2 MiB. |
| `plugin.install` | `{ id, grants: [...], data_policy }` | Dry-run → install → lifecycles → health-check → audit. |
| `plugin.remove` | `{ id, purge: bool }` | Inverse steps; honours retain-data. |
| `plugin.upgrade` | `{ id }` | Transaction with auto-rollback on failed health-check. |
| `plugin.service` | `{ id, action }` | Per-plugin unit allowlist; protected units refused. |
| `plugin.settings.apply` | `{ id, values }` | Renders templates; secrets decrypted daemon-side only. |
| `plugin.status` | `{ id }` | `systemctl is-active` + plugin-declared probe + version. |
| `plugin.logs` | `{ id, lines }` | Recent journal lines for the plugin's own unit (`journalctl -u <unit>`); protected and unknown units refused. |
| `<plugin>::<rpc>` | plugin-defined | Only names compiled into the daemon and granted to that plugin. |

Enforcement mirrors today's service model: `SERVICE_UNITS` is the admin whitelist, `CONNECTOR_SAFE_UNITS` is the connector whitelist. Plugins get a **third, per-plugin** allowlist built from `plugin_grants` at install time, default-deny for everything else. `micrond` keeps **no** generic "run this for plugin X" path.

---

## 9. Management Plane: Libraries, Controllers & UI

| Component | Responsibility |
| :-- | :-- |
| `App\Plugins\PluginManifest` | Validate `plugin.json` against the closed vocabularies; expose accessors. (Sibling of `App\Connectors\Manifest`.) |
| `App\Plugins\PluginRegistry` | Catalogue index, installed set, version/update diff. |
| `App\Plugins\CapabilitySet` | Parse, normalise and compare declared vs granted capabilities. |
| `App\Libraries\PackageManager` | Talks to `micrond`; never shells out; builds the human-readable install plan. |
| `App\Libraries\PluginHealth` | Post-install/upgrade probe; feeds the `broken` state and alerts. |
| `App\Libraries\PluginNav` | The installed plugins' `provides.menu` entries for the current session, filtered by each entry's permission (§9.1). Filesystem-only, no IPC. |
| `App\Controllers\Plugins` | Browse / install / remove / upgrade / configure / enable / status, plus the per-plugin page (`show`), the Updates screen (`updates`/`upgradeAll`), and the daemon status/control/logs endpoints (§9.2–§9.3). |
| `App\Libraries\RepoRegistry` | The curated repository registry: field grammars, the generated `deb822` stanza and apt pin, the plan's conflicts, the declarative apply, and `requires.repos` resolution. Daemon-free, like `DhcpRelayCompiler`. |
| `App\Controllers\Packages` | The Repositories screen: registry CRUD, declarative apply, metadata refresh, and the read-only verification of a repository row or an image-shipped preset. (Catalogue view and offline bundle upload are still to land.) |
| `App\Models\PluginModel` / `PluginRepoModel` / `PluginGrantModel` | Persistence and audit. |
| `App\Views\plugins/index.php` + `_configure.php` | Grid of plugin cards, schema-driven config modal, capability consent dialog. |
| `App\Views\plugins/show.php` + `plugins/updates.php` | The per-plugin page and the Updates & Upgrades screen (§9.2–§9.3). |
| `App\Views\plugins/repositories.php` | The Repositories screen (§9.5): the registry table, the preset panel, the trust-model panel, the add/edit form, the compiled plan and the verification report panel. |

The management surfaces in §9.1–§9.3 and §9.5 ship in the current tree; the remaining components above (health probe, catalogue view, offline bundle upload, models) are specified and land with the roadmap in §15.

**Permissions** (added to the RBAC catalogue as *static* keys at migration time, never registered at runtime):

| Key | Meaning |
| :-- | :-- |
| `plugins.view` | See installed + available plugins |
| `plugins.install` | Install/remove/upgrade (implies accepting the plan & grants) |
| `plugins.configure` | Edit settings and enable/disable |
| `plugins.repo.refresh` | Refresh repository metadata |
| `plugins.repo.manage` | Register, edit, enable and remove curated repositories, and apply them |
| `plugins.audit.view` | Read the install/rollback history |

A plugin's own menu entries reference a permission **declared in its manifest and created at migration time** — so a malicious or corrupted bundle cannot invent a permission that the UI then honours.

### 9.1 Navigation menus contributed by plugins

A plugin contributes its navigation as declaratively as everything else: entries in `provides.menu`, validated at load (§4.1). The management plane renders them **only for installed plugins**, and only when the session satisfies the entry's optional `permission`.

- **Source.** `App\Libraries\PluginNav::entries()` reads the installed-plugin set from the `plugins` table and resolves each `provides.menu` entry through `PluginRegistry`. It uses the filesystem-only registry (no daemon call), so the per-render cost of building the navigation stays trivial.
- **Filtering.** An entry with a `permission` is shown only when the session holds it, or when the session is superadmin (the same bypass the RBAC filter uses). An entry with no `permission` is visible to any session that can see the page at all. A plugin that is not installed contributes nothing — installing it makes its menu appear, removing it takes the menu away.
- **Placement.** The entries render in two places: the top bar's **Plugins** group (below the *Manager* links) and the sidebar's **Administration** section, each labelled from the manifest and highlighted by path prefix. The group itself also carries the manager entries — *Plugins & Packages* and *Updates & Upgrades*.

```json
"provides": {
  "services": ["iperf3"],
  "menu": [
    { "path": "/plugins/iperf3", "label": "iperf3 Speed Test", "permission": "plugins.view" }
  ],
  "status_source": "rpc:plugin.status"
}
```

Because menu paths are constrained to `/plugins/…`, a bundle can never add an item that resolves into the core route namespace — the navigation can only ever grow inside the extension surface.

### 9.2 Per-plugin pages (`/plugins/<id>`)

Every installed plugin gets its own page at the path its menu entry declares. `Plugins::show()` renders it; the route is registered **after** the literal `plugins/*` routes (`updates`, `daemon…`, `refresh`) so those always win over the `(:segment)` catch-all.

- **Guard.** A request for a plugin whose manifest is unknown *or* which has no install row is a `404` — the page exists only for something actually installed.
- **Identity.** The header shows name, class, shipped version, and enabled/installed state; the body lists the install facts (installed version — and, when newer, the shipped version — install date, enabled flag), the declared packages and systemd units, the manifest's menu entries, and the vendor/description.
- **Daemon control.** A plugin that declares units gets the shared service widget: live run-state, **Start / Restart / Stop**, and a click-through to the journal. It is driven by three JSON endpoints — `plugins/daemons/status` (bulk poll), `plugins/daemons/<id>/control`, and `plugins/daemons/<id>/logs` — with the same contract the DHCP-relay instance controls use. Each endpoint gates on the plugin being installed and declaring a unit.
- **Grants.** The declared-capability table lists every capability and whether the operator granted it, with who granted it.
- **Actions.** *Update* (per-plugin upgrade) and *Remove* (data retained) are presented here.
- **Plugin-contributed UI.** If the bundle ships a view at `Views/plugins/apps/<id>.php`, `show()` renders that instead of the generic metadata page — the hook a plugin uses for a real tool. The `iperf3` plugin is the reference for Class B: its menu entry `/plugins/iperf3` lands on a throughput runner (client test with target/port/duration/protocol/direction/streams, and a time-bounded on-demand server), while plugins without such a view fall back to the metadata page. The `netbird` plugin is the reference for Class C: `Views/plugins/apps/netbird.php` renders the overlay's live status (FQDN, overlay IP, interface, public key, management/signal reachability, peer counts) from `plugin.netbird.status` — refusing to draw a page at all unless the plugin is installed — with **Connect / Disconnect** (`plugin.netbird.up` / `.down`) and a setup-key enrolment form (`plugin.netbird.enroll`) that stages the key daemon-side into a `0600` file, so it never reaches the process table, a log line or the response. The `zerotier` plugin is the second Class C page and the reference for a *membership-driven* overlay: `Views/plugins/apps/zerotier.php` renders the node's identity (address, version, online state) and a table of the networks this node has joined, from `plugin.zerotier.status`, and drives **Join network** / **Leave** through `plugin.zerotier.join` / `.leave` with nothing but a 16-hex network id. Because a network's *name* is chosen by whoever controls that network, every cell is built as a text node and never as markup.

### 9.3 Updates & Upgrades screen (`/plugins/updates`)

`Plugins::updates()` is the plugin-lifecycle counterpart to the appliance-wide **System Updates** page in the [Update & Upgrade Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html): plugins are moved here, OS packages there.

- **List.** Every installed plugin with its installed version, its available version, enabled state, and packages. The available version is the **signed catalogue's** when it names a newer release, otherwise the shipped manifest's; each row is labelled `FROM CATALOGUE` or `SHIPPED`, and `update_available` is `version_compare(available, installed, '>')`.
- **Check Signed Catalogue** — `plugins/catalogue/sync` (permission `plugins.repo.refresh`); fetches, verifies and caches the feed (§9.4). The header carries a `CATALOGUE VERIFIED` badge and a panel with the feed version, source URL, signing key id, issue/expiry, fetch time and SHA-256; a cache older than `maxAgeHours` is flagged **STALE**.
- **Refresh Repositories** — `plugins/refresh`, permission `plugins.repo.refresh`; forwards to the `plugin.repo.refresh` RPC (`apt-get update` against the curated sources only).
- **Upgrade All** — `plugins/upgrade-all`, permission `plugins.install`; iterates the installed plugins, calls `plugin.upgrade` for each, and records the version the operator upgraded **to** — the catalogue's when it is newer, otherwise the shipped manifest's. A partial failure is reported with the offending plugin ids rather than a blanket success.
- **Per-plugin Upgrade** — `plugins/<id>/upgrade`, the same transaction scoped to one plugin, recording the same target version.

### 9.4 The signed remote catalogue

The Updates screen detects newer versions from a document published by Micron Network Systems and signed by a key shipped in the image. `App\Libraries\RemoteCatalogue` fetches it through the shared `ConnectorEgressGuard` — so it inherits the same SSRF defence, DNS-rebinding pinning, redirect validation and byte caps the connector engine uses — and `App\Libraries\CatalogueVerifier` verifies it before anything is written. Only a verified document is cached (`writable/plugin-catalogue.json`), and the cached envelope is **re-verified on every read**, so rotating a key out of the keyring invalidates it immediately.

The feed is a single envelope document so verification never depends on JSON canonicalisation — the signature covers the *exact bytes* of the inner catalogue, transported base64-encoded:

```json
{
  "schema": 1,
  "signature": { "algorithm": "RSA-SHA256", "key_id": "micron-plugins-2026", "value": "base64" },
  "payload": "base64(<catalogue JSON>)"
}
```

The signed payload carries `schema`, `issued_at`, `expires_at`, `catalogue_version`, and a `plugins[]` array (`id`, `version`, `class`, `packages`, `units`, `capabilities`, and optional `bundle` metadata for the signed `.mplug`).

**Trust and failure modes.**

| Check | On failure |
| :-- | :-- |
| Trusted key | Keys come from the image keyring (`/etc/micron/pkg/keys/*.pem`, key id = file stem) plus inline dev keys in `Config\PluginCatalogue`. An unknown `key_id` is refused. |
| Signature | The payload bytes are verified with OpenSSL against the named key; a tampered payload or a bad signature is refused. |
| Currency | A document issued in the future (beyond a small skew) or past `expires_at` is refused. |
| Schema | An unexpected `schema`, a malformed entry, an invalid or duplicate plugin id, or a missing version is refused. |
| Transport | The URL must be `https` (unless `requireHttps` is relaxed). |

Nothing above is ever cached — a refused catalogue leaves the previous verified cache untouched, and the screen shows why.

**Offline / air-gap.** `RemoteCatalogue::import()` verifies a local envelope with the same gates and no URL configured; the operator's explicit action is the consent. The CLI exposes it as `micron:plugin catalogue --file <envelope>` (Plugin Spec §5.2, §14).

**Configuration.** `Config\PluginCatalogue` — `enabled`, `url`, `keyringPath`, `trustedKeys`, `cachePath`, `maxAgeHours`, `maxSkewSeconds`, `requireHttps` — every property overridable by environment (`PluginCatalogue.url`, …). When unconfigured the screen says so and falls back to shipped-manifest comparison.

> **What a valid catalogue cannot do.** It is *descriptive*, not authoritative. It can only name versions for plugin ids the daemon already knows; `plugin.install`/`plugin.upgrade` still resolve the id against `micrond`'s fixed catalogue and still refuse protected packages/units. A compromised or forged feed cannot introduce a package set, a unit, or a repository — at most it can misstate a version. Fetching the newer packages remains an apt action through the daemon, so the catalogue changes *detection*, never the privilege boundary.

### 9.5 Repositories screen (`/plugins/repositories`)

The registry behind `requires.repos` and the curated package sources (§5.2).

- **Registry.** Every registered repository with its generated source path, suite/components/architectures, signing-key fingerprint (tail only) and key provenance (`KEY STORED` / `FETCHED PER APPLY`), enabled state, applied state (`APPLIED` / `NOT APPLIED`), and the outcome of the last apply. Presence on the daemon means applied — the same presence-based contract the DHCP relay widget uses, read from `plugin.repo_status`.
- **Trust model.** The page states, in the order the daemon performs them, what an apply will do: generate the stanza, verify the fingerprint, pin the repository to 100, pin the protected core to the appliance's own release, and reconcile. It reports the core pin path once it exists.
- **CRUD.** `plugins/repositories/store` upserts a repository (permission `plugins.repo.manage`); `plugins/repositories/<id>/toggle` enables/disables; `plugins/repositories/<id>/delete` forgets it (its files are retired by the next apply). An invalid field is refused at save time, so a stored repository always compiles. Registering and enabling are also **verified first** — the release metadata is fetched and the signing key checked against the declared fingerprint, and an unreachable source or a key that does not match is refused before anything is persisted or brought into service. An explicit *Save without verifying* checkbox (and an *Enable offline* action) is the only way past it, for a deliberately offline check; the flash then says the row was saved unverified. Disabling is never gated.
- **Apply / Refresh.** `plugins/repositories/apply` reconciles (permission `plugins.repo.manage`) and is **disabled** while the plan has conflicts; `plugins/repositories/refresh` is metadata-only (permission `plugins.repo.refresh`). A conflicting plan is refused before anything reaches the daemon.
- **Presets.** A panel of image-shipped vendor definitions (`App\Libraries\RepoPresets`) with a *Register* action per preset — `plugins/repositories/preset/<id>`, permission `plugins.repo.manage`. The preset's declared fingerprint is shown *before* it is registered. Registering goes through the identical validation as a typed row and refuses to overwrite an existing definition, so a preset is a starting point the operator can then edit, disable or delete like any other, never a hidden override. Each card carries its own **Verify against vendor** action — `plugins/repositories/preset/<id>/test`, permission `plugins.repo.refresh` — which proves the *shipped* trust data against the vendor (reachability, signature against the shipped key, `VALIDSIG` signer equal to the declared fingerprint) **without registering the preset**, leaving the registry exactly as it was. The card shows the last preset check's outcome as a `VERIFIED` / `FAILED` badge.
- **Compiled plan.** Each enabled repository is shown with the exact `deb822` stanza and apt pin that will be written, so nothing about the resulting system state is implicit.
- **Test.** `plugins/repositories/<id>/test` (permission `plugins.repo.refresh`) verifies the repository **without changing anything** and stores the report in the session, which the page renders as a panel: the declared versus held fingerprints, the verified signer and signature form, the release's origin/suite/codename/architectures/components/`Valid-Until`, the steps taken, and any warnings. The tested row is flagged `TESTED` / `TEST FAILED`. The panel states plainly that nothing was written, is stamped with the moment of the check, and can be dismissed (`plugins/repositories/test/clear`). The **preset** check renders in the same panel, titled `Preset verification — <name>` and badged *image-shipped trust data*: it names the shipped fingerprint and where it was re-derived from, and says explicitly that no repository was registered, enabled or applied — because a statement about the image's shipped trust data must not be read as a verdict on a row the operator authored.
- **A report describes one definition at one moment.** Editing, enabling/disabling or deleting a repository — or registering one over it — drops the stored report (`App\Controllers\Packages`), so the page can never keep claiming a verification of a row that has since changed. A *rejected* submit or an unknown id changes nothing and keeps it. The check is read-only, so it deliberately survives `apply` and `refresh`; re-run it when the vendor's metadata should be re-confirmed.
- **Drift.** Health, as opposed to trustworthiness. A per-row **Drift** column (`HEALTHY` / `NOT CHECKED` / kind chips, with the day flagged and the signing key's expiry) and a **Repository drift** panel listing what is flagged, why, since when, and what to do about it — the remedy is part of the model (`App\Libraries\RepoDrift::remedyOf()`), because a badge that says only "DRIFT" is not actionable. `plugins/repositories/drift` (permission `plugins.repo.refresh`) runs the same sweep as the scheduled task at once; the panel states whether the sweep is actually scheduled, and warns when the task is **missing, disabled or has never run**, because a badge nobody refreshes looks like an assurance. Thresholds live in one place: metadata stale after 21 days, a metadata `Date:` unchanged for 14 days is *not updating*, `Valid-Until` inside 7 days is expiring, a key inside 30 days of its own expiry is expiring. `not_updating` is reported *instead of* the weaker `metadata_stale` — it is the stronger, more actionable signal. A defect in *our* stored definition (`invalid_repository`, `unknown_repository`) is `definition_invalid`, critical, rather than a transient warning that could sit unnoticed. Alerting keys on change: a newly flagged or just-recovered repository is recorded in the audit log, while a standing finding is not re-announced every day — the sweep's own run history carries the pattern instead. The sweep re-verifies as well as refreshes, so a source that verified before and no longer does is flagged **Verification lost** with the reason (e.g. `release_unreachable`, `signer_mismatch`); the row's last good verification is shown as `verified <date>` in the Drift column and names the date in the finding, so a regression reads as *"this worked on 2026-10-01 and now does not"* rather than an undifferentiated failure.

---

## 10. Data Model

```sql
CREATE TABLE plugins (
    id              VARCHAR(64) PRIMARY KEY,   -- catalogue/plugin.json id
    name            VARCHAR(128) NOT NULL,
    version         VARCHAR(32)  NOT NULL,
    class           VARCHAR(1)   NOT NULL,     -- A | B | C
    state           VARCHAR(16)  NOT NULL,     -- available|installed|enabled|broken|blocked
    installed_at    DATETIME     NULL,
    enabled         TINYINT(1)   DEFAULT 0,
    data_policy     VARCHAR(8)   DEFAULT 'retain', -- retain | purge
    manifest_json   TEXT         NOT NULL
);

CREATE TABLE plugin_grants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    plugin_id       VARCHAR(64)  NOT NULL,
    capability      VARCHAR(128) NOT NULL,     -- e.g. system.service:zerotier-one
    granted         TINYINT(1)   DEFAULT 0,
    granted_by      VARCHAR(64)  NULL,
    granted_at      DATETIME     NULL,
    UNIQUE (plugin_id, capability)
);

CREATE TABLE plugin_repos (
    id              VARCHAR(64) PRIMARY KEY,
    name            VARCHAR(128) NULL,
    url             VARCHAR(255) NOT NULL,      -- https, single line, no apt option syntax
    suite           VARCHAR(64)  NULL,          -- 'stable', …, or './' for a flat repository
    components      VARCHAR(255) NULL,          -- comma-separated; empty for a flat repository
    architectures   VARCHAR(64)  NULL,          -- comma-separated, from the closed Debian set
    key_fingerprint VARCHAR(64)  NOT NULL,      -- the trust anchor, declared out of band
    key_url         VARCHAR(255) NULL,          -- fetched through the egress guard when key_armor is empty
    key_armor       TEXT         NULL,          -- public key, cached so a restore needs no fetch
    enabled         TINYINT(1)   DEFAULT 1,
    last_refresh    DATETIME     NULL,
    last_outcome    VARCHAR(16)  NULL,          -- ok | error
    last_detail     TEXT         NULL,
    added_by        VARCHAR(64)  NULL,
    created_at      DATETIME     NULL
);

CREATE TABLE plugin_settings (
    plugin_id       VARCHAR(64)  NOT NULL,
    setting_key     VARCHAR(64)  NOT NULL,
    value           TEXT         NULL,          -- secrets stored ciphertext only
    is_secret       TINYINT(1)   DEFAULT 0,
    PRIMARY KEY (plugin_id, setting_key)
);

CREATE TABLE plugin_install_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    plugin_id       VARCHAR(64)  NOT NULL,
    action          VARCHAR(16)  NOT NULL,      -- install|upgrade|remove|rollback
    outcome         VARCHAR(16)  NOT NULL,      -- ok|failed|rolled_back
    plan            TEXT         NULL,          -- the dry-run plan shown to the operator
    detail          TEXT         NULL,
    user_id         VARCHAR(36)  NULL,
    created_at      DATETIME     NOT NULL
);
```

Plugins are, like connectors, part of the appliance's **configuration backup**. `plugins` and `plugin_grants` hold no secret — a manifest is the plugin's public shape and a grant is a capability name the operator already approved — so they travel in a plaintext archive as well as an encrypted one, alongside `plugin_repos`. `plugin_settings` is the one mixed table: its non-secret rows are configuration and travel in both forms, while its **secret** rows (`is_secret`) obey §7.6 of the Connector Specification / Backup engine and ride **only inside the encrypted envelope**, exactly as `connector_secrets` does. A plaintext archive is therefore free of plugin credentials, and — because a restore never truncates `plugin_settings` — restoring one adds the non-secret rows without destroying the secret rows the target already holds.

---

## 11. Integration Catalogue

The request that motivated this document named a specific set. Each is placed in its correct class, with its real dependency and risk. This table is the single source of truth for what MicronOS ships as an integration, and it is machine-checked: `tests/DocsIntegrationCatalogueTest.php` requires it to list *exactly* the registered integrations, and fails if any document names an entry that was removed — a name cannot creep back in unnoticed.

| Integration | Class | Source | Provides | Notable privilege / risk | Phase |
| :-- | :-- | :-- | :-- | :-- | :-- |
| **LLDP (`lldpd`)** | B | Debian `main` | Neighbour-discovery daemon + Interfaces-page neighbour table | `net.raw` (LLDP frames); low risk, read-only telemetry | 1 |
| **iperf3** | B | Debian `main` | On-demand `iperf3 -s` + client UI under Diagnostics | Binds a listening TCP port; must be explicitly enabled and time-bounded | 1 |
| **darkstat** | B | Debian `main` | Per-interface traffic statistics daemon + graphs | `net.raw` (promiscuous capture); read-only | 1 |
| **Lightsquid** | B (report) | Curated bundle + Debian `perl`/`gd` | Offline Squid **log reporter**: cron job, static HTML reports, no daemon | Parses Squid logs; **no** new listener; safest Class B | 1 |
| **ZeroTier One** | C | ZeroTier apt repo | Overlay network agent (`zerotier-one`), network join/leave, firewall ifgroup `zt+` | Third-party repo; outbound registration; `net.raw`; its own daemon | 3 · **implemented** |
| **NetBird** | C | NetBird apt repo / self-hosted | WireGuard-based mesh agent, SSO enrolment | Third-party repo; account provisioning; `net.raw`; managed routes | 3 · **implemented** |
| **NAT64 / DNS64** | B | Debian `main` (`tayga`) | IPv6-only clients reach IPv4-only hosts: userspace NAT64 translator + Unbound DNS64 synthesis | Owns its own service unit (`tayga`); writes beside the **protected** `unbound` but never controls it; needs a forwarding accept for its translator interface | 2 · **implemented** |
| **IPv6 tunnel broker (6in4)** | B | none (generated unit) | A free 6in4/SIT tunnel from a broker (tunnelbroker.net, route64.org, …) | `kernel.module:sit`; fixed `/usr/sbin/ip` argv in a generated unit; optional endpoint update contacts a **third-party** service | 2 · **implemented** |

> **Why ZeroTier and NetBird are Class C, not B.** Both require a *third-party* apt repository and an account/controller the vendor operates. That combination — external repo, external identity, and an overlay that changes the routing of live traffic — is qualitatively more dangerous than a distro daemon, even though the code path (install → configure → enable) looks identical. Class C forces the extra consent step and the third-party-repo pinning in §5.2.

**NetBird and ZeroTier are the two implemented Class C references**, and they share one shape. NetBird's manifest (`app/Plugins/definitions/netbird/plugin.json`) declares `class: "C"`, `requires.packages: ["netbird"]`, `requires.repos: ["netbird"]`, the `system.service:netbird` and `net.raw` capabilities, and `root.rpc` entries for the four overlay calls below; the daemon's fixed catalogue marks the entry with `repos: ["netbird"]` and `plugin.install` **refuses the id as `blocked` until `/etc/apt/sources.list.d/micron-netbird.sources` actually exists on the appliance** — enabled in the management-plane database is not enough. The Plugins page additionally requires an explicit Class C acknowledgement checkbox before the install is submitted, and the overlay page is a ThinUI that names the vendor, the fingerprint and the fact that enrolment creates vendor-side state the appliance does not own (§16).

**ZeroTier is the second implemented Class C entry** (`app/Plugins/definitions/zerotier/plugin.json`), and it binds the `zerotier` repository preset to something concrete: `requires.packages: ["zerotier-one"]`, `requires.repos: ["zerotier"]`, the `system.service:zerotier-one` / `net.raw` / `firewall.ifgroup:zt+` surface, and `root.rpc` entries for its three calls. The daemon's fixed catalogue carries the same `repos: ["zerotier"]`, so `plugin.install` refuses the id as `blocked` until `/etc/apt/sources.list.d/micron-zerotier.sources` actually exists on the appliance — the repository being enabled in the management-plane database is not enough, and the preset itself is seeded **disabled**, so nothing reaches the vendor until an operator enables and applies it. Its overlay page (`app/Views/plugins/apps/zerotier.php`, routes `plugins/zerotier/status` and `plugins/zerotier/control`) is driven by network memberships rather than a single peer: it lists the joined networks and drives join/leave by network id.

**NAT64/DNS64 and the IPv6 tunnel broker are the implemented Class B references** for a plugin that owns a *kernel-visible interface*.

- **NAT64 / DNS64** (`app/Plugins/definitions/nat64/plugin.json`) installs Debian `main`'s userspace `tayga` — no kernel module, no third-party repository — and provides the `tayga` unit plus its own page. Applying configuration from the page is a *request*: the daemon validates the prefix against RFC 6052's `/32, /40, /48, /56, /64, /96` set and the dynamic pool before writing `/etc/tayga.conf` and the host-side route unit, and gates the DNS64 half through `unbound-checkconf` with rollback before reloading the resolver. Unbound is a **protected unit** and is never started, stopped or enabled from the plugin — only its fragment is written, beside the resolver's own config. The plugin page reports honestly when `/etc/unbound/unbound.conf` lacks the `unbound.conf.d` include, rather than pretending the fragment is live.
- **IPv6 6in4 tunnel broker** (`app/Plugins/definitions/ipv6-tunnel/plugin.json`) ships **no package**: it generates one `micron-6in4.service` unit whose fixed `/usr/sbin/ip` argv creates the SIT tunnel, addresses and routes from the values the broker gave the operator. `tunnelbroker.net` (Hurricane Electric) is a preset and its documented dyndns2 update URL is a daemon constant — the caller never supplies a URL — with the update key stored in a `0600` file and never echoed. `route64.org` and any other 6in4 broker are selectable as a generic broker; the plugin makes no assumption about a broker-specific API beyond HE's.

Both interfaces are **gated in the firewall compiler**: `NftablesCompiler` emits forward-chain accepts for the translator device (`nat64`) and the tunnel device (`sit1`) only while the owning plugin's `plugins` row is installed and enabled, so a disabled or removed plugin leaves no standing accept. A successful apply recompiles and reloads the ruleset (without safe mode — the operator already confirmed the change and a rollback timer would silently revert the accepts).

---

## 12. Security Model & Threat Analysis

Modelled on the Connector Threat Model's finding style. Severity: High / Medium / Low.

| # | Finding | Severity | Required control |
| :-- | :-- | :-- | :-- |
| **PL1** | **Arbitrary root install.** A package manager that accepts a package name from the browser is remote root. | High | Ids only; `micrond` resolves to a fixed, signed package set (principle P2, §5.1). No `apt install <input>` path exists. |
| **PL2** | **Malicious repository.** A curated repo is compromised or a key is swapped. | High | Image-shipped keys; signature verification mandatory; pinning prevents core-package takeover; key change requires an image update. |
| **PL3** | **Plugin declares an over-broad capability.** e.g. `system.service:nftables`. | High | Protected-unit list is absolute and independent of grants; capability grammar bounds what can even be *declared*. |
| **PL4** | **Uninstall/upgrade breakage.** A failed upgrade bricks a daemon or lingers half-installed. | Medium | Transaction + health-check + auto-rollback; `broken` state is visible; `repair` re-runs the typed steps. |
| **PL5** | **Data loss on remove.** Purge deletes overlay keys/config the operator needed. | Medium | Retain-data is the default; purge is an explicit, confirmed choice; both audited. |
| **PL6** | **Kernel-module / eBPF surface.** Out-of-tree module in the packet path. | High | Only signed modules on the image's allowlist; `kernel.module:<name>` capability; premium/distro-gated; a failed load or panic must not wedge forwarding. |
| **PL7** | **Secret exposure.** Plugin settings hold API keys/tokens. | Medium | Same `SecretsVault` (AES-256-GCM) as connectors; never returned to the browser or logged; injected daemon-side at render time. |
| **PL8** | **Supply-chain drift.** "Updates" pull a new upstream version silently. | Medium | Versions are pinned in the catalogue; upgrades are explicit and shown as a plan; the repo metadata is signed. |
| **PL9** | **Route/permission injection.** A bundle adds an HTTP route or RBAC key the UI trusts. | High | Routes and permissions are **static** and created at migration time; the manifest may only *reference* declared keys; no runtime registration. |
| **PL10** | **Third-party egress.** An overlay agent phones home from the appliance. | Medium | The agent is upstream's responsibility, but its outbound is surfaced (status page + the appliance's own egress rules); Class C consent states it plainly. |

The recurring theme is the same as the connector work: **the caller names an id, never a unit, package, path or command**; the privileged daemon resolves it against a signed, curated, default-deny table; and the operator approves capabilities one at a time.

---

## 13. Lifecycle Coupling: Updates, A/B Slots & Backup

- **Platform updates (A/B).** Plugins and their Debian packages live in the running root slot, which the [Update & Upgrade Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html) treats as immutable between platform releases. A platform update therefore **re-installs** the plugin set into the new slot by replaying the recorded `plugins` state — plugins are configuration, and configuration survives the slot flip. Plugin *state* (grants, settings, catalogue) lives in the data partition.
- **Management-plane releases.** Because plugins reference routes/permissions created at migration time, a plugin whose manifest is newer than the app's declared compat range is held in `blocked` until the app updates — the same schema-compatibility gate the Update spec already defines.
- **Backup/Restore.** The curated repository registry (`plugin_repos`) and the signing keys that anchor it are archived with the rest of the configuration, and a restore **re-applies** them to the daemon — so a rebuilt appliance can reach its Class C packages again without the operator re-typing a fingerprint and without reaching the vendor. The rows hold no secret (`key_armor` is the vendor's *published* public key), so they travel in a plaintext archive as well as an encrypted one; what is asserted is that the key material sits **inside** the envelope, never beside it. Selective restore exposes the registry as its own section, *Package Repositories & Signing Keys* (`sections[]=repositories`). A restore onto a different appliance re-runs the install plan for any plugin whose packages are absent, after the NIC-remap step (an overlay bound to a NIC name is remapped like any interface reference). Secret settings obey the §7.6 encrypted-envelope policy: `plugins` and `plugin_grants` are configuration and travel in both archive forms, while only the `is_secret` rows of `plugin_settings` are held back from a plaintext archive.
  > **Status.** `plugin_repos` is implemented end to end: archived in `BackupEngine::createBackup`, reconciled by `Backup::applyRestore()` → `RepoRegistry::apply()`, exercised by `BackupRepositoryPolicyTest` and `BackupRepositoryRestoreTest`. The row's drift observation (`drift_kinds`, `drift_since`, `drift_detail`, `last_check_at`, `last_verified_at`, `metadata_date`, `metadata_seen_at`, `key_created_at`, `key_expires_at`) travels with it, so a restored appliance knows where each repository stood until the first sweep re-observes it; the scheduled sweep itself is a `cron_jobs` row, not archived state. `plugins`, `plugin_grants` and `plugin_settings` are now archived too: the installed-plugin rows and their grants in both archive forms, and the settings split so the `is_secret` rows are written **only** inside the encrypted envelope. A restore writes `plugin_settings` by replacing the rows the archive names rather than truncating the table, so a plaintext archive — which by design carries no secret rows — adds its non-secret settings without deleting the target's own plugin secrets. Covered by `BackupPluginPolicyTest`.
- **Content updates.** Class A connectors continue to ride the content plane (feed refresh), unaffected by plugin transactions.

---

## 14. CLI & RPC Reference

```
php spark micron:plugin list            # the catalogue, with installability
php spark micron:plugin plan    <id>    # the local, daemon-free install plan
php spark micron:plugin install <id>    # same transaction as the UI
php spark micron:plugin remove  <id> [--purge]
php spark micron:plugin status  <id>
php spark micron:plugin refresh         # repository metadata only
php spark micron:plugin repos          # the curated repository registry + compiled plan
php spark micron:plugin repos --apply  # reconcile sources, keys and pins, then apt-get update
php spark micron:plugin repos --refresh
php spark micron:plugin repos --test <id>   # read-only: reachable, signed by the declared key
php spark micron:plugin repos --seed <id>   # register an image-shipped vendor preset (disabled)
php spark micron:repo-drift             # refresh metadata + flag drift (the scheduled sweep)
php spark micron:repo-drift --repo=<id,id>  # limit to named repositories
php spark micron:repo-drift --dry-run   # report stored verdicts; refresh and write nothing
php spark micron:repo-drift --json      # machine-readable report; exit non-zero on a critical finding
php spark micron:plugin catalogue       # show the verified catalogue status
php spark micron:plugin catalogue --sync            # fetch + verify + cache the remote feed
php spark micron:plugin catalogue --file <envelope> # air-gap: verify + cache a local envelope
php spark micron:plugin sign <catalogue.json> <private-key.pem> [--key-id <id>] [--out <path>]
```

`sign` builds the envelope a publisher ships (it never runs on the appliance's request path); `catalogue --file` is the corresponding appliance-side import.

| RPC | Caller | Enforcement |
| :-- | :-- | :-- |
| `plugin.catalog` / `plugin.status` | read paths | RBAC `plugins.view` |
| `plugin.install` / `plugin.remove` / `plugin.upgrade` | operator/CLI | RBAC `plugins.install` + capability consent |
| `plugin.service` | status widget | per-plugin unit allowlist |
| `plugin.settings.apply` | configure page | RBAC `plugins.configure` |
| `plugin.repo.refresh` | operator/schedule | RBAC `plugins.repo.refresh`, metadata only |
| `plugin.repo.apply` / `plugin.repo_status` | Repositories screen, CLI | RBAC `plugins.repo.manage` (write) / `plugins.view` (read) |
| `plugin.repo_test` | Repositories screen, CLI | RBAC `plugins.repo.refresh`; read-only (private keyring, no writes) |
| `plugin.netbird.status` | NetBird plugin page | RBAC `plugins.view`; read-only, refused unless the plugin is installed and its unit is running |
| `plugin.netbird.up` / `plugin.netbird.down` | NetBird plugin page | RBAC `plugins.configure`; fixed argv, `https://` management URL validated before any command runs |
| `plugin.netbird.enroll` | NetBird plugin page | RBAC `plugins.configure`; setup key validated against a closed grammar, staged in a `0600` file, never in argv, never logged, never returned |
| `plugin.zerotier.status` | ZeroTier plugin page | RBAC `plugins.view`; read-only, refused unless the plugin is installed and its unit is running |
| `plugin.zerotier.join` / `plugin.zerotier.leave` | ZeroTier plugin page | RBAC `plugins.configure`; fixed argv, the network id validated as exactly 16 hex characters *before* the readiness probe runs, and a `zerotier-cli` status code other than `200` treated as a failure even under a zero exit status |
| `plugin.nat64.status` | NAT64 plugin page | RBAC `plugins.view`; read-only, refused unless the plugin is installed |
| `plugin.nat64.apply` | NAT64 plugin page | RBAC `plugins.configure`; every prefix/pool validated before a byte is written; DNS64 gated through `unbound-checkconf` with rollback; the protected `unbound` unit is never controlled |
| `plugin.tunnel.status` | IPv6 tunnel plugin page | RBAC `plugins.view`; read-only, never returns the stored update key |
| `plugin.tunnel.apply` | IPv6 tunnel plugin page | RBAC `plugins.configure`; every address validated before the unit is generated; the update key is staged in a `0600` file, never in argv and never returned (an empty value clears it, an absent one keeps it) |
| `plugin.tunnel.update` | IPv6 tunnel plugin page | RBAC `plugins.configure`; contacts the broker's **fixed** update URL (no caller-supplied URL) with this appliance's public IPv4 and the stored key |
| `plugin.darkstat.status` | darkstat plugin page | RBAC `plugins.view`; read-only, refused unless the plugin is installed |
| `plugin.darkstat.apply` | darkstat plugin page | RBAC `plugins.configure`; every value (interface names, port range, local networks, BPF filter, chroot/database/daylog, hosts caps) validated before the generated systemd drop-in is written or the unit is restarted |
| `plugin.lightsquid.status` | lightsquid plugin page | RBAC `plugins.view`; read-only, refused unless the plugin is installed |
| `plugin.lightsquid.apply` | lightsquid plugin page | RBAC `plugins.configure`; every value (report language/template/bar colour, IP resolve method, directories, skip list, schedule) validated against a closed set or an absolute path before the generated config file and `/etc/cron.d/lightsquid` drop-in are written |
| `plugin.lightsquid.refresh` | lightsquid plugin page | RBAC `plugins.configure`; runs the reporter's **fixed** parser — `today` re-parses the current log, `full` the current and every rotated log (bounded at 100) — with the mode validated first |
| `plugin.lldpd.status` | lldpd plugin page | RBAC `plugins.view`; read-only, refused unless the plugin is installed |
| `plugin.lldpd.neighbours` | lldpd plugin page, Interfaces inventory | RBAC `plugins.view`; read-only, runs the **fixed** `lldpcli show neighbors`, bounded in size. `format=text` (default) passes the listing through verbatim; `format=json` runs `-f json0` and returns one structured row per neighbour (local interface, remote chassis name/ID/description, remote port, management address, age, TTL) so the **Interfaces** page can show which switch and port each interface is patched into without parsing the human-readable table. Any other format is refused before `lldpcli` runs |
| `plugin.lldpd.apply` | lldpd plugin page | RBAC `plugins.configure`; every value (interface and management-address patterns, system name, description, platform, transmit timing) validated before the generated systemd drop-in and `/etc/lldpd.conf` are written or the unit is restarted |

---

## 15. Phased Roadmap

**Phase 1 — Class B daemon plugins (low risk, no third-party repo).**
Manifest + registry + capability model; catalogue and install/remove/transaction/rollback; the `plugins` UI and schema-driven config; `plugin.service`. Ship **LLDP, iperf3, darkstat, Lightsquid** — all from Debian `main` or a first-party report bundle, none introducing a third-party repo or kernel module. Proves install → configure → enable → upgrade → remove end to end.

> **Status (engine + daemon implemented).** `App\Plugins\PluginManifest`, `App\Plugins\CapabilitySet`, `App\Plugins\PluginRegistry` (with the four shipped Class B definitions under `app/Plugins/definitions/`), `App\Libraries\PackageManager`, `Config\Services::packageManager()`, and the `micron:plugin` CLI are in place, together with the `plugin.*` RPCs and the protected-core refusals in `micrond`. The `plugins`/`plugin_grants`/`plugin_repos`/`plugin_settings`/`plugin_install_log` tables and the `plugins.*` permissions exist (`2026-10-06-000090_CreatePluginTables`). The **Plugins page** (`App\Controllers\Plugins`, `app/Views/plugins/index.php`, the `plugins/*` routes and the sidebar/top-nav entry) renders the catalogue grid and drives install/remove/upgrade/service/refresh through a capability-consent dialog that shows the install plan and lets the operator approve each grant — persisting the plugin and its grants. Covered by `tests/app/Plugins/*`, `tests/app/Libraries/PackageManagerTest.php`, `tests/app/Controllers/PluginsControllerTest.php` and `tests/test_micrond_plugins.py`. The **curated repository registry** is in place too: `App\Libraries\RepoRegistry`, `App\Controllers\Packages`, `app/Views/plugins/repositories.php`, the first `repos/…` routes, the `plugins.repo.manage` permission and the enriched `plugin_repos` schema (`2026-10-06-000092_EnhancePluginRepos`), together with the `plugin.repo.apply` / `plugin.repo_status` RPCs — the daemon generates every source, preference and keyring file, verifies each signing key's fingerprint, pins the repository below the distribution and pins the protected core to the appliance's own release. A manifest's `requires.repos` is now resolved against it, so a plugin whose repository is absent is blocked rather than half-installed. A per-repository **Test** action (`plugin.repo_test`) verifies reachability, the signature and the declared fingerprint without writing anything, on a private keyring. The registry is also observed **over time**: a scheduled sweep (`micron:repo-drift`, seeded by `2026-10-06-000095_SeedRepoDriftCronJob` with the drift columns added by `2026-10-06-000094_AddRepositoryDriftTracking`) refreshes metadata and flags drift — a vendor that stopped publishing, a `Valid-Until` or signing key nearing expiry, a rotated key, or a source that has gone unreachable — keeping the verdict on the `plugin_repos` row and exiting non-zero on a critical finding. The same sweep re-verifies each repository and flags a **Verification lost** regression when a source that previously verified — tracked by `last_verified_at` (`2026-10-06-000096_AddRepositoryVerificationTracking`) — stops doing so, with the reason surfaced on the row and in the drift panel. Covered by `tests/app/Libraries/RepoRegistryTest.php`, `tests/app/Libraries/RepoDriftTest.php`, `tests/app/Controllers/PackagesControllerTest.php`, `tests/app/Commands/RepoDriftCommandTest.php`, `tests/_support/Libraries/RepoFixture.php` and `tests/test_micrond_plugins.py`. It also has a **per-plugin page** for each plugin that needs one, and darkstat's is now among them: `app/Views/plugins/apps/darkstat.php` renders a settings form (enabled, capture interface(s), web-UI port, local networks, BPF filter, DNS resolution, local-only, chroot/database/daylog, hosts caps) and `plugin.darkstat.apply` writes the validated settings into a systemd drop-in over the package's own `darkstat.service` and restarts it — the unit name stays the package's, so the generic status/control and the daemon widget resolve the same service. The **lightsquid reporter** has its own page too: `app/Views/plugins/apps/lightsquid.php` renders the pfSense-parity settings (report language/template/bar colour, IP resolve method, skip list, Squid log and report directories, refresh schedule) and `plugin.lightsquid.apply` writes the validated values into a generated `lightsquid.conf` plus a `/etc/cron.d/lightsquid` drop-in — no daemon and no listener — while `plugin.lightsquid.refresh` runs the parser on demand (today, or full over the rotated logs). The **lldpd** page carries both halves of pfSense's LLDP screen: `app/Views/plugins/apps/lldpd.php` renders the settings (interfaces, management-address pattern, system name/description, CDP platform, transmit interval and hold, receive-only, the CDP/FDP/SONMP/EDP companion protocols, the SNMP subagent, kernel-version suppression) and the neighbour table (`plugin.lldpd.neighbours` returns `lldpcli show neighbors`, verbatim as `format=text` or as structured rows as `format=json`). The page renders the structured rows as a **sortable table** — one row per neighbour across local port, remote chassis, remote port, management address and TTL, with a TTL compared as a number and a blank left last — and falls back to the raw `lldpcli` listing, with a toggle between the two, whenever a table cannot be built (the daemon refuses, or answers with no neighbours). A neighbour name arrives over the wire, so every cell is rendered as text, never markup. `plugin.lldpd.apply` writes the validated values into a systemd drop-in over the package's own `lldpd.service` plus `/etc/lldpd.conf` for the settings only `lldpcli` can apply at start. The neighbour table is consumed by the **Interfaces page** (`App\Controllers\Interfaces`), which annotates each row and card with the remote chassis and port that interface is patched into — the peer is shown as inventory, only for the devices an interface is bound to, and the page degrades to no peer when lldpd is not installed or the daemon cannot answer. Still to do: the generic schema-driven per-plugin **settings** form, the catalogue view and offline `.mplug`/`.deb` bundle upload, and a live-on-daemon apt transaction (the daemon path is exercised against stubbed `apt-get`/`systemctl`/`gpg`).

**Phase 2 — Kernel-visible interfaces.**
NAT64/DNS64 (Debian `main`'s userspace `tayga` plus Unbound DNS64 synthesis, written beside the protected resolver but never controlling it) and the IPv6 6in4 tunnel broker (a generated `micron-6in4.service` unit carrying the `sit` module) — both **implemented**. Each owns a kernel-visible interface whose forward accept is gated on the plugin's installed state (§11). No third-party repository; no new privilege class.

**Phase 3 — Class C overlays: ZeroTier, NetBird.**
Third-party repos + pinning, `net.raw`/`firewall.ifgroup` capabilities, account enrolment flow, interface remap on restore, and the extra consent step. This is where the Package Manager's repo discipline is really exercised.

> **Status (both Class C overlays implemented).** The whole Class C path exists for `netbird`: the image-shipped repository preset (§5.2) with the vendor's real key and fingerprint, the `netbird` manifest with `requires.repos`, the Class C acknowledgement on the Plugins page, the daemon's repository-presence refusal in `plugin.install`, the `plugin.netbird.status` / `.up` / `.down` / `.enroll` RPCs, and the per-plugin overlay page (`app/Views/plugins/apps/netbird.php`, routes `plugins/netbird/status` and `plugins/netbird/control`). **ZeroTier is implemented the same way**, on top of its own shipped repository: `RepoPresets` carries the vendor key and `2026-10-09-000100_SeedZerotierPresetRepository` lands the preset — `https://download.zerotier.com/debian/trixie`, key `74A5E9C458E1A431F1DA57A71657198823E52A61`, verified live against `dists/trixie/InRelease` — **disabled**, so the manifest never needed an operator-transcribed fingerprint. `app/Plugins/definitions/zerotier/plugin.json` declares `class: "C"`, `requires.repos: ["zerotier"]` and the `zt+` ifgroup; the daemon's catalogue carries the matching `repos: ["zerotier"]`, and `plugin.zerotier.status` / `.join` / `.leave` plus the overlay page (`app/Views/plugins/apps/zerotier.php`) report the node's identity and its memberships and drive join/leave by network id. Unlike NetBird's release-independent `stable`, ZeroTier publishes one distribution *per Debian codename*, so that preset is image-specific (the image is Debian 13 "trixie") and a future image on a newer codename ships an updated one. Covered by `tests/app/Libraries/RepoPresetsTest.php`, `tests/app/Libraries/IpcClientZerotierMockTest.php`, `tests/test_repo_presets.py`, the `zerotier` manifest case in `tests/app/Plugins/PluginManifestTest.php`, the Class C cases in `tests/app/Controllers/PluginsControllerTest.php` and the ZeroTier section of `tests/test_micrond_plugins.py`. Still to do: an interface remap on restore, and a live enrolment against a real controller — the daemon path is exercised against a stubbed `netbird`/`zerotier-cli`/`systemctl`.

**Phase 4 — High-assurance engines.**
Kernel-module/eBPF allowlist, signed out-of-tree module loading, packet-path safety (a failed engine must not wedge forwarding), premium gating, and an explicit operator warning. Only if the module risk can be contained to the appliance's satisfaction.

**Phase 5 — Ecosystem.**
Third-party plugin authoring kit (schema, validator, signing helper), a published catalogue, and a reproducible offline bundle path for air-gapped sites.

---

## 16. Risks & Open Questions

1. **Kernel-path plugins change the risk calculus.** Anything that hooks the packet path needs its own threat model; treat Phase 4 as a separate decision, not an incremental Class C install.
2. **Third-party account provisioning.** ZeroTier/NetBird enrolment creates vendor-side state the appliance cannot fully audit. The consent text must say so, and the UI must never imply the appliance "owns" that identity.
3. **Slot re-install cost.** Replaying the plugin set on every platform update needs the dpkg cache to survive the flip; without it, an update on an air-gapped appliance can strand plugins. Decide whether the data partition carries the package cache.
4. **Permission accretion.** Every plugin adds RBAC keys and menu entries; the catalogue needs a lint that rejects duplicate/conflicting keys before an image is built.
5. **`apt` and A/B interact.** A plugin installed into the live slot and then rejected by a rollback must be reverted with it; the transaction engine must coordinate with the slot flip, not fight it.

---

*Document Version:* 1.0.0 (Plugin & Package Manager Specification)
*Project:* Micron Firewall OS (MicronOS)
*See also:* [Connector Abstraction Specification](MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.html) · [Connector Threat Model](MICRONOS_CONNECTOR_THREAT_MODEL.html) · [Update & Upgrade Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html) · [Architecture Blueprint](MICRON_FIREWALL_OS_ARCHITECTURE.html)

# Micron OS — Semantic Versioning & Release Lifecycle Specification

## Design & Engineering Specification

### Standardized `MAJOR.MINOR.PATCH` Release Channels and Incremental Build Architecture

**Status:** Authoritative Standard  
**Initial Baseline Version:** `0.1.23` (Development Channel)  
**Project Name Standard:** Strictly **"Micron OS"** (with space), not "MicronOS"  
**Applies to:** All Micron OS appliances (Linux Debian 13 Base + Management Plane)  
**Companion Documents:** [Architecture Blueprint](MICRON_FIREWALL_OS_ARCHITECTURE.html) · [Update & Upgrade Specification](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html) · [Advanced Roadmap Specification](MICRONOS_ADVANCED_ROADMAP_SPECIFICATION.html)

---

## 1. Executive Summary & Purpose

This specification governs the software versioning format, release channel determination rules, and incremental patch tracking architecture for **Micron OS**. It defines how developers, automated CI/CD pipelines, package repositories, and the on-appliance Git revision engine generate, display, and increment software version identifiers.

---

## 2. Core Versioning Schema

Micron OS strictly adopts a 3-component semantic versioning structure:

$$\mathbf{MAJOR}.\mathbf{MINOR}.\mathbf{PATCH}$$

*Example: `2.4.56`, `0.1.23`, `2.2.11`*

```
     ┌─────────── MAJOR: Architecture Generation / Milestone
     │   ┌─────── MINOR: Release Channel (Odd = Development, Even = Stable)
     │   │   ┌─── PATCH: Incremental Project Edits / Build Number
     ▼   ▼   ▼
     2 . 4 . 56
```

### 2.1 Major Version (1st Digit)
- Represents core platform generations, major architectural milestones, or breaking kernel/framework migrations.
- Examples: `0.x` (initial development and appliance stabilization), `1.x` (first enterprise baseline), `2.x` (next-generation modular architecture).

### 2.2 Minor Version (2nd Digit) — Release Channel Rule
The second digit strictly dictates the software **stability channel**:

| Minor Digit (2nd Digit) | Channel Type | Description | Illustrative Examples |
| :---: | :---: | :--- | :--- |
| **Odd Number**<br>`1, 3, 5, 7, 9, ...` | **Development Version** | Active engineering branch, nightly builds, experimental features, pre-release testing. | `0.1.23`<br>`1.1.24`<br>`2.3.56` |
| **Even Number**<br>`0, 2, 4, 6, 8, ...` | **Stable Version** | Production-hardened releases, long-term enterprise support (LTS), validated appliance images. | `0.2.0`<br>`2.2.11`<br>`2.4.12` |

- **Development Version Logic:** When the minor digit is odd (e.g. `2.3.x`, `1.1.x`, `0.1.x`), the system identifies itself with the **Development** channel badge. Operators and engineers are notified that active features and experimental modules may be present.
- **Stable Version Logic:** When the minor digit is even (e.g. `2.4.x`, `2.2.x`, `0.2.x`), the system identifies itself with the **Stable** channel badge. These versions have undergone full regression testing and are approved for mission-critical deployments.

### 2.3 Patch Version (3rd Digit) — Incremental Edits
- The third digit represents the **incremental edits, builds, or configuration revisions** performed on the project over time.
- Every development edit, build run, or committed appliance checkpoint increments this digit:
  - `0.1.23` $\rightarrow$ `0.1.24` $\rightarrow$ `0.1.25`
  - `2.4.12` $\rightarrow$ `2.4.13`
  - `2.3.56` $\rightarrow$ `2.3.57`

---

## 3. Project Starting Baseline

For this project, the official starting version is:

$$\mathbf{0.1.23}$$

- **Major (`0`):** Pre-1.0 foundation phase.
- **Minor (`1`):** Odd number $\rightarrow$ **Development Version**.
- **Patch (`23`):** Baseline project edit count.

---

## 4. Release Channel Progression Matrix

The table below illustrates how versions progress across development and stable release branches:

| Version | Minor Value | Channel | Target Environment | Stability State |
| :--- | :---: | :--- | :--- | :--- |
| `0.1.23` | `1` (Odd) | **Development** | Engineering & Testing | Initial Project Starting Version |
| `0.1.24` | `1` (Odd) | **Development** | Engineering & Testing | Incremental edit / revision commit |
| `0.1.25` | `1` (Odd) | **Development** | Engineering & Testing | Incremental edit / revision commit |
| `0.2.0` | `2` (Even) | **Stable** | Production Deployments | Stable milestone release |
| `0.2.1` | `2` (Even) | **Stable** | Production Deployments | Maintenance hotfix / patch |
| `1.1.24` | `1` (Odd) | **Development** | Developer Evaluation | 1.x Development branch |
| `2.2.11` | `2` (Even) | **Stable** | Enterprise Production | 2.2 Stable release |
| `2.3.56` | `3` (Odd) | **Development** | Preview & Lab Staging | 2.3 Development branch |
| `2.4.12` | `4` (Even) | **Stable** | Enterprise Production | 2.4 Stable production release |
| `2.4.56` | `4` (Even) | **Stable** | Enterprise Production | 2.4 Maintenance point release |

---

## 5. Architectural Implementation in Micron OS

### 5.1 Canonical Version Provider (`App\Libraries\AppVersion`)
Version resolution is centralized in `app/Libraries/AppVersion.php`. Hardcoded version strings are prohibited across application views and controllers.

```php
namespace App\Libraries;

final class AppVersion
{
    public const PROJECT_NAME    = 'Micron OS';
    public const DEFAULT_VERSION = '0.1.23';

    public static function getVersion(): string;          // e.g. "0.1.23"
    public static function getMajor(): int;               // 0
    public static function getMinor(): int;               // 1
    public static function getPatch(): int;               // 23
    public static function isDevelopment(): bool;         // true if minor % 2 !== 0
    public static function isStable(): bool;              // true if minor % 2 === 0
    public static function getChannel(): string;          // "Development" or "Stable"
    public static function getChannelBadge(): string;     // Styled HTML badge
    public static function incrementPatch(): string;      // 0.1.23 -> 0.1.24 (persists to VERSION)
    public static function getProjectName(): string;      // Strictly "Micron OS"
    public static function getFullVersionString(): string; // "Micron OS v0.1.23 (Development)"
}
```

### 5.2 Root Persistence File (`VERSION`)
The version number is stored at the repository root in the `VERSION` file:
```text
0.1.23
```
This plain-text standard allows shell scripts, packaging tools, Debian `live-build` recipes, and the PHP management plane to read the version without framework dependencies.

### 5.3 Git-Backed Revision Integration (`ConfigRevisionEngine`)
When an administrator confirms a configuration change or creates a manual checkpoint, the `ConfigRevisionEngine` automatically:
1. Serializes active appliance tables to `config.json`.
2. Invokes `AppVersion::incrementPatch()`, advancing the third digit.
3. Records the Git commit with author attribution and unified diff metadata.
4. Updates the active audit timeline at `/system/revisions`.

### 5.4 User Interface Integration
The version and stability channel appear consistently throughout the web GUI:
1. **Topbar Badge:** Displays `v0.1.23` with a color-coded channel badge (`Development` in amber, `Stable` in emerald) linking directly to Configuration Revisions & Diff.
2. **Dashboard Hero Header:** Renders `Micron OS Security Appliance v0.1.23 (Development, amd64)`.
3. **System Information Card (`/system`):** Displays `Micron OS Version: 0.1.23 (Development)`.
4. **Authentication Portal (`/auth/login`):** Displays `Micron OS v0.1.23 (Development) · Linux Network Firewall Appliance`.
5. **SIEM / Observability Telemetry:** Formats RFC 5424 and ECS audit payloads with `observer.vendor = 'Micron OS'` and `observer.version = '0.1.23'`.

---

## 6. Official Branding & Naming Standard

The project name is strictly:

$$\text{\textbf{Micron OS}}\quad (\text{with a space})$$

The variant "MicronOS" is deprecated. All documentation, page titles, logging envelopes, and user-facing copy adhere to "Micron OS".

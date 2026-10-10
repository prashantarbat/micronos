# Micron Firewall OS (MicronOS)
## Power-Loss-Resilient Router OS — Architecture Review & Recovery Engineering Specification
### Comprehensive Technical Blueprint for Filesystem Durability, Application-Level Consistency, Pre-Boot LKGC Recovery, and Automated Failover

---

## Table of Contents
1. [Executive Summary & Ground-Truth Codebase Audit](#1-executive-summary--ground-truth-codebase-audit)
2. [Tri-Partite Failure Boundary: Filesystem vs. Application vs. System Recoverability](#2-tri-partite-failure-boundary-filesystem-vs-application-vs-system-recoverability)
3. [Filesystem Resilience Evaluation (SquashFS+OverlayFS, ext4, ZFS, Btrfs, F2FS, UFS)](#3-filesystem-resilience-evaluation)
4. [Application-Level Data Durability & SQLite WAL Engineering](#4-application-level-data-durability--sqlite-wal-engineering)
5. [Analysis of Existing SquashFS, OverlayFS & Phase 0 Lifecycle Assets](#5-analysis-of-existing-squashfs-overlayfs--phase-0-lifecycle-assets)
6. [Pre-Boot Keyboard Intercept (Esc/Shift LKGC Recovery Menu in GRUB)](#6-pre-boot-keyboard-intercept-escshift-lkgc-recovery-menu-in-grub)
7. [Hardware Storage Considerations (PLP, eMMC, SATA DOMs, NVMe & Watchdogs)](#7-hardware-storage-considerations)
8. [Proven Industry Architectural Patterns (OpenWrt, ChromeOS, Android, Junos)](#8-proven-industry-architectural-patterns)
9. [End-to-End System Architecture & Boot Flow Diagram](#9-end-to-end-system-architecture--boot-flow-diagram)
10. [Prioritized Implementation Roadmap](#10-prioritized-implementation-roadmap)
11. [Power-Failure Chaos Testing & Verification Suite](#11-power-failure-chaos-testing--verification-suite)
12. [False Assurances & Common Architectural Anti-Patterns](#12-false-assurances--common-architectural-anti-patterns)

---

## 1. Executive Summary & Ground-Truth Codebase Audit

Network security gateways and edge routers in branch offices, industrial plants, and retail locations are routinely subjected to unannounced power cuts, UPS battery exhaustion, and abrupt plug pulls. Operational experience with traditional FreeBSD-based firewalls (pfSense/OPNsense running on UFS) demonstrates that dirty shutdowns frequently cause soft-updates corruption, orphaned inodes, locked state databases, and unbootable filesystems requiring manual `fsck` intervention.

The primary engineering objective of this specification is to make **Micron Firewall OS (MicronOS)** completely resilient against sudden power outages: **a single power interruption or unexpected runtime crash must never permanently disable an appliance while a valid recovery state remains available.**

### 1.1 Ground-Truth Codebase Audit
Before designing modifications, every load-bearing claim regarding MicronOS's filesystem layout, persistence layer, and recovery state was verified directly against the active repository source code:

| Subsystem | Documented Description | Ground-Truth Code Reality in Repository | Vulnerability / Failure Mode under Power Cut |
| :--- | :--- | :--- | :--- |
| **Root Filesystem** | *"SquashFS + OverlayFS"* | **Pure writable ext4** on `/dev/sda3` (`installer/install.sh:133`). | Root filesystem is fully mutable. A power drop during an apt package write, binary swap, or log flush risks ext4 metadata damage or orphaned inodes. |
| **A/B Slot B** | *"Dual A/B active/inactive slots"* | `sda4` is formatted ext4 but **completely empty** (`installer/install.sh:136`). | If Slot A corrupts before a Phase 3 firmware upgrade is flashed, GRUB's fallback mechanism attempts to boot empty Slot B and triggers an unrecoverable kernel panic. |
| **Persistent Data** | `/var/lib/micron` on `sda5` ext4 | Mounted via `/etc/fstab` with `defaults,noatime,nofail` (`installer/install.sh:175`). | Contains critical configuration (`config.db`, `secrets.key`). ext4 with default writeback caching risks 0-byte file truncation on sudden power cutoff. |
| **Configuration DB** | SQLite 3 | `WRITEPATH . 'micron_config.db'`, `synchronous: null` (`app/Config/Database.php:28,36`). | SQLite defaults to rollback journal mode with indeterminate fsync enforcement. Power loss mid-commit risks journal corruption or database locks. |
| **Boot Fallback** | Automated 3-fail healthcheck reboot | `09_micron_slots` boots `micron_target`; `micron-healthcheck` counts to 3 and reboots. | **No pre-boot keyboard intercept (Esc/Shift LKGC menu)**. If both slots fail or slot B is empty, the machine enters a reboot loop or panics. |
| **File I/O in Daemon** | *"Atomic writes"* | Direct `open("w")` on live configs (`/etc/nftables.conf`, `system/micrond/micrond.py:946`); `os.replace()` without `fsync()` on others. | Direct writes truncate live configs to 0 bytes on power failure. `os.replace()` without `fsync()` leaves directory metadata pointing to uncommitted blocks. |

---

## 2. Tri-Partite Failure Boundary: Filesystem vs. Application vs. System Recoverability

A frequent architectural mistake is assuming that filesystem integrity guarantees application consistency, or that application consistency guarantees system recoverability. In a robust router OS, resilience is divided into three distinct, non-overlapping contracts:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                           LEVEL 1: FILESYSTEM INTEGRITY                          │
│  Guarantee: The storage superblock, inode tables, and block allocators are not   │
│             corrupted. The OS can mount the disk without kernel fsck panic.      │
│  Mechanisms: Journaling (ext4), Copy-on-Write (Btrfs/ZFS), Read-Only SquashFS.   │
│  Limitation: Does NOT ensure the data inside a written file is complete or valid.│
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                        LEVEL 2: APPLICATION DATA CONSISTENCY                     │
│  Guarantee: Partially written files never replace valid ones; databases commit   │
│             atomically; config files pass schema validation before activation.   │
│  Mechanisms: Temp-write + fsync + atomic rename; SQLite WAL transactions; parent │
│             directory fsync; JSON schema assertions before daemon reloads.       │
│  Limitation: Does NOT prevent human misconfiguration or bad software updates.    │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                        LEVEL 3: SYSTEM RECOVERABILITY (LKGC)                     │
│  Guarantee: If the active software stack fails to boot or breaks routing/access, │
│             an independent mechanism restores operational state automatically.   │
│  Mechanisms: Pre-boot GRUB LKGC menu, Watchdog auto-reversion, A/B dual slots,   │
│             automated healthcheck fallback, hardware recovery trigger.          │
└──────────────────────────────────────────────────────────────────────────────────┘
```

1. **Level 1: Filesystem Integrity (The Storage Contract)**
   - Protects the underlying disk block structure. A journaled filesystem (ext4) or a Copy-on-Write (CoW) system guarantees that after an unclean shutdown, filesystem metadata is consistent and mountable without hanging in manual `fsck` recovery.
   - *Failure boundary:* Filesystem integrity guarantees that a file exists and has valid inode pointers; it does **not** guarantee that the file contains complete data rather than unwritten zeros.
2. **Level 2: Application Data Consistency (The Software Contract)**
   - Protects the semantic validity of application state (`micron_config.db`, `/etc/nftables.conf`, certificates). It guarantees that a write operation either completely succeeds or is completely discarded.
   - *Failure boundary:* Uses atomic temporary-file staging, strict synchronous flushes, Write-Ahead Logging (WAL), and validation schemas. It guarantees that applications never parse half-written, truncated JSON or corrupt database pages.
3. **Level 3: System Recoverability / LKGC (The Architectural Contract)**
   - Protects appliance availability when operational code or configuration causes a functional failure (e.g. an administrator applies a firewall rule that blocks the management port, or a new kernel panics).
   - *Failure boundary:* Operates completely outside the runtime OS, utilizing A/B boot slots, pre-boot keyboard intercept menus, and hardware watchdog timers.

---

## 3. Filesystem Resilience Evaluation

| Filesystem | Power-Loss Failure Modes | What It Protects Against | RAM & CPU Overhead | Suitability for MicronOS Router Appliance |
| :--- | :--- | :--- | :--- | :--- |
| **SquashFS + OverlayFS** *(Recommended for Root)* | **Zero** on lower layer (bit-immutable). Upper layer risks orphan files if ext4 without sync. | Total immunity against OS root corruption, accidental file deletion, or interrupted system updates. | Extremely Low (< 64 MB RAM, fast decompress). | **Optimal for Root Slots A & B.** Deployed by OpenWrt, ChromeOS, and carrier appliances. |
| **ext4** *(Recommended for Data Layer)* | Delayed allocation write-buffering can cause 0-byte truncation on crash unless `barrier=1,commit=5`. | Journal protects filesystem structure; fast replay on reboot. | Negligible (native Linux standard). | **Optimal for `/var/lib/micron` (Data)** if mounted with `data=ordered,errors=remount-ro`. |
| **ZFS** | Low risk to pools (CoW), but high risk of pool import hangs on unclean shutdown if caches desync. | End-to-end data checksumming, self-healing, native snapshots. | **Prohibitive:** Requires 2–4 GB RAM minimum; massive CPU overhead on 4-core routers. | **Unsuitable.** Overkill for small flash storage; violates low-resource router constraints. |
| **Btrfs** | Metadata tree corruption on sudden write cut (split-brain trees on cheap storage); high write amplification. | CoW snapshots, subvolumes, metadata checksums. | Moderate (150–300 MB RAM). | **Caution.** Prone to unrecoverable read-only remounts on consumer eMMC/SATA DOMs under repeated power cuts. |
| **F2FS** | Checkpoint rollbacks can discard recent writes; requires background garbage collection. | Optimized for raw NAND/eMMC flash flash translation layers (FTL). | Low-to-moderate. | **Viable alternative for Data layer** on pure eMMC hardware, but adds tooling complexity over ext4. |
| **UFS** *(BSD)* | Soft-updates inconsistency; requires slow background fsck on crash; unbootable on dirty journal. | Standard Unix filesystem semantics. | Low. | **Irrelevant.** FreeBSD-only legacy filesystem (pfSense/OPNsense default, primary cause of power-cut crashes). |

---

## 4. Application-Level Data Durability & SQLite WAL Engineering

### 4.1 The Four-Step POSIX Atomic Write Protocol
Direct file writes (`open(path, "w")` or PHP `file_put_contents($path, $data)`) immediately truncate the existing file. If power cuts during the write, the file is reduced to **0 bytes**.

To prevent this, all file modifications in `micrond` and PHP must follow the strict four-step atomic sequence:

```
[Write Data to Tempfile in Same Directory]
                   │
                   ▼
       [f.flush() + os.fsync(fd)]  <── Forces data blocks from OS buffer cache to disk NAND
                   │
                   ▼
     [os.replace(tmp_path, final_path)] <── Atomic directory entry pointer swap
                   │
                   ▼
  [dir_fd = os.open(dir); os.fsync(dir_fd)] <── Commits updated directory inode to physical media
```

```python
import os
import tempfile

def safe_atomic_write(filepath: str, content: str, mode: int = 0o644) -> None:
    """Writes content to filepath using atomic rename and explicit fsync barriers."""
    dir_name = os.path.dirname(filepath)
    os.makedirs(dir_name, exist_ok=True)
    
    # 1. Allocate tempfile in the exact same directory (same filesystem guarantee)
    tmp_fd, tmp_path = tempfile.mkstemp(dir=dir_name, prefix=".tmp_")
    try:
        with os.fdopen(tmp_fd, "w", encoding="utf-8") as f:
            f.write(content)
            # 2. Flush Python user-space buffer and force OS cache to disk
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp_path, mode)
        
        # 3. Atomic replacement of destination path
        os.replace(tmp_path, filepath)
        
        # 4. Fsync parent directory to ensure directory entry metadata is on media
        dir_fd = os.open(dir_name, os.O_RDONLY)
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    except Exception:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
        raise
```

### 4.2 SQLite 3 Database Durability Architecture
MicronOS stores all appliance configuration, user accounts, network interfaces, and firewall rules in `WRITEPATH . 'micron_config.db'`. In default rollback journal mode, sudden power interruption risks corrupted transaction headers.

#### Required SQLite PRAGMA Configuration
In `app/Config/Database.php`, enforce:
```php
public array $default = [
    'database'    => WRITEPATH . 'micron_config.db',
    'DBDriver'    => 'SQLite3',
    'DBPrefix'    => '',
    'DBDebug'     => false,
    'busyTimeout' => 5000,
    'synchronous' => 2, // 2 = FULL synchronous
    // Connection initialization hook:
    // PRAGMA journal_mode = WAL;
    // PRAGMA wal_autocheckpoint = 1000;
];
```

#### Why Write-Ahead Logging (WAL) is Superior for Routers:
1. **Append-Only Write Pattern:** Updates are appended to the `.db-wal` file. Original database pages are never touched during active transactions.
2. **Crash Invariance:** If power drops mid-write, uncommitted frames in `.db-wal` are invalid checksums; SQLite simply ignores them on the next read and rolls back cleanly with zero data corruption.
3. **Zero Read-Write Contention:** In WAL mode, writes never block reads, and packet-filter generation reads never block UI configuration commits.

---

## 5. Analysis of Existing SquashFS, OverlayFS & Phase 0 Lifecycle Assets

### 5.1 Existing Phase 0 Architecture
MicronOS currently ships three lifecycle assets in `installer/`:
1. `installer/micron-slot`: Manages the 1024-byte GRUB environment block (`/boot/grub/grubenv`).
2. `installer/micron-healthcheck`: Post-boot systemd service checking Apache2, PHP-FPM, micrond IPC, packet filtering, and `/var/lib/micron` persistence.
3. `installer/grub.d/09_micron_slots`: Generates GRUB2 boot script with auto slot selection.

### 5.2 Critical Flaw: The Empty Slot B Trap
During initial installation (`installer/install.sh:136`), partition `sda4` (`MICRON_ROOT_B`) is formatted with `mkfs.ext4` but **left completely empty**.

```
[Installation: Slot A Populated, Slot B Formatted Empty]
                         │
                         ▼
             [Unclean Power Loss / Boot Crash]
                         │
                         ▼
        [micron-healthcheck fails 3 times on Slot A]
                         │
                         ▼
          [Healthcheck unmarks Slot A as good]
                         │
                         ▼
     [GRUB 09_micron_slots switches to Slot B (Empty)]
                         │
                         ▼
         💥 KERNEL PANIC: VFS Unable to mount root fs 💥
```

#### Resolution: Pre-Populate Slot B as Golden Factory Root
The installer must rsync the root image into **both** `$P_ROOT_A` and `$P_ROOT_B` during installation. Slot B is marked healthy (`micron_b_ok=1`) as a factory baseline. If Slot A fails on first deployment, the router falls back to a working Slot B instead of crashing.

---

## 6. Pre-Boot Keyboard Intercept (Esc/Shift LKGC Recovery Menu in GRUB)

Currently, GRUB auto-boots `micron_target` after a 3-second delay. If an operator misconfigures network interfaces or a power failure corrupts the database, they have no interactive escape hatch before system services start.

### 6.1 The LKGC (Last Known Good Configuration) Concept
An operator standing in front of the console or connected via serial IPMI/COM must be able to press **`Esc`** or hold **`Shift`** to display a dedicated recovery menu.

### 6.2 Implementation in `installer/grub.d/09_micron_slots`
We update the GRUB template generator to include keyboard interception and recovery boot entries:

```sh
cat << 'EOF'
# Pre-Boot Escape Intercept & Timeout Configuration
set timeout=3
set timeout_style=menu

# Check for Esc or Shift keypress to trigger emergency recovery
if keystatus --escape || keystatus --shift ; then
    set default="micronos-recovery"
fi

menuentry "MicronOS (Production - Active Slot)" --id micronos-auto {
    micron_boot $micron_target
}

menuentry "MicronOS (Last Known Good Configuration - Alternate Slot)" --id micronos-lkgc {
    if [ "$micron_target" = "a" ]; then
        micron_boot b
    else
        micron_boot a
    fi
}

menuentry "MicronOS (Factory Recovery - Pristine Boot)" --id micronos-factory {
    # Boots active slot with overlay bypass, resetting configuration to factory defaults
    if [ "$micron_target" = "b" ]; then
        search --no-floppy --fs-uuid --set=root $UUID_B
        linux $KERNEL root=UUID=$UUID_B ro micron_factory_reset=1 quiet
        initrd $INITRD
    else
        search --no-floppy --fs-uuid --set=root $UUID_A
        linux $KERNEL root=UUID=$UUID_A ro micron_factory_reset=1 quiet
        initrd $INITRD
    fi
}

menuentry "MicronOS (Failsafe Single-User Maintenance Shell)" --id micronos-recovery {
    search --no-floppy --fs-uuid --set=root $UUID_A
    linux $KERNEL root=UUID=$UUID_A ro single init=/bin/bash
    initrd $INITRD
}
EOF
```

---

## 7. Hardware Storage Considerations

Software durability is bounded by storage controller physics:

1. **Power-Loss Protection (PLP) Storage:**
   Enterprise SSDs feature hardware capacitors that provide 20–50 ms of autonomous power after a host power drop, enabling the controller to drain all internal volatile DRAM write buffers into flash cells.
2. **Consumer SSDs, SD Cards & eMMC Vulnerabilities:**
   Consumer drives lack capacitors. An unannounced power cut during an internal flash page compaction or wear-leveling cycle can corrupt the drive's internal **Flash Translation Layer (FTL)** mapping table, causing the storage drive to brick permanently at the hardware controller level.
3. **Kernel Barrier Enforcement:**
   On systems without PLP, `/etc/fstab` must mount `/var/lib/micron` with:
   ```
   UUID=<data-uuid> /var/lib/micron ext4 defaults,noatime,barrier=1,commit=5,errors=remount-ro 0 2
   ```
   - `barrier=1`: Forces hardware write-cache barrier flushes before journal commits are marked complete.
   - `commit=5`: Flushes journal entries every 5 seconds, capping unwritten data loss.

---

## 8. Proven Industry Architectural Patterns

| Platform | Architectural Resilience Model | Key Lesson for MicronOS |
| :--- | :--- | :--- |
| **OpenWrt** | Read-Only SquashFS base + JFFS2/ext4 OverlayFS. Pre-boot UDP/Keyboard Failsafe listener. | **Failsafe mode via keypress;** overlay can be completely wiped (`firstboot`) without breaking the underlying OS. |
| **ChromeOS** | Dual A/B partitions (`KERN-A`/`ROOT-A`, `KERN-B`/`ROOT-B`). Cryptographically verified with `dm-verity`. State stored on encrypted stateful partition. | **Read-only root + authenticated A/B slots.** Upgrades are fully staged before boot flag flips. |
| **Android** | `boot_a`/`system_a` and `boot_b`/`system_b`. Boot control HAL manages slot metadata and rollbacks. | **Automated retry counter** decrements on boot; falls back to alternate slot if health probes fail. |
| **OSTree / Silverblue** | Git-like content-addressed object store for the operating system. Atomic symlink deployment switching. | **Atomic symlink swaps** for management-plane deployments (Plane B updates in MicronOS). |
| **Juniper Junos** | FreeBSD base with primary and backup boot partitions. `request system configuration rescue save` command for LKGC rollback. | **Preserved rescue configuration file** stored in read-only flash, loaded on demand via boot menu. |

---

## 9. End-to-End System Architecture & Boot Flow Diagram

```
                                  [ POWER APPLIED / HARD REBOOT ]
                                                 │
                                                 ▼
                                     ┌───────────────────────┐
                                     │  UEFI Firmware (OVMF) │
                                     └───────────┬───────────┘
                                                 │
                                                 ▼
                                     ┌───────────────────────┐
                                     │     GRUB2 (ESP p1)    │
                                     │   reads grubenv block │
                                     └───────────┬───────────┘
                                                 │
                                ┌────────────────┴────────────────┐
                       [Esc/Shift pressed?]              [No Key (Auto)]
                                │                                 │
                                ▼                                 ▼
                 ┌─────────────────────────────┐    ┌───────────────────────────┐
                 │    EMERGENCY RECOVERY MENU  │    │      SLOT ARBITRATION     │
                 │ 1. Boot Active Slot (Slot A)│    │ • micron_target = active  │
                 │ 2. Boot Inactive Slot (B)   │    │ • if !active_ok && other_ok│
                 │ 3. Restore LKGC Config      │    │     micron_target = other │
                 │ 4. Factory Reset (Wipe Data)│    └─────────────┬─────────────┘
                 └──────────────┬──────────────┘                  │
                                └────────────────┬────────────────┘
                                                 │
                                                 ▼
                        ┌─────────────────────────────────────────────────┐
                        │     KERNEL BOOT: root=UUID=<Slot A or B> (ro)   │
                        │     Mounts Root Filesystem as READ-ONLY         │
                        └────────────────────────┬────────────────────────┘
                                                 │
                                                 ▼
                        ┌─────────────────────────────────────────────────┐
                        │       EARLY SYSTEMD / INIT MOUNT ENGINE         │
                        │  1. fsck -a on DATA partition (sda5)            │
                        │  2. Mount /var/lib/micron (ext4, barrier=1)     │
                        │  3. Mount OverlayFS (Lower=Root, Upper=Data/ovl)│
                        └────────────────────────┬────────────────────────┘
                                                 │
                                                 ▼
                        ┌─────────────────────────────────────────────────┐
                        │              APPLIANCE SERVICES UP              │
                        │  • Apache2 + PHP 8.4 FPM                        │
                        │  • micrond IPC Daemon (/run/micron/micrond.sock)│
                        │  • nftables Packet Filter Engine                │
                        └────────────────────────┬────────────────────────┘
                                                 │
                                                 ▼
                        ┌─────────────────────────────────────────────────┐
                        │          micron-healthcheck.service             │
                        │  Probes: Web, IPC, Dataplane, Persistence       │
                        └────────┬───────────────────────────────┬────────┘
                                 │ Pass                          │ Fail
                                 ▼                               ▼
                      ┌──────────────────────┐       ┌───────────────────────┐
                      │ micron-slot mark-ok  │       │ micron-slot mark-fail │
                      │ Slot verified healthy│       │ attempts_left--       │
                      │ Starts Watchdog Ping │       │ If 0: failover & reboot│
                      └──────────────────────┘       └───────────────────────┘
```

---

## 10. Prioritized Implementation Roadmap

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│                       PRIORITIZED IMPLEMENTATION MATRIX                               │
├────┬─────────────────────────────┬───────────┬──────────────┬────────────┬─────────────┤
│Tier│ Action Item                 │ Risk Mit. │ Reliability  │ Complexity │ RAM/CPU Cost│
├────┼─────────────────────────────┼───────────┼──────────────┼────────────┼─────────────┤
│ P0 │ SQLite WAL + Full Sync      │ CRITICAL  │ Very High    │ Very Low   │ Negligible  │
│ P0 │ micrond Atomic Write/Sync   │ CRITICAL  │ Very High    │ Low        │ Negligible  │
│ P0 │ Populate Slot B at Install  │ CRITICAL  │ Very High    │ Low        │ 0 overhead  │
│ P1 │ GRUB Esc-Key LKGC Boot Menu │ HIGH      │ Very High    │ Medium     │ 0 overhead  │
│ P1 │ Ext4 Mount Barriers (data)  │ HIGH      │ High         │ Very Low   │ Negligible  │
│ P2 │ Read-Only Root + OverlayFS  │ HIGH      │ Maximum      │ Medium     │ < 30 MB RAM │
│ P3 │ Hardware Watchdog Tie-in    │ MEDIUM    │ High         │ Medium     │ Negligible  │
└────┴─────────────────────────────┴───────────┴──────────────┴────────────┴─────────────┘
```

---

## 11. Power-Failure Chaos Testing & Verification Suite

To validate compliance against real-world power cuts, the following automated test suite must be executed:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                      POWER FAILURE TEST EXECUTION SUITE                          │
├──────────────────────────────────────────────────────────────────────────────────┤
│ TEST 1: Sudden Cut During SQLite Database Commit                                 │
│  Procedure: Run loop inserting 5,000 firewall rules into micron_config.db; cut   │
│             VM power abruptly via `Stop-VM -Name MicronOS-Dev -TurnOff`.         │
│  Pass Criteria: DB mounts cleanly; sqlite3 integrity_check returns 'ok'; no lost │
│                 committed transactions; uncommitted transaction cleanly reverted.│
├──────────────────────────────────────────────────────────────────────────────────┤
│ TEST 2: Power Cut During Core Config Atomic Replacement                          │
│  Procedure: Trigger continuous nftables compile/rewrite cycle; cut power mid-run.│
│  Pass Criteria: On boot, /etc/nftables.conf is NEVER 0 bytes; it contains either │
│                 the valid new ruleset or valid previous ruleset; nftables passes.│
├──────────────────────────────────────────────────────────────────────────────────┤
│ TEST 3: Interrupted Firmware Update (A/B Write Failure)                          │
│  Procedure: Initiate update into Slot B; cut power at 50% image flash.           │
│  Pass Criteria: Slot A boots normally; grubenv retains Slot A as verified good;  │
│                 corrupted Slot B is never marked ok; appliance remains online.   │
├──────────────────────────────────────────────────────────────────────────────────┤
│ TEST 4: Forced Slot A Crash Loop (Automated Healthcheck Failover)                │
│  Procedure: Corrupt /usr/local/bin/micrond on Slot A; power cycle VM.            │
│  Pass Criteria: Healthcheck fails 3 times; increments fail counter; automatically│
│                 switches active_slot to B; boots cleanly into Slot B.            │
├──────────────────────────────────────────────────────────────────────────────────┤
│ TEST 5: Pre-Boot Keyboard Emergency Recovery (Esc Key Intercept)                 │
│  Procedure: Reboot VM; send Escape keypress during 3-second GRUB window.         │
│  Pass Criteria: Drops into LKGC menu; operator can select Factory / Safe Boot;   │
│                 bypasses corrupted runtime configuration completely.             │
└──────────────────────────────────────────────────────────────────────────────────┘
```

---

## 12. False Assurances & Common Architectural Anti-Patterns

1. **"ext4 Journaling Guarantees File Data Is Protected" (FALSE):**
   Standard ext4 journaling (`data=ordered`) only journals **metadata** (inodes, allocation bitmasks), not data blocks. An unclean shutdown after file creation frequently leaves valid inode metadata pointing to empty zero-byte data blocks on disk.
2. **"Having A/B Partition Entries in GRUB Prevents Bricking" (FALSE):**
   If Slot B is formatted but left unpopulated during initial installation, fallback logic triggers a fatal kernel panic (`VFS: Unable to mount root fs`). Both slots must carry bootable images from day one.
3. **"`os.replace()` / `rename()` is Atomic and Prevents Corruption" (FALSE):**
   `rename()` is atomic only in RAM and directory metadata. Without `fsync(file_fd)` before rename and `fsync(dir_fd)` after rename, the directory entry will be committed while file content blocks remain in unwritten volatile cache.
4. **"ZFS is Mandatory for Self-Healing Storage" (FALSE on Routers):**
   ZFS requires significant memory (1–4 GB RAM minimum) and high CPU overhead, making it impractical for low-power edge router hardware (e.g. Intel Atom, N100, Celeron appliances). A combination of SquashFS + ext4 with barriers provides equivalent resilience with under 30 MB RAM overhead.
5. **"Snapshots Prevent Configuration Loss" (FALSE):**
   Rolling back a full disk snapshot discards legitimate runtime state, including DHCP lease tables, IPsec security associations, traffic counters, and audit log entries. Persistent runtime data must remain decoupled from static configuration state.

<p align="center">
  <h1 align="center">MICRON OS (Micron Firewall OS)</h1>
  <p align="center"><strong>A Modern, Resilient, Debian-Based Linux Network Security Appliance & Next-Gen Routing Platform</strong></p>
  <p align="center">
    <a href="https://debian.org"><img src="https://img.shields.io/badge/Base-Debian_13_Trixie-A81D33.svg?logo=debian&logoColor=white" alt="Debian Base"></a>
    <a href="https://netfilter.org/projects/nftables/"><img src="https://img.shields.io/badge/Firewall-Linux_nftables-FFA500.svg" alt="nftables"></a>
    <a href="https://frrouting.org"><img src="https://img.shields.io/badge/Routing-FRRouting_(BGP%2FOSPF)-1E90FF.svg" alt="FRR"></a>
    <a href="https://www.wireguard.com"><img src="https://img.shields.io/badge/VPN-WireGuard_%26_IPsec-88171A.svg?logo=wireguard&logoColor=white" alt="WireGuard"></a>
    <a href="https://codeigniter.com"><img src="https://img.shields.io/badge/Control_Plane-PHP_8.4_%7C_CI4-EF4423.svg?logo=codeigniter&logoColor=white" alt="CodeIgniter"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/License-Apache_2.0-blue.svg" alt="License"></a>
  </p>
</p>

---

## 📖 A Note From The Creator: Why Micron OS Exists

> *"Sometimes the best tools aren't born in Silicon Valley boardrooms or venture-funded labs. They are born out of sheer frustration, real-world battle scars, and the desperate need to solve a problem that keeps an engineer awake on Sunday night."*

Hello world, I am **Prashant Arbat**, writing to you from **India**.

Before you look at the code, let me be completely frank and humble with you: **I am not a professional software developer.** 

By trade, I am a **Network and Information (Cyber) Security GRC Professional**. As of 2026, I have spent over **22 years** in the trenches of IT and network infrastructure. Over those two decades, I have physically racked, cabled, configured, and troubleshot thousands of enterprise routers, switches, and firewalls across corporate data centers, remote branches, factories, and rural outposts.

The only programming language I ever managed to learn and master is **PHP with an MVC framework (CodeIgniter)**. Throughout my entire career, simple scripts and lightweight MVC apps have saved my job, my time, and my sanity in hundreds of critical operational crises.

---

### The Pain That Started It All

Last two years, we managed branch office networks with **VyOS**. Later, we replaced several branch appliances with **pfSense** to give non-CLI administrators an accessible web GUI. 

That is when reality hit hard.

In India and many developing regions, power infrastructure outside Tier-2 & 3 cities/locations centers is brutal. Sudden power cuts, voltage fluctuations, brownouts, and brief blackouts happen regularly—often at remote branch locations operating without expensive UPS batteries or generator failover.

Under these real-world conditions, we discovered a painful truth: **FreeBSD repeatedly struggled with sudden, violent power loss.** Month after month, across nearly three years:
* 3 to 4 remote branch firewalls would get corrupted every single month.
* Root filesystems became read-only or dropped into single-user recovery prompts.
* Background `php-fpm` and web services silently broke or lost state configurations.
* My weekends were spent driving out to branch offices or guiding remote junior techs to re-flash USB drives and rebuild firewall appliances from scratch.

I reached a point of complete exhaustion and frustration.

### The Missing Piece in Open Source

I started searching for alternatives across the open-source landscape:
* **pfSense & OPNsense:** Brilliant, mature projects with great communities—but both are built on **FreeBSD**, inheriting the same filesystem and driver behavior under hard power cuts on commodity hardware.
* **IPFire:** A Linux-based firewall, but its ecosystem and modernization pace felt detached from current kernel advancements (its latest release was Update 204 from October 14).
* **VyOS:** An absolute tank under power cuts because it is built on **Debian GNU/Linux**. But VyOS lacked a native, modern, responsive web GUI out of the box and had shifted away from free pre-built rolling ISOs for production appliances.

I asked myself a simple question:

> **Why is there no modern, actively maintained, Debian-based Linux firewall OS with a beautiful Web GUI, zero paywalls, and true enterprise resilience?**

Debian's Linux kernel with journaled `ext4` and modern overlays simply shrugs off sudden power cuts. When power cuts, Linux reboots cleanly. No filesystem panic. No corrupted web daemons. It just works.

### The 60% / 40% Collaboration

Because I am a network security engineer and not a full-time software architect, building an entire operating system from scratch felt impossible for years.

Then came the emergence of modern generative AI (2024–2026). For the first time, I had a tireless pair-programmer by my side.

I want to be 100% transparent with the open-source community about how MicronOS was created:
* **60% Human Domain Expertise (Me):** 22 years of networking architecture, firewall packet-flow design, RFC compliance, routing topologies, security policies, hardware testing in live labs, and battle-testing against power cuts.
* **40% AI Pair-Programming:** Helping translate high-level network contracts into low-level Python 3 system IPC daemons, compiling complex `nftables` syntax checkers, scaffolding unit tests, and writing automated Debian `live-build` installer hooks.

Together, we turned 22 years of real-world pain into **Micron OS**.

### The Wonder of Debian Linux

On this journey, I fell in love with modern Debian all over again. What Linux offers today is nothing short of breathtaking:
* **Linux `nftables`:** Sub-millisecond atomic ruleset evaluation, connection tracking, and zero packet overhead.
* **FRRouting (FRR):** True carrier-grade dynamic routing (BGP, OSPFv2, OSPFv3, BFD, PIM multicast).
* **WireGuard & strongSwan IPsec:** Native in-kernel cryptography that saturates gigabit uplinks effortlessly.
* **Zero Trust Network Access (ZTNA):** Clientless HTML5 browser gateway powered by Apache Guacamole and Nginx reverse proxy—no local VPN clients needed to securely access remote RDP/SSH desktops.
* **Dual-Plane Architecture:** A decoupled PHP 8.4 Web Control Plane that talks to a hardened Python 3 IPC daemon (`micrond`) over local UNIX domain sockets (`/run/micron/micrond.sock`).
* **Power-Loss Immune:** A/B dual root partitioning, transactional SQLite state with WAL mode, and atomic configuration generation.

### UI & Operational Design Inspiration

To craft the management interface of Micron OS, I took immense inspiration from the proven structures, visual workflows, and ergonomics of three firewall giants that I have configured for decades:
* **pfSense & OPNsense:** For their battle-tested, modular navigation, interface assignment flows, live diagnostics, and physical console menu philosophy.
* **FortiGate (FortiOS):** For its elegant, object-oriented firewall policy structures, interactive address/service pickers, intuitive NAT mappings, and enterprise policy clarity.

I combined these familiar paradigms with modern responsive web design, crisp typography, and instant client-side search to create an interface that feels instantly natural to any network engineer while delivering the raw power of the Debian Linux kernel.

There is no limit to what this project can become.

Thank you from the bottom of my heart for reading this and trying Micron OS. I hope it saves your weekends and your sleep the way it has saved mine.

**— Prashant Arbat**  
*Email:* [prashant.arbat@gmail.com](mailto:prashant.arbat@gmail.com)  
*India*

---

## ⚡ Key Highlights & Architecture

```
                          ┌──────────────────────────────────────┐
                          │     Web GUI (PHP 8.4 + CI4)          │
                          │   Console CLI (`microctl` Python)    │
                          │   Interactive Console Menu (Bash)    │
                          └──────────────────┬───────────────────┘
                                             │ JSON-RPC (UNIX Socket)
                                             ▼
                          ┌──────────────────────────────────────┐
                          │      micrond (System IPC Daemon)     │
                          │        Stateful Python Engine        │
                          └──────────────────┬───────────────────┘
                                             │ Transactional Apply
                 ┌───────────────────────────┼───────────────────────────┐
                 ▼                           ▼                           ▼
      ┌─────────────────────┐     ┌─────────────────────┐     ┌─────────────────────┐
      │  Linux Data Plane   │     │   Routing & VPN     │     │  Services & Edge    │
      │  • nftables stateful│     │  • FRRouting (BGP)  │     │  • Unbound DNSBL    │
      │  • pyroute2 links   │     │  • WireGuard tunnel │     │  • Kea DHCPv4/v6    │
      │  • A/B dual root    │     │  • strongSwan IPsec │     │  • ZTNA (Guacamole) │
      └─────────────────────┘     └─────────────────────┘     └─────────────────────┘
```

1. **Rock-Solid Debian 13 (Trixie) Foundation:**  
   Immune to sudden power cuts and hard reboot corruption. Uses transactional writes and SQLite WAL journaling.
2. **Native `nftables` Firewall & NAT:**  
   Clean, modern packet filtering, stateful connection tracking, port forwarding (DNAT), outbound masquerade (SNAT), and rate limiting.
3. **Enterprise Dynamic Routing (FRR):**  
   Full BGP, OSPF, and dynamic multi-WAN failover with gateway latency/jitter SLA tracking.
4. **ZTNA Clientless Gateway:**  
   Built-in clientless remote access gateway using Apache Guacamole, allowing secure RDP, SSH, and VNC browser sessions without open WAN ports.
5. **Modern VPN Suite:**  
   Kernel-native WireGuard tunnels (site-to-site and road-warrior) + enterprise IPsec IKEv2 (strongSwan) + OpenVPN.
6. **DNSBL Ad & Threat Shield:**  
   Unbound validating DNS resolver with integrated domain and IP blocklists.
7. **Dual-Root A/B Partitioning:**  
   Enterprise dual-slot updates (`MICRON_ROOT_A` / `MICRON_ROOT_B`) with automated health-check watchdog rollback.
8. **pfSense-Style Console Menu:**  
   Interactive Ncurses / Bash physical console menu on `tty1` for instant interface assignment, IP reconfiguration, ping checks, and factory reset.
9. **CVEs Security Advisories & Vulnerability Scanner:**  
   Authenticated root-level appliance scanner auditing installed MicronOS and Debian packages against the NIST National Vulnerability Database (NVD) and Debian Security Bug Tracker, with one-click NIST NVD reference verification and automated remediation guidance.

---

## 📦 Download & Install

1. Download `micronos-<version>-amd64.hybrid.iso` and its checksum file from **[micronos.microninfo.com/download](https://micronos.microninfo.com/download)** (free account required) and verify the checksum. ISO images are published only there, not on GitHub Releases.
2. Write the ISO to a USB drive (or attach it to a VM) and boot it.
3. Follow the five-step installer. **Set your own administrator password** at step 4.
4. Browse to the LAN address shown on the console and sign in as `Ministrator`.

Minimum hardware: 64-bit x86 CPU, 2 GB RAM, 32 GB disk, two NICs. Full walk-through: [`docs/INSTALL.md`](docs/INSTALL.md).

> **Install check-in:** once at boot and then daily, each appliance reports its Micron Appliance ID, hostname, version, uptime, boot slot, active rule count and basic hardware to micronos.microninfo.com, which records the public IP and its country and city. No rules, traffic, logs, users or keys. It is on by default; turn it off in **System > Networking** or with `php spark micron:checkin --disable`. Details: [`docs/INSTALL_CHECKIN.md`](docs/INSTALL_CHECKIN.md).

> **High availability:** VRRP failover, connection-state sync and encrypted configuration sync to the peer. See the [release notes](docs/RELEASE_NOTES_1.0.0.md#high-availability).

---

## 🧭 Editions & Support

Micron OS has **one edition**: the open-source **Community Edition**, licensed under Apache 2.0 with every feature included and free forever.

Organisations that need more than community help can buy **services** around the same software: Standard and Enterprise Support (with SLAs and a long-term-support update channel), a Managed Firewall Service, Professional Services for design and migration, training, and certified hardware. Paid services never unlock extra features. See [`SUPPORT.md`](SUPPORT.md).

| | Community Edition | With a support subscription |
| :--- | :--- | :--- |
| All features, ISO and updates | ✅ | ✅ |
| Security fixes | ✅ Latest release | ✅ Latest release + LTS backports |
| Help | GitHub Discussions & Issues (best-effort) | Private channel with response-time SLA |
| Update channel | `stable` | `stable` or `lts` |

---

## 🔐 Security

* Harden every deployment with [`docs/SECURITY_HARDENING.md`](docs/SECURITY_HARDENING.md).
* Found a vulnerability? Please **do not** open a public issue. Report it privately as described in [`SECURITY.md`](SECURITY.md).

---

## 🚀 Quick Verification & Development

To test the control plane, database migrations, and RPC verification locally:

```bash
# 1. Install dependencies and create a local configuration
composer install
cp env .env
php spark key:generate

# 2. Initialize configuration database
php spark migrate --all
php spark db:seed InitialApplianceSeeder
php spark db:seed RolesPermissionsSeeder

# 3. Run automated test suites
php vendor/bin/phpunit --no-coverage
for t in tests/test_*.py; do python3 "$t" || echo "FAILED: $t"; done
bash tests/installer/web_and_boot_deconflict_test.sh

# 4. Verify RPC method sync between Web UI and micrond daemon
python3 tools/rpc_inventory.py
```

On a live appliance, run the diagnostic doctor at any time:
```bash
microctl doctor
```

---

## 🛠️ ISO Build & Appliance Installation

Micron OS builds as a hybrid BIOS / UEFI bootable ISO using standard Debian `live-build`:

```bash
# Navigate to packaging directory
cd packaging/live-build

# Stage application overlay into the chroot tree
bash stage-overlay.sh

# Build the hybrid bootable ISO image
sudo lb clean --purge
sudo lb config
sudo lb build
```

The resulting ISO can be burned to a USB drive or mounted directly in Proxmox, VMware ESXi, Hyper-V, or bare-metal x86_64 hardware. An interactive terminal installer partitions the disk, configures A/B boot slots, and deploys the appliance in under 2 minutes.

---

## 📚 Documentation Hub

Complete technical blueprints, API contracts, and implementation guides are available directly inside the appliance web interface under **Admin → Documentation**, or in the [`docs/`](docs/) directory:

* 📄 **Installation Guide:** [`docs/INSTALL.md`](docs/INSTALL.md)
* 📄 **Security Hardening Guide:** [`docs/SECURITY_HARDENING.md`](docs/SECURITY_HARDENING.md)
* 📄 **Release Notes:** [`docs/RELEASE_NOTES_1.0.0.md`](docs/RELEASE_NOTES_1.0.0.md) · **Changelog:** [`CHANGELOG.md`](CHANGELOG.md)
* 📄 **Module Reference:** [`docs/MICRONOS_MODULE_REFERENCE.md`](docs/MICRONOS_MODULE_REFERENCE.md)
* 📄 **System Architecture Specification:** [`docs/MICRON_FIREWALL_OS_ARCHITECTURE.md`](docs/MICRON_FIREWALL_OS_ARCHITECTURE.md)
* 📄 **Appliance ISO Creation & Installer Guide:** [`docs/ISO_CREATION_AND_INSTALLER_GUIDE.md`](docs/ISO_CREATION_AND_INSTALLER_GUIDE.md)
* 📄 **Daemon & RPC Interface Contract:** [`docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md`](docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md)
* 📄 **Implementation Roadmap & Execution Plan:** [`docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md`](docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md)
* 🌐 **Offline Browser Launchpad:** [`docs/index.html`](docs/index.html)

---

## 🤝 Contributing & Community

Micron OS is an open-source project created for network engineers, system administrators, homelab enthusiasts, and small businesses everywhere. 

Whether you are a Linux kernel hacker, a frontend designer, a documentation writer, or a network engineer testing on different hardware NICs: **you are warmly welcome here.**

* **Found a bug?** Open an issue on GitHub using the bug report template.
* **Have an improvement?** Read [`CONTRIBUTING.md`](CONTRIBUTING.md), then open a pull request. Commits must be signed off (`git commit -s`, Developer Certificate of Origin).
* **Security issue?** Follow [`SECURITY.md`](SECURITY.md). Never use a public issue.
* **Questions or feedback?** Reach out directly to [prashant.arbat@gmail.com](mailto:prashant.arbat@gmail.com).

Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

---

## 📄 License

Micron OS is licensed under the **[Apache License, Version 2.0](LICENSE)**. You are free to use, modify, distribute and deploy it for personal, commercial or enterprise use, with an explicit patent grant. Redistributions must keep the [`LICENSE`](LICENSE) and [`NOTICE`](NOTICE) files.

Copyright 2026 Prashant Arbat and the Micron OS contributors.

### Third-party software

Bundled third-party components and their licences are listed in [`THIRD_PARTY_LICENSES.md`](THIRD_PARTY_LICENSES.md).

### Source code for the ISO image

The appliance ISO combines Micron OS with unmodified Debian GNU/Linux packages (the Linux kernel, FRRouting, nftables, strongSwan, nginx, Apache httpd, PHP, Python, guacd and others). Each keeps its own licence, some of which are the GNU GPL. The exact package list for each release is published beside the ISO as `packages.list`, and the corresponding source code for every package is available from the Debian archive at <https://snapshot.debian.org/> for the build date shown in the release notes, or on request to the maintainer for three years after the release.

### Trademarks

"Micron OS" is the name of this project. The Apache License does not grant permission to use the name or logo (section 6). If you distribute a modified version, please give it a different name so users are not confused about its origin.

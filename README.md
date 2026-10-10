# Micron Firewall OS (MicronOS)
## Enterprise Linux Network Security Appliance & Routing Platform

---

### Documentation Hub

The guides are rendered **inside the management console** as PHP views — Admin → Documentation, or directly:

| Guide | In-appliance URL | Source |
| :--- | :--- | :--- |
| System Architecture Specification | `/docs` (or `/docs/architecture`) | [`docs/MICRON_FIREWALL_OS_ARCHITECTURE.md`](docs/MICRON_FIREWALL_OS_ARCHITECTURE.md) |
| Step-by-Step Implementation Plan | `/docs/plan` | [`docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md`](docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md) |
| Appliance ISO Creation & Installer Guide | `/docs/iso` | [`docs/ISO_CREATION_AND_INSTALLER_GUIDE.md`](docs/ISO_CREATION_AND_INSTALLER_GUIDE.md) |

Each page shows a filterable table of contents beside one card per section, so the guides read like an operator manual. The Markdown files stay the single source of truth; the Markdown and standalone HTML portals listed below remain for offline reading and hand-off.

All detailed technical documentation, engineering blueprints, implementation execution plans, database DDL schemas, and ISO creation guides are located in the [`docs/`](docs/) directory:

1. **System Architecture Specification:**
   - 📄 Markdown: [`docs/MICRON_FIREWALL_OS_ARCHITECTURE.md`](docs/MICRON_FIREWALL_OS_ARCHITECTURE.md)
   - 🌐 Interactive HTML Portal: [`docs/MICRON_FIREWALL_OS_ARCHITECTURE.html`](docs/MICRON_FIREWALL_OS_ARCHITECTURE.html)
   - *Covers: Linux Kernel Data Plane, CI4 Control Plane, SQLite & MySQL Dual Strategy, 2FA/LDAP, OpenVPN & WireGuard Road Warrior 2FA, Squid TPROXY Web Filtering, Unbound DNS Resolver (DNSBL plus local host & domain overrides), FRRouting (BGP/OSPF), Keepalived + conntrackd HA, and Encrypted Backup/Restore Engine.*

2. **Step-by-Step Implementation Execution Plan:**
   - 📄 Markdown: [`docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md`](docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md)
   - 🌐 Interactive HTML Portal: [`docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.html`](docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.html)
   - *Covers: Local Laragon/Windows workspace setup, CodeIgniter 4 scaffolding, SQLite migrations, JSON-RPC IPC bridge mocking, nftables compiler, Squid proxy, and virtual lab verification.*

3. **Appliance ISO Creation & Installer Engineering Guide:**
   - 📄 Markdown: [`docs/ISO_CREATION_AND_INSTALLER_GUIDE.md`](docs/ISO_CREATION_AND_INSTALLER_GUIDE.md)
   - 🌐 Interactive HTML Portal: [`docs/ISO_CREATION_AND_INSTALLER_GUIDE.html`](docs/ISO_CREATION_AND_INSTALLER_GUIDE.html)
   - *Covers: Hybrid BIOS/UEFI ISO compilation via Debian `live-build`, Ncurses Dialog interactive installer, A/B dual partitioning, first-boot NIC auto-detection, and automated QEMU/Proxmox deployment.*

4. **Documentation Hub Launchpad:**
   - 🌐 Browser Launchpad: [`docs/index.html`](docs/index.html)

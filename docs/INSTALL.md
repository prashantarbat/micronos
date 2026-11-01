# Installing Micron OS

This guide takes you from a downloaded ISO to a running appliance. Building the
ISO yourself is covered in [ISO_CREATION_AND_INSTALLER_GUIDE.md](ISO_CREATION_AND_INSTALLER_GUIDE.md).

## 1. Requirements

| Item | Minimum | Recommended |
| :--- | :--- | :--- |
| CPU | 64-bit x86 (amd64), 2 cores | 4 cores with AES-NI |
| Memory | 2 GB | 4 GB or more (8 GB with IDS or SSL inspection) |
| Disk | 32 GB (the A/B layout uses about 25 GB before data) | 64 GB SSD or NVMe |
| Network | 2 NICs (WAN and LAN) | Intel or Realtek NICs supported by Debian 13 |
| Firmware | Legacy BIOS or UEFI | UEFI |

Virtual machines work on Proxmox VE, VMware ESXi, Hyper-V (generation 2, Secure
Boot off) and KVM/QEMU. Give the VM at least two network adapters.

## 2. Download and verify

Download `micronos-<version>-amd64.hybrid.iso` and `SHA256SUMS` from the
project's GitHub **Releases** page, then verify the image:

```bash
sha256sum -c SHA256SUMS --ignore-missing
```

On Windows: `Get-FileHash .\micronos-<version>-amd64.hybrid.iso -Algorithm SHA256`.

## 3. Write the installer media

The ISO is a hybrid image. Write it byte-for-byte to a USB drive of 4 GB or more
with Rufus (DD mode), balenaEtcher, or:

```bash
sudo dd if=micronos-<version>-amd64.hybrid.iso of=/dev/sdX bs=4M status=progress oflag=sync
```

For a virtual machine, attach the ISO as a virtual DVD instead.

## 4. Run the installer

Boot from the media and choose **Install Micron OS**. The installer first
shows the **Micron OS Licence Agreement** (`EULA.txt`). Choose **Accept** to
continue; **Decline** stops the installer before any disk is touched. A
kickstart install must set `ACCEPT_EULA="yes"`. Then come five steps:

1. **Welcome and keyboard.**
2. **Time zone.**
3. **Storage.** Pick the target disk and a layout. The recommended layout is
   *Guided A/B Dual Slot*: a 512 MB EFI partition, 4 GB swap, two 10 GB root
   slots (A is active, B is the standby and upgrade slot) and the rest of the
   disk for persistent data in `/var/lib/micron`. **Everything on the target
   disk is erased.**
4. **Administrator password.** This one password is used for the web UI
   account `Ministrator` and for the console accounts `ministrator` and `root`.
   There is no default: enter it twice, at least 8 characters. The published
   factory password is refused. A kickstart install without `ADMIN_PW`
   generates a random password, shows it at the end and saves it to
   `/etc/micron/initial-admin-password` (delete that file after first sign-in).
5. **Network.** Nothing is assigned by default. You choose the WAN and LAN
   ports on the console after the first boot (section 5). A kickstart file
   can set `WAN_IF` / `LAN_IF` instead.

Before it partitions the disk, the installer also says what the **install
check-in** sends (appliance ID, hostname, version, uptime, boot slot, rule
count and hardware, plus the public IP it comes from) and asks whether to keep
it on. See [INSTALL_CHECKIN.md](INSTALL_CHECKIN.md); kickstart uses
`INSTALL_CHECKIN="no"` to turn it off.

Remove the installer media when asked and reboot.

Unattended installs for many branches are supported with a kickstart file; see
`installer/micron-kickstart.cfg.example` and section 9 of the ISO guide.

## 5. First boot

Sign in on the console as `ministrator` with your administrator password.
Because no interface is assigned yet, the console starts **1) Assign
Interfaces** by itself (the same dialogue as pfSense):

1. `Should VLANs be set up now [y|n]?` Answer `n` unless your ISP needs a VLAN
   tag on the WAN port. With `y`, enter a parent port and a tag (1-4094) for
   each VLAN, and press Enter when finished.
2. Enter the **WAN** port name from the list (for example `enp1s0` or `igc0`),
   or `a` to auto-detect it: plug in that port's cable when asked, and the
   port whose link comes up is used.
3. Enter the **LAN** port name, or press Enter for a WAN-only appliance.
4. Enter **OPT1**, **OPT2** ... ports, or press Enter to skip them.
5. Check the summary and answer `y` to apply it.

The LAN gets the factory address 10.10.10.10/24 and the WAN asks for an
address by DHCP. The console then shows the management address. The same
dialogue runs again when an assigned port is missing (new hardware), and from
option 1 or option 4 (factory reset) at any time. From the menu you can also
change the LAN address, ping, and reset the administrator password. The Web
GUI is not offered on WAN, so a WAN-only appliance is managed from the console
until a LAN or OPT port is assigned. `sudo` asks for the same password, and SSH does not
accept password logins as `root`: sign in as `ministrator` instead.

## 6. First login

1. Connect a computer to the LAN port.
2. Browse to `https://<LAN address>/` shown on the console banner. The
   appliance uses a self-signed certificate until you install your own, so
   your browser will warn once.
3. Sign in as `Ministrator` with the password you set during installation.
4. The first sign-in opens the **licence agreement**. An administrator ticks
   *I have read and accept* and chooses **Accept**; until then no other page
   opens. It is asked once per appliance, and again only when a release ships
   a new version of the agreement.
5. Then, in this order:
   - Enable two-step verification for your account (`/profile`).
   - Set the hostname, DNS servers and time zone (System Settings, `/system`).
   - Install a trusted certificate for the web UI (Certificates, `/certificates`).
   - Review the default firewall policies (`/firewall/rules`).
   - Take a first configuration backup (Backup & Restore, `/backup`).

## 7. Next steps

- Harden the appliance: [SECURITY_HARDENING.md](SECURITY_HARDENING.md).
- Upgrades and rollback: [MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.md](MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.md).
- Every module explained: [MICRONOS_MODULE_REFERENCE.md](MICRONOS_MODULE_REFERENCE.md).
- Check health at any time from the console with `microctl doctor`.

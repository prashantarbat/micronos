# Micron OS 1.0.0 release notes

> **Draft.** Finalise the date, checksums and known issues when the release is
> tagged. Items marked TBD must be filled in before publishing.

**Release date:** TBD
**Image:** `micronos-1.0.0-amd64.hybrid.iso` (SHA-256: TBD)
**License:** Apache License 2.0

## Highlights

Micron OS is a Debian 13 based firewall and routing appliance with a web
management interface, built to survive hard power loss at remote sites.

- **Firewall and NAT** on nftables, with object-based policies, schedules and
  per-policy logging.
- **Dynamic routing** with FRRouting: static, OSPF, BGP, RIP and IS-IS, plus
  multi-WAN failover and SD-WAN probes.
- **VPN**: WireGuard, IPsec (strongSwan) and OpenVPN, with a user portal.
- **Clientless Gateway (ZTNA)**: browser-based RDP, SSH, VNC and web access
  with its own sign-in page, TOTP, trusted certificates (including Let's
  Encrypt with auto-renewal) and firewall-controlled access.
- **Network services**: DHCP and relay, DNS with blocklists, PPPoE client and
  server with RADIUS, QoS, SNMP and multicast.
- **Security services**: IDS, web filtering, application identification, SSL
  inspection, threat intelligence and CVE advisories.
- **Resilience**: A/B root slots with health-checked rollback, transactional
  configuration with revision history, and encrypted backups.

## Preview features

These ship in 1.0.0 but are not yet complete. Do not rely on them in production.

- **High availability**: configuration is not yet synchronised to the peer.

## Installing

See [INSTALL.md](INSTALL.md). Verify the image checksum before writing it.

## Upgrading

This is the first public release. Installations of 0.x development builds
should be reinstalled from the 1.0.0 image after taking a configuration backup.

## Known issues

- TBD

## Security

The installer has no default password, an installed appliance asks for a
console login, `sudo` asks for the administrator's password, and SSH refuses
root password logins. The live ISO's accounts are locked. Follow
[SECURITY_HARDENING.md](SECURITY_HARDENING.md). Report vulnerabilities as
described in [SECURITY.md](../SECURITY.md).

## Source code

Micron OS source is on GitHub under Apache 2.0. The ISO also contains Debian
packages under their own licences, some under the GNU GPL. See the README
section "Source code for the ISO image" for how to obtain that source.

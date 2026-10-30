# Changelog

All notable changes to Micron OS are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/) as described in
[docs/MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION.md](docs/MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION.md).

## [Unreleased]

## [1.0.0-rc.1] - not yet released

First public release candidate under the Apache License 2.0.

### Added
- Clientless Gateway (ZTNA): separate gateway sign-in page at
  `/vpn/clientless-gateway/login`, independent of the appliance login.
- Clientless Gateway: HTTPS certificate upload, selection from the certificate
  store, and Let's Encrypt (ACME) issuance with automatic renewal.
- Clientless Gateway: **Gateway Access** mode. *Controlled by firewall rules*
  restricts the gateway port with ordinary firewall policies whose outgoing
  interface is **This Firewall**, with a seeded, logged allow-all starter
  policy and an in-page step-by-step guide.
- Firewall rules: **This Firewall** outgoing interface for policies that
  protect services running on the appliance itself.
- Dashboard: active and standby boot slot display.
- Project documents: NOTICE, THIRD_PARTY_LICENSES, CONTRIBUTING (DCO),
  SECURITY, CODE_OF_CONDUCT, installation and hardening guides.

### Changed
- The default theme for new installations is **Micron OS**.
- guacd is built against FreeRDP 3.

### Fixed
- ISO build failures.
- OSPF status tab returning "Permission denied".
- WireGuard status legend text contrast.

### Security
- Gateway sessions use their own session key and can never reach the
  management UI.

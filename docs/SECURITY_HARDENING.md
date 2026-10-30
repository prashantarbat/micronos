# Security hardening guide

A Micron OS appliance sits at the edge of your network, so its own management
surface needs the same care as the networks it protects. Work through this
list after installation and review it after every major change.

## 1. Credentials

- [ ] Set a strong, unique administrator password during installation (the
      installer has no default and refuses the published factory password).
- [ ] If the install was a kickstart with a generated password, delete
      `/etc/micron/initial-admin-password` after you sign in.
- [ ] Add an SSH key for `ministrator` and set `PasswordAuthentication no` in
      `/etc/ssh/sshd_config.d/99-micronos.conf`. Root password logins over SSH
      are already off.
- [ ] Enable two-step verification (TOTP) for every web UI account.
- [ ] Create a named account per administrator on the Users page and give each
      the narrowest role that works. Keep `Ministrator` for break-glass use.
- [ ] Remove accounts for people who no longer need access.

## 2. Management access

- [ ] Reach the web UI and SSH only from the LAN or a management VLAN. Do not
      expose them on WAN.
- [ ] If remote administration is needed, connect through WireGuard or IPsec
      first, then manage over the tunnel.
- [ ] Install a certificate from your own CA or Let's Encrypt for the web UI
      (Certificates, `/certificates`).
- [ ] Set a session timeout that fits your policy (System Settings, `/system`).

## 3. Clientless Gateway (ZTNA)

The gateway listens on its external port (443 by default) and has its own
sign-in page at `/vpn/clientless-gateway/login`.

- [ ] Require two-step verification for gateway users (**Mandate TOTP**).
- [ ] Use a trusted certificate: upload one, pick one from the certificate
      store, or let the appliance obtain and renew one from Let's Encrypt.
- [ ] Set **Gateway Access** to **Controlled by firewall rules**. The appliance
      then seeds a logged *allow all* policy for the gateway port. Add narrower
      allow policies above it (by country, address group or schedule), watch
      the log, and finally change the seeded policy to **Deny**. The **How it
      works** button next to Gateway Access walks through this step by step.
- [ ] Publish only the resources each role needs.

## 4. Firewall policy

- [ ] Keep the default inbound policy on WAN as deny.
- [ ] Log denied traffic and forward logs to a SIEM (SIEM, `/siem`).
- [ ] Use address and service objects instead of raw addresses so policies
      stay readable.
- [ ] Review policy hit counts monthly and remove rules that never match.

## 5. Updates and backups

- [ ] Apply Micron OS updates promptly. The A/B slots let you roll back if an
      update misbehaves.
- [ ] Check CVE Advisories (`/security/cve`) after each update.
- [ ] Schedule encrypted configuration backups and store a copy off the
      appliance. Test a restore on a spare box or VM.

## 6. Physical security

- [ ] Keep the appliance in a locked rack or room. Anyone at the console can
      reset the administrator password from the console menu.
- [ ] Set a firmware (BIOS/UEFI) password and disable booting from USB after
      installation.

## Reporting a vulnerability

See [SECURITY.md](../SECURITY.md).

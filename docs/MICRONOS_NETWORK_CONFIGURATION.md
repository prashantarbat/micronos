# Micron OS — Network Configuration, IPv6 Switch and Address-Change Propagation

**Author:** Prashant Arbat · **Project:** https://github.com/prashantarbat/micronos

## 1. Root cause of stale addresses after a WAN/LAN change

Before this change an interface edit only wrote the `interfaces` row ("Remember
to Apply Changes"). The Apply button sent `network.apply` without a plan; the
firewall and DHCP were never recompiled; the factory firewall rules, DHCP pool,
router and DNS options held literal `10.10.10.x` values; and at every boot
`micron-network` regenerated Kea and Unbound from hard-coded templates. A new
LAN address therefore left firewall rules, NAT and DHCP pointing at the old one,
and a reboot could bring old values back.

Separately, the compiler treated a rule whose `src_interface` was the schema
default `any` as a structured policy and dropped its address/port matchers, so
the factory "LAN → Web GUI (443)" and "LAN → DNS" rules compiled to an
unconditional accept. Fixed in `NftablesCompiler::compileFirewallRule()`.

## 2. Single source of truth and one transaction

* **Authority:** the configuration database — `interfaces` (addressing, role by
  id `wan*`/`lan*`/…) and `system_settings.ipv6_enabled`.
* **Plan:** `App\Libraries\Network\NetworkPlan` builds the network plan from it.
* **Transaction:** `App\Libraries\Network\NetworkTransaction::apply()` is the
  only way a network change reaches the OS, used by the Interfaces page (save,
  enable/disable, add, delete, re-apply), System › Networking (IPv6), the CLI
  (`php spark network …`, `microctl interface set-lan`, `microctl ipv6`):

  1. open a database transaction and apply the change;
  2. `AddressChangeSync` updates every dependent setting (below);
  3. build the plan, the nftables ruleset (`NftablesCompiler`) and the Kea
     DHCPv4 configuration (`KeaDhcpCompiler`) **from the uncommitted state**,
     validate the plan (overlaps, network/broadcast addresses, prefix, gateway
     inside the subnet, one static default gateway);
  4. send all three to micrond `network.apply`, which snapshots addresses,
     routes, IPv6 state, `/etc/nftables.conf` and Kea, checks the ruleset with
     `nft -c`, applies (`system/micron_netcfg.py`), loads the ruleset and Kea
     (`kea-dhcp4 -t` first), **verifies** addresses, default route and IPv6
     state, and on any failure restores the snapshot;
  5. commit the database only if the daemon reports `applied` (or
     `pending_confirm`); otherwise roll it back — the Web UI never shows a
     success the OS did not confirm.

* **Management interface:** when the change touches the interface the browser
  reaches the appliance through, it is applied with a 120 s confirmation window
  (`network.confirm`). Without confirmation (from the new address) the daemon
  rolls back by itself — also across a daemon restart or reboot
  (`net_recover_on_start`) — and the database is reconciled
  (`network_transactions` holds the rows to restore).

## 3. Dependency-aware updates (`AddressChangeSync`)

| Dependency | On an address/prefix change |
|---|---|
| DHCP server of the interface | router and DNS options equal to the old address follow; the pool is re-based into the new subnet (same offsets when they fit, otherwise .100–.200 or the upper half); reservations outside the new subnet are listed for review |
| Firewall rules (`source_value`/`dest_value`, `sources`/`destinations`) | exact old subnet → `{lan:network}`, exact old address → `{lan:address}` (dynamic references resolved at compile time) |
| Address objects | same |
| Port forwards (`nat_dnat.external_ip`) | old WAN address → `{wan:address}` |
| SNAT pools | a single-address pool equal to the old address → `{wan:address}` |
| Local DNS records (`dns_hosts`) | records naming the old address follow |
| Gateways (`wan_gateways`), static routes | a changed static gateway follows (monitor IP too) |
| Hosts inside the old subnet (port-forward targets, route next hops, HA VIPs, host rules) | **never guessed** — listed as "needs review" |

"Keep literal references" on the edit page (CLI `--keep-references`) lists
instead of rewriting. Unrelated addresses are never touched. Tokens are
resolved by `NftablesCompiler::resolveToken()`; a token whose interface has no
static address makes the rule be skipped with a comment, never compiled
open-ended.

## 4. IPv6 master switch

* **Default:** off on the live ISO, fresh installs and any settings row nobody
  has saved (migration 120).
* **Authority:** `system_settings.ipv6_enabled` → plan `ipv6_enabled`.
* **Off** (`micron_netcfg.apply_ipv6`): `/etc/sysctl.d/60-micron-ipv6.conf`
  sets `disable_ipv6=1` for `all`, `default`, `lo` and every managed device;
  the same is written to the running kernel (the kernel drops all IPv6
  addresses and routes); DHCPv6 clients, `kea-dhcp6-server` and `radvd` are
  stopped; the firewall compiles `meta nfproto ipv6 drop` in input and forward;
  Unbound has no `::` listener and `do-ip6: no`.
* **On:** the sysctl file enables IPv6 and IPv6 forwarding; `accept_ra=2` only
  on a WAN in SLAAC/DHCPv6 mode; configured static IPv6 addresses are applied;
  DHCPv6 client on the WAN when configured; firewall and Unbound include IPv6;
  services that bind IPv6 (`unbound`, `ssh`) are try-restarted.
* **Verification:** the daemon compares `/proc/sys/net/ipv6/conf/all/disable_ipv6`
  with the plan and rolls back on mismatch. A refused change leaves the switch
  as it was and the page says why.
* The ISO ships `60-micron-ipv6.conf` (off) and `99-micron-routing.conf`
  (IPv4 forwarding only), so IPv6 is off from early boot, before
  `micron-network` runs. At every boot `micron-network` rewrites the file from
  the database, so the administrator's choice survives slot upgrades.
* Limitation: a kernel built with `ipv6.disable=1` on the command line has no
  IPv6 at all; this switch uses the sysctl, so IPv6 can be turned on again
  without a reboot.

## 5. Boot (`system/micron-network`)

1. Bind WAN/LAN to NICs that exist (first boot on new hardware).
2. `php spark network boot-files /run/micron/network-boot` → `plan.json`,
   `nftables.conf`, `kea-dhcp4.conf`, from the database (micrond is not up yet).
3. `micron_netcfg.py boot plan.json` (falls back to the last committed
   `/etc/micron/network.json`) — the same engine micrond uses.
4. WAN lease overlapping the LAN → `php spark network set-address lan
   192.168.10.1/24 --offline` (same dependency sync) and re-apply.
5. Kea only from the generated config (checked with `kea-dhcp4 -t`); Unbound
   base file follows the IPv6 switch and includes the DNS page's
   `unbound.conf.d/micron.conf`; nftables only after `nft -c`.

Installer `ifupdown` stanzas for managed devices are switched to `inet manual`
(backup in `/etc/network/interfaces.micron-orig`) so they can never re-assign
an old address.

## 6. Existing installations (migration)

Migration `2026-10-14-000120_NetworkDefaultsAndTransactions` (idempotent):
adds `network_transactions`; applies the new defaults only to a settings row
nobody saved; turns the factory rules and DHCP options that still hold the
seeded `10.10.10.x` literals into dynamic references when they still match the
LAN. Administrator-written rules are never changed. Run `php spark migrate
--all`, then `php spark network apply` (or reboot) to regenerate everything.

Rollback: `php spark network rollback` during a confirmation window; otherwise
re-apply the previous address the same way. `php spark migrate:rollback` drops
`network_transactions` only.

## 7. CLI

```bash
php spark network status                      # plan vs kernel, pending change, last result
php spark network plan                        # the plan as JSON
php spark network apply [--confirm]           # re-apply everything
php spark network set-address lan 10.0.0.1/24 [gateway] [--keep-references] [--offline]
php spark network ipv6 on|off|status
php spark network confirm <tx> | rollback | reconcile
microctl interface set-lan 10.0.0.1/24        # same transaction
microctl ipv6 on|off|status
microctl network status
```

## 8. RPC contract (micrond)

| Method | Class | Params | Result |
|---|---|---|---|
| `network.apply` | destructive | `plan`, `ruleset?`, `dhcp4?`, `confirm_timeout?` (30–600) | `status` = `applied` / `pending_confirm` (+`tx`) / `invalid` / `busy` / `rolled_back` (+`errors`, `restored`) |
| `network.confirm` | configure | `tx` | `applied` / `none` / `mismatch` / `rolled_back` |
| `network.rollback` | configure | — | `rolled_back` |
| `network.status` | read | — | committed `plan`, `pending`, `last`, `ipv6_effective`, `routes`, per-device effective addresses |
| `system.ipv6.set` | destructive | `enabled`, `ruleset?` | as `network.apply` on the committed plan (CLI compatibility) |

## 9. Tests

`tests/test_micron_netcfg.py` (engine and daemon RPCs, rollback, confirm window,
boot fallback, ifupdown neutralisation, IPv6 sysctl), `tests/app/Libraries/
NetworkTransactionTest.php` (dependency sync, rollback on refusal / unreachable
daemon, validation, keep-references, WAN → port forwards, token compilation,
confirm/reconcile, Interfaces page and IPv6 switch through the transaction,
IPv6 drop, idempotent migration).

Not verified here: a real NIC, `ip`, `nft`, `kea-dhcp4`, systemd and a reboot
(the container has none); disruptive tests belong on a disposable VM.

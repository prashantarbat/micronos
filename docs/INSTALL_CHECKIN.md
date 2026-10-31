# Install check-in

Every Micron OS appliance tells **micronos.microninfo.com** that it exists: once
about five minutes after boot, and then once a day. The project uses it to count
active installs, see which versions and hardware are in use, and plan security
fixes and support.

It is **on by default**. The installer says so before it touches the disk and
asks whether to keep it on, and you can turn it off at any time. When it is off,
nothing is sent.

## What is sent

One HTTPS POST of JSON to `https://micronos.microninfo.com/api/v1/checkin`:

| Field | Example | From |
| :--- | :--- | :--- |
| `appliance_id` | `83E6A05D-5315-4822-D234-8C82057040AB` | System > General, Micron Appliance ID |
| `hostname` | `micronos-fw01` | System > General |
| `version` | `1.0.0-rc.1` | `VERSION` |
| `event` | `install`, `boot`, `daily` or `manual` | why this run happened |
| `uptime_sec` | `277200` (3d 5h) | `/proc/uptime` |
| `boot_slot` | `Slot A`, `Unknown` | the running A/B root slot |
| `active_rules` | `3` | live nftables rule count (the dashboard's "Active Rules") |
| `config_saved_at` | `2026-10-18T14:30:00Z` | "Last saved" of System settings |
| `kernel`, `arch` | `6.12.48-amd64`, `x86_64` | `uname` |
| `cpu_model`, `cpu_cores` | `Intel(R) Celeron(R) J4125`, `4` | `/proc/cpuinfo` |
| `mem_total_mb`, `disk_total_gb` | `8192`, `128` | `/proc/meminfo`, root filesystem |
| `interfaces` | `4` | physical network ports |
| `virtual` | `none`, `kvm`, `vmware`, `hyper-v` ... | DMI / CPU flags |
| `timezone` | `Asia/Kolkata` | System > General |

The website also records the **public IP address** the request arrives from,
and looks up its approximate **country, region, city and ISP**.

**Never sent:** firewall rules or objects, traffic or connection data, logs,
user names, passwords, keys, certificates, VPN peers, or the organisation details
in System > General.

See the exact JSON for your appliance, without sending it:

```
php /usr/local/micron/spark micron:checkin --show
```

## Turning it off (or on)

- Web UI: **System > Networking > Install Check-in**, switch off, Save.
- Console: `php /usr/local/micron/spark micron:checkin --disable` (`--enable` to turn it back on).
- Kickstart: `INSTALL_CHECKIN="no"`.
- Builds that must never call out: set `telemetry.allowed = false` in `.env`
  (or `$allowed` in `app/Config/Telemetry.php`). The switch in the UI is then greyed out.

`php spark micron:checkin --status` shows whether it is on, when it was last
sent and the last result. The same is shown under System > Networking.

## How it runs

`micron-checkin.timer` starts `micron-checkin.service` 5 minutes after boot and
then every 24 hours (with up to 30 minutes of random delay, so a site that
powers on many appliances at once does not send them all in the same second).
The service runs `php spark micron:checkin` as `www-data`. A failed send (no
internet, site down) is logged and retried at the next run; it never marks the
unit as failed.

To stop the timer entirely: `systemctl disable --now micron-checkin.timer`.

## Deleting what was sent

Email the Micron Appliance ID to the address on
https://micronos.microninfo.com/legal/privacy and the install's records are deleted.

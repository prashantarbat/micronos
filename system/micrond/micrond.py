#!/usr/bin/env python3
"""
MicronOS System IPC Daemon (micrond)
====================================
High-performance, non-blocking asynchronous Unix Domain Socket daemon providing
a secure JSON-RPC 2.0 bridge between the PHP Web Management Plane and the Linux
data plane (nftables, iproute2, strongSwan, WireGuard, Squid, Kea, Unbound, Suricata).

Protocol: 4-byte big-endian unsigned integer (uint32) length prefix followed by UTF-8 JSON payload.
Default Socket: /run/micron/micrond.sock
"""

import asyncio
import base64
import datetime
import glob
import ipaddress
import json
import logging
import os
import re
import shutil
import secrets
import signal
import socket
import sqlite3
import struct
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional

# Configure Logging
LOG_FORMAT = "[%(asctime)s] [%(levelname)s] [micrond] %(message)s"
logging.basicConfig(level=logging.INFO, format=LOG_FORMAT)
logger = logging.getLogger("micrond")


def _reject_nonstandard_json_constant(token: str):
    """Rejects the `NaN` / `Infinity` / `-Infinity` forms `json.loads` accepts.

    They are not valid JSON (RFC 8259). A peer that sends one is broken or
    probing, so the parse fails rather than letting a non-finite float into the
    daemon and from there into a response.
    """
    raise ValueError(f"non-standard JSON constant rejected: {token}")


def strict_json_loads(text: str):
    """`json.loads` with the non-standard NaN/Infinity extensions disabled.

    Used everywhere the daemon parses JSON that arrives from outside this
    process — the RPC request payload, caller-supplied config, and external
    tool output — so a non-standard constant can never enter.
    """
    return json.loads(text, parse_constant=_reject_nonstandard_json_constant)


# Tunnel tokens revoked by ztna.session_kill; read by micron-guacd-bridge.
ZTNA_REVOKED_PATH = "/run/micron/ztna-revoked.json"


def safe_atomic_write(filepath: str, content: str, mode: int = 0o644) -> None:
    """Writes content to filepath using atomic rename and explicit fsync barriers.

    1. Allocates tempfile in the exact same directory (same filesystem guarantee).
    2. Flushes Python user-space buffer and forces OS cache to disk via os.fsync.
    3. Sets file permissions before publishing.
    4. Atomically replaces destination path via os.replace.
    5. Fsyncs parent directory to ensure directory entry metadata is on media (POSIX).
    """
    dir_name = os.path.dirname(filepath)
    if dir_name:
        os.makedirs(dir_name, exist_ok=True)
    else:
        dir_name = "."

    tmp_fd, tmp_path = tempfile.mkstemp(dir=dir_name, prefix=".tmp_")
    try:
        with os.fdopen(tmp_fd, "w", encoding="utf-8") as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())

        try:
            os.chmod(tmp_path, mode)
        except OSError:
            pass

        if os.name == "nt" and os.path.exists(filepath):
            try:
                os.chmod(filepath, 0o666)
            except OSError:
                pass

        os.replace(tmp_path, filepath)

        # Fsync parent directory to ensure directory entry metadata is on media (POSIX)
        try:
            dir_fd = os.open(dir_name, os.O_RDONLY)
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
        except (OSError, AttributeError):
            pass
    except Exception:
        if os.path.exists(tmp_path):
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
        raise


SOCKET_PATH = "/run/micron/micrond.sock"
CONFIG_DIR = "/etc/micron"
ROLLBACK_TIMEOUT = 60  # seconds

# The ruleset captured before the most recent apply, reloaded by the rollback
# watchdog and by `system.rollback`.
NFTABLES_SNAPSHOT = "/etc/nftables.conf.bak"

# Squid web proxy paths used by the WebFilter cache/status RPCs.
SQUID_CONF = "/etc/squid/squid.conf"
SQUID_SPOOL_DIR = "/var/spool/squid"
SQUID_ACCESS_LOG = "/var/log/squid/access.log"

# Multicast / IGMP paths used by the multicast.apply and multicast.status RPCs.
IGMPPROXY_CONF = "/etc/igmpproxy.conf"
MULTICAST_PIM_FILE = "/etc/micron/multicast-pim.vtysh"
MDNS_REPEATER_CONF = "/etc/mdns-repeater.conf"

# Bridge sysfs attributes the compiler is allowed to touch (whitelist).
SNOOPING_SYSFS_ATTRS = {
    "multicast_snooping",
    "multicast_querier",
    "multicast_query_interval",
    "multicast_startup_querier",
}
IF_NAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$")

# DNSBL / Unbound blocklist paths used by the dnsbl.apply and dnsbl.update RPCs.
# The control plane stages one artifact file per feed here; the daemon only ever
# reads from this directory, never from a caller-supplied path elsewhere.
DNSBL_STAGE_DIR = "/var/lib/micron/dnsbl"
DNSBL_BLOCKLIST_FILE = "/etc/unbound/micron-blocklist.conf"
DNSBL_ARTIFACT_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$")
DNSBL_MAX_ENTRIES = 2000000
DNSBL_MAX_ARTIFACT_BYTES = 8 * 1024 * 1024

# Appliance stub resolver file written by the dns.resolver.apply RPC. This is
# how the *firewall itself* resolves DNS (updates, package installs); clients on
# the LAN are served by Unbound. The compiled content is validated line by line
# (comments and `nameserver <ip>` only) so control-plane text can never become
# arbitrary resolv.conf syntax.
RESOLV_CONF = "/etc/resolv.conf"
RESOLV_MAX_BYTES = 64 * 1024
RESOLV_MAX_NAMESERVERS = 8
RESOLV_BEHAVIORS = ("local-fallback", "local-only", "remote-only")

# Dynamic DNS servers: the interface hooks (dhclient/pppd/openvpn up-scripts)
# drop one file per dynamic link under DYNAMIC_DNS_DIR/<source>/<link>.dns, one
# address per line. The interface path reads them back and merges them into the
# appliance stub resolver while the DNS Server Override is enabled. Every link
# with no file simply contributes nothing.
DYNAMIC_DNS_DIR = "/run/micron/dynamic-dns"
DYNAMIC_DNS_MAX_SERVERS = 8
DYNAMIC_DNS_SOURCES = ("dhcp", "ppp", "openvpn")

# Hostname validation for feed entries: lowercase, no leading/trailing dot.
DOMAIN_RE = re.compile(r"^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z]{2,63}$")

# systemd units the management plane may control, keyed by `system_services` id.
# The daemon has no database access, so this mirrors the services inventory;
# resolving the id here means a caller can never name an arbitrary unit.
SERVICE_UNITS = {
    "c-icap": "c-icap",
    "chrony": "chrony",
    "clamav": "clamav-daemon",
    "conntrackd": "conntrackd",
    "darkstat": "darkstat",
    "frr": "frr",
    "gateway-monitor": "gateway-monitor",
    "igmpproxy": "igmpproxy",
    "kea-dhcp4": "kea-dhcp4-server",
    "keepalived": "keepalived",
    "lldpd": "lldpd",
    "mdns-repeater": "mdns-repeater",
    "netbird": "netbird",
    "nftables": "nftables",
    "openvpn": "openvpn@server",
    "rsyslog": "rsyslog",
    "snmpd": "snmpd",
    "squid": "squid",
    "strongswan": "strongswan",
    "suricata": "suricata",
    "tayga": "tayga",
    "unbound": "unbound",
    "wireguard": "wireguard",
    "zerotier-one": "zerotier-one",
}
SERVICE_ACTIONS = {"start", "stop", "restart", "enable", "disable"}
UNIT_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9@._-]{0,63}$")

# Daemons a *connector* (manifest-driven, i.e. driven by untrusted data) may
# control. This is a strict allowlist — anything absent is refused — so a
# poisoned manifest can never stop safety-critical infrastructure: nftables,
# unbound, strongswan, wireguard, keepalived, FRR, kea-dhcp4, suricata, chrony,
# rsyslog, snmpd and the admin-plane daemons all fall outside it. Only the
# service-plane daemons a connector plausibly manages are listed.
CONNECTOR_SAFE_UNITS = {
    "c-icap": "c-icap",
    "clamav": "clamav-daemon",
    "igmpproxy": "igmpproxy",
    "mdns-repeater": "mdns-repeater",
    "squid": "squid",
}

# ------------------------------------------------------------------ Plugins
# Curated Class B plugin catalogue (Plugin Spec §5, §11). The management plane
# references a plugin *id*; micrond resolves it against this fixed table, so a
# caller can never name an arbitrary package or unit. The PHP-side definitions
# under app/Plugins/definitions/ mirror this catalogue for planning and UI.
PLUGIN_CATALOG: Dict[str, Dict[str, Any]] = {
    "darkstat": {
        "packages": ["darkstat"],
        "units": {"darkstat": "darkstat"},
        "capabilities": [
            "system.service:darkstat", "net.raw", "root.rpc:plugin.status",
            "root.rpc:plugin.darkstat.apply", "root.rpc:plugin.darkstat.status",
            "fs.write:/etc/systemd/system/darkstat.service.d/micron.conf",
            "fs.write:/var/lib/micron/plugins/darkstat",
        ],
    },
    "iperf3": {
        "packages": ["iperf3"],
        "units": {"iperf3": "iperf3"},
        "capabilities": ["system.service:iperf3", "root.rpc:plugin.status"],
    },
    "lightsquid": {
        "packages": ["perl", "libgd-perl"],
        "units": {},
        "capabilities": [
            "root.rpc:plugin.status",
            "root.rpc:plugin.lightsquid.apply", "root.rpc:plugin.lightsquid.status",
            "root.rpc:plugin.lightsquid.refresh",
            "fs.write:/etc/lightsquid/lightsquid.conf",
            "fs.write:/etc/cron.d/lightsquid",
            "fs.write:/var/lib/micron/plugins/lightsquid",
        ],
    },
    "lldpd": {
        "packages": ["lldpd"],
        "units": {"lldpd": "lldpd"},
        "capabilities": [
            "system.service:lldpd", "net.raw", "root.rpc:plugin.status",
            "root.rpc:plugin.lldpd.apply", "root.rpc:plugin.lldpd.status",
            "root.rpc:plugin.lldpd.neighbours",
            "fs.write:/etc/systemd/system/lldpd.service.d/micron.conf",
            "fs.write:/etc/lldpd.conf",
            "fs.write:/var/lib/micron/plugins/lldpd",
        ],
    },
    # Class C: the package comes from a THIRD-PARTY repository, so `repos` names
    # the curated repository that must be applied before the package can resolve.
    # The management plane blocks the install from the database side; this entry
    # is what makes the daemon check the disk, so a plugin cannot be installed
    # from a source that was never applied — whatever the database says.
    "netbird": {
        "packages": ["netbird"],
        "units": {"netbird": "netbird"},
        "repos": ["netbird"],
        "capabilities": [
            "system.service:netbird", "net.raw", "firewall.ifgroup:wt+",
            "fs.write:/etc/netbird", "root.rpc:plugin.status",
            "root.rpc:plugin.netbird.status", "root.rpc:plugin.netbird.up",
            "root.rpc:plugin.netbird.down", "root.rpc:plugin.netbird.enroll",
        ],
    },
    # The second Class C overlay (§11): the same shape as netbird and the same
    # repository gate. ZeroTier's package is `zerotier-one` and its unit is the
    # package's own, so the generic status/control widget resolves the very unit
    # the package ships. A node joins and leaves *networks* by network id — a
    # 16-character hex string, which is the only value the page may ever name.
    "zerotier": {
        "packages": ["zerotier-one"],
        "units": {"zerotier-one": "zerotier-one"},
        "repos": ["zerotier"],
        "capabilities": [
            "system.service:zerotier-one", "net.raw", "firewall.ifgroup:zt+",
            "fs.write:/var/lib/zerotier-one", "root.rpc:plugin.status",
            "root.rpc:plugin.zerotier.status", "root.rpc:plugin.zerotier.join",
            "root.rpc:plugin.zerotier.leave",
        ],
    },
    # NAT64/DNS64 (Class B): tayga ships in Debian main, so no repository is
    # involved. The translator unit is the package's own; the DNS64 half writes
    # an Unbound include file, never a protected unit's service state.
    "nat64": {
        "packages": ["tayga"],
        "units": {"tayga": "tayga"},
        "capabilities": [
            "system.service:tayga", "root.rpc:plugin.status",
            "root.rpc:plugin.nat64.apply", "root.rpc:plugin.nat64.status",
            "fs.write:/etc/tayga.conf",
            "fs.write:/etc/systemd/system/micron-nat64-net.service",
            "fs.write:/etc/unbound/unbound.conf.d/micron-nat64.conf",
            "fs.write:/var/lib/micron/plugins/nat64", "firewall.rules",
        ],
    },
    # Free IPv6 6in4 tunnel brokers (Class B): no package at all — the SIT
    # device is kernel-side and the plugin generates one systemd unit. An empty
    # package list skips every apt step in install/upgrade/remove.
    "ipv6-tunnel": {
        "packages": [],
        "units": {},
        "capabilities": [
            "root.rpc:plugin.status", "root.rpc:plugin.tunnel.apply",
            "root.rpc:plugin.tunnel.status", "root.rpc:plugin.tunnel.update",
            "fs.write:/etc/systemd/system/micron-6in4.service",
            "fs.write:/var/lib/micron/plugins/ipv6-tunnel",
            "kernel.module:sit", "firewall.rules",
        ],
    },
}

# ------------------------------------------------------------- NetBird (Class C)
# The overlay agent's CLI contract, from the vendor's documented reference:
# `netbird [command] [subcommand] [flags]`, `netbird status --json` for machine
# output, and `NB_<FLAG>` environment variables mirroring every flag. Nothing
# here is passed through a shell.
NETBIRD_BIN = "/usr/bin/netbird"
NETBIRD_UNIT = "netbird"
# A setup key is a secret (Plugin Spec PL7): it is never argv, never logged and
# never echoed back — it reaches the agent through the documented `--setup-key-file`.
NETBIRD_SETUP_KEY_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._\-]{7,127}$")
NETBIRD_MANAGEMENT_RE = re.compile(r"^https://[A-Za-z0-9][A-Za-z0-9.\-]{0,253}(?::[0-9]{1,5})?$")

# --------------------------------------------------------- ZeroTier (Class C)
# The vendor's CLI contract: `zerotier-cli [-j] <command> [<args>]`, where
# `info` prints `200 info <address> <version> <status>`, `listnetworks` prints one
# `200 listnetworks <nwid> <name> <mac> <status> <type> <dev> <ips...>` line per
# joined network (or the same as JSON under `-j`), and `join`/`leave` take a
# single argument. Nothing here is passed through a shell, and the only value a
# caller may supply is a 16-character hex network id.
ZEROTIER_CLI_BIN = "/usr/sbin/zerotier-cli"
ZEROTIER_UNIT = "zerotier-one"
ZEROTIER_NETWORK_RE = re.compile(r"^[0-9a-f]{16}$")
# Anchored so one command's output can never be read as another's: every
# zerotier-cli response starts with the numeric status code `200`.
ZEROTIER_INFO_RE = re.compile(
    r"^\s*200\s+info\s+([0-9a-fA-F]{10,16})\s+(\S+)\s+(\S+)\s*$")
# The CLI's own leading status code, e.g. the `200` in `200 join OK` or the `500`
# in a refusal. `_zerotier_status_code` reads it, because zerotier-cli reports
# many of its failures there with a zero exit status.
ZEROTIER_STATUS_CODE_RE = re.compile(r"^\s*(\d{3})\b")

# Units a plugin may NEVER control, whatever it is granted. This is the set of
# core services already managed by the admin plane — the units an authenticated
# operator must keep reachable and an extension must not (Plugin Spec §7).
PLUGIN_UNITS = {
    unit for p in PLUGIN_CATALOG.values() for unit in p.get("units", {}).values()
}
PROTECTED_UNITS = (set(SERVICE_UNITS.values()) | {"kea-ctrl-agent", "systemd-networkd", "systemd-resolved"}) - PLUGIN_UNITS


# Packages shipped in the appliance image. A plugin that requires one is
# refused outright: an extension must never remove or replace a core dependency.
PROTECTED_PACKAGES = {
    "nftables", "unbound", "wireguard-tools", "strongswan", "frr", "keepalived",
    "suricata", "squid", "kea-dhcp4-server", "kea-ctrl-agent", "chrony",
    "rsyslog", "snmpd", "openvpn", "c-icap", "clamav", "clamav-daemon",
    "igmpproxy", "mdns-repeater", "iproute2", "systemd",
}

# ------------------------------------------------------- Plugin repositories
# Curated apt repositories for plugin packages (Plugin Spec §5.2).
#
# An apt repository is arbitrary root code, so the daemon treats every field as
# hostile and GENERATES the source file itself — there is no field for apt
# options, so `[trusted=yes]` and friends are unexpressible. Two properties make
# an operator-added repository safe to sit next to the distribution's own:
#
#   * the signing key is imported into a private keyring and its fingerprint is
#     compared against the value the operator declared out of band; a mismatch
#     refuses the repository outright, and
#   * the repository is pinned BELOW the distribution (priority 100) and the
#     protected core packages are pinned to the appliance's own release, so a
#     curated repository can supply packages the distribution lacks but can
#     never outbid the distribution for a core dependency.
PLUGIN_REPO_SOURCES_DIR = "/etc/apt/sources.list.d"
PLUGIN_REPO_PREFERENCES_DIR = "/etc/apt/preferences.d"
PLUGIN_REPO_KEYRING_DIR = "/etc/apt/keyrings"
PLUGIN_REPO_PREFIX = "micron-"
PLUGIN_REPO_PIN_PRIORITY = 100
PLUGIN_REPO_CORE_PIN = "/etc/apt/preferences.d/micron-plugin-core.pref"

# ------------------------------------------------- NAT64 / DNS64 (nat64 plugin)
# tayga's own reference paths and the fixed tun device name its sample config
# uses. The unit shipped by Debian is `tayga.service` (`/usr/sbin/tayga -d
# --config /etc/tayga.conf`); everything host-side — link, addresses, routes —
# is configured here, because tayga.conf(5) explicitly leaves the host-side
# parameters of the tun device to the administrator.
TAYGA_BIN = "/usr/sbin/tayga"
TAYGA_CONF = "/etc/tayga.conf"
TAYGA_TUN = "nat64"
NAT64_UNIT = "micron-nat64-net"
NAT64_UNIT_FILE = "/etc/systemd/system/micron-nat64-net.service"
# DNS64 is an Unbound fragment beside the protected resolver: the daemon writes
# it, gates the whole config through `unbound-checkconf`, and rolls it back on
# rejection — never a unit control (Plugin Spec §7 protects `unbound`).
NAT64_DNS64_FRAGMENT = "/etc/unbound/unbound.conf.d/micron-nat64.conf"
NAT64_STATE_DIR = "/var/lib/micron/plugins/nat64"
# RFC 6052 §2.2: only these prefix lengths embed an IPv4 address correctly.
NAT64_PREFIX_LENGTHS = {32, 40, 48, 56, 64, 96}

# ------------------------------------------- IPv6 6in4 tunnel (ipv6-tunnel plugin)
# A configuration-only plugin: no package, one generated oneshot unit that runs
# fixed `/usr/sbin/ip` argv (Debian 13 ships iproute2's `ip` in /usr/sbin).
TUNNEL_UNIT = "micron-6in4"
TUNNEL_UNIT_FILE = "/etc/systemd/system/micron-6in4.service"
TUNNEL_IFACE = "sit1"
TUNNEL_STATE_DIR = "/var/lib/micron/plugins/ipv6-tunnel"
# The iproute2 binary, resolved ABSOLUTELY: a generated unit must never depend on
# the PATH systemd happens to hand it. Deliberately a different name from the
# diagnostics `IP_BIN` further down, which is a bare `ip` *on purpose* (the
# Diagnostics tools are looked up by PATH). The two were once the same name, and
# because Python binds the later module-level assignment, every unit rendered
# here — this one and the NAT64 host-side unit — used a PATH lookup while the
# comment above promised a fixed absolute argv. Keep the names apart.
IPROUTE_BIN = "/usr/sbin/ip"
# The broker's documented update API (dyndns2-style, key-authenticated GET).
# The host is a constant: a caller never supplies a URL (no SSRF surface).
HE_UPDATE_URL = "https://ipv4.tunnelbroker.net/nic/update"
HE_UPDATE_KEY_RE = re.compile(r"^[A-Za-z0-9]{8,64}$")
HE_UPDATE_USER_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
HE_UPDATE_TUNNEL_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")

# ---------------------------------------------- darkstat (darkstat plugin)
# The package ships its own `darkstat` unit; the plugin does not replace it but
# drops a systemd drop-in over its ExecStart, so the unit name (and therefore the
# generic plugin status/control) stays the package's own. darkstat takes every
# setting on the command line, so the drop-in *is* the configuration.
DARKSTAT_BIN = "/usr/sbin/darkstat"
DARKSTAT_UNIT = "darkstat"
DARKSTAT_DROPIN_DIR = "/etc/systemd/system/darkstat.service.d"
DARKSTAT_DROPIN = "/etc/systemd/system/darkstat.service.d/micron.conf"
DARKSTAT_STATE_DIR = "/var/lib/micron/plugins/darkstat"
DARKSTAT_CHROOT_DEFAULT = "/var/lib/darkstat"
# The unprivileged account darkstat switches to (its own default is `nobody`).
DARKSTAT_USER = "nobody"
DARKSTAT_GROUP = "nogroup"
# A capture interface is `ip link`'s name: IFNAMSIZ is 16, so fifteen characters.
DARKSTAT_IFACE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:@-]{0,14}$")
# The database and daylog names are relative to the chroot directory, so they are
# bare filenames — a slash would let a name escape the chroot darkstat sets.
DARKSTAT_FILE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
DARKSTAT_CHROOT_RE = re.compile(r"^/[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$")

# -------------------------------------------- lightsquid (lightsquid plugin)
# Lightsquid is an offline Squid access-log reporter: it contributes no daemon
# and opens no listener, so there is no unit to drop settings over. Its settings
# live in a config file and its schedule in a cron drop-in, and "applying" is
# writing those two files — the same rule as darkstat, that the daemon writes and
# the page only ever requests.
LIGHTSQUID_CONFIG_DIR = "/etc/lightsquid"
LIGHTSQUID_CONFIG = "/etc/lightsquid/lightsquid.conf"
LIGHTSQUID_CRON = "/etc/cron.d/lightsquid"
LIGHTSQUID_STATE_DIR = "/var/lib/micron/plugins/lightsquid"
LIGHTSQUID_PARSER = "/usr/lib/lightsquid/lightparser.pl"
LIGHTSQUID_PERL = "/usr/bin/perl"
LIGHTSQUID_REPORT_DIR_DEFAULT = "/var/lightsquid/report"
LIGHTSQUID_LOG_DIR_DEFAULT = "/var/log/squid"
LIGHTSQUID_LOG_FILE = "access.log"
# The language, template, bar-colour and IP-resolve sets are the reporter's own;
# the schedule names are pfSense's, so an operator moving from pfSense maps 1:1.
LIGHTSQUID_LANGS = frozenset({"bg", "cz", "eng", "fr", "hu", "it", "pt_br", "ru", "ru-koi8", "sp", "ua"})
LIGHTSQUID_TEMPLATES = frozenset({"base", "text", "novosea", "novopf"})
LIGHTSQUID_BARCOLORS = frozenset({"orange", "blue", "green", "yellow", "brown", "red"})
LIGHTSQUID_IP2NAMES = frozenset({"ip", "demo", "dns", "simple", "smb", "squidauth"})
LIGHTSQUID_SCHEDULES: Dict[str, Optional[tuple]] = {
    "none": None,
    "10m": ("*/10", "*"), "20m": ("*/20", "*"), "30m": ("*/30", "*"),
    "40m": ("*/40", "*"), "50m": ("*/50", "*"), "60m": ("*/60", "*"),
    "2h": ("0", "*/2"), "3h": ("0", "*/3"), "4h": ("0", "*/4"),
    "6h": ("0", "*/6"), "8h": ("0", "*/8"), "12h": ("0", "*/12"),
    "24h": ("45", "23"),
}
LIGHTSQUID_SCHEDULE_DEFAULT = "60m"
# The report directory and the Squid log directory are absolute paths; the skip
# list is domains/subnets, which may carry a trailing separator (192.168.1.).
LIGHTSQUID_PATH_RE = re.compile(r"^/[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$")
LIGHTSQUID_SKIP_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,252}$")

# ----------------------------------------------------- lldpd (lldpd plugin)
# The package ships its own `lldpd` unit; the plugin drops a systemd drop-in
# over its ExecStart, so the unit name — and therefore the generic plugin
# status/control — stays the package's own. Most of lldpd's options are command
# line and live in that drop-in; the few only `lldpcli` can set at start (the
# transmit timing, a hostname override) go in `/etc/lldpd.conf`, which the
# daemon processes at start.
LLDPD_BIN = "/usr/sbin/lldpd"
LLDPD_CLI = "/usr/sbin/lldpcli"
LLDPD_UNIT = "lldpd"
LLDPD_DROPIN_DIR = "/etc/systemd/system/lldpd.service.d"
LLDPD_DROPIN = "/etc/systemd/system/lldpd.service.d/micron.conf"
LLDPD_CONFIG = "/etc/lldpd.conf"
LLDPD_STATE_DIR = "/var/lib/micron/plugins/lldpd"
LLDPD_TX_INTERVAL_DEFAULT = 30
LLDPD_TX_HOLD_DEFAULT = 4
# An interface *pattern*: names, globs and the !/!! inclusion rules lldpd
# documents, comma-separated. It must start with a name character or a rule.
LLDPD_PATTERN_RE = re.compile(r"^[A-Za-z0-9!*][A-Za-z0-9._:@*!,-]{0,126}$")
# A management-address pattern: addresses, interface names, globs, inversions.
LLDPD_MGMT_RE = re.compile(r"^[A-Za-z0-9!*][A-Za-z0-9._:@*!,/\[\]:-]{0,255}$")
LLDPD_HOSTNAME_RE = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9.-]{0,252}[A-Za-z0-9])?$")
# A neighbour listing is a diagnostic, not an index: cap what is held in memory.
LLDPD_NEIGHBOURS_MAX = 64 * 1024

PLUGIN_REPO_MAX = 16
PLUGIN_REPO_ARMOR_MAX = 64 * 1024
# A Release/InRelease file is metadata, not an index: a vendor's is tens to a
# few hundred kilobytes. The cap keeps `plugin.repo_test` from being handed an
# arbitrary blob to write to disk.
PLUGIN_REPO_RELEASE_MAX = 2 * 1024 * 1024
PLUGIN_REPO_SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9-]{0,31}$")
PLUGIN_REPO_SUITE_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,31}$")
PLUGIN_REPO_COMPONENT_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,31}$")
PLUGIN_REPO_ARCHES = {"amd64", "arm64", "armhf", "i386", "ppc64el", "riscv64", "s390x", "all"}
PLUGIN_REPO_FINGERPRINT_RE = re.compile(r"^(?:[0-9A-F]{40}|[0-9A-F]{64})$")
PLUGIN_REPO_URL_RE = re.compile(
    r"^https://[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~%+\-/]*)?$"
)
# Hosts that already serve the appliance's own distribution: a curated
# repository pointing at one is a typo or an attempt to shadow the image.
PLUGIN_REPO_HOST_DENY = {
    "deb.debian.org", "security.debian.org", "archive.ubuntu.com",
    "security.ubuntu.com", "ports.ubuntu.com", "snapshot.debian.org",
}
# Distribution identity -> the Origin field apt reports for its release.
PLUGIN_REPO_DISTRO_ORIGINS = {"debian": "Debian", "ubuntu": "Ubuntu"}

# ---------------------------------------------------------------- DHCP relay
# dhcp-helper instances are rendered as a systemd template unit per relay
# destination. The daemon validates every interface name, server address and
# instance slug itself — a caller may pick the values, but nothing that reaches
# the unit file is unvalidated.
DHCP_HELPER_UNIT_DIR = "/etc/systemd/system"
DHCP_HELPER_BIN = "/usr/sbin/dhcp-helper"
DHCP_HELPER_PREFIX = "dhcp-helper@"
DHCP_RELAY_MAX = 16
DHCP_IFACE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,15}$")
DHCP_SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9-]{0,28}$")

# ---------------------------------------------------------------- DHCP server (Kea)
# The management plane compiles Kea DHCPv4 JSON and hands it to the daemon as
# `dhcp.apply`. The daemon never trusts the caller's bytes: it re-checks the
# structure, runs Kea's own config test on a temp file, writes the config
# atomically and only then restarts kea-dhcp4-server. A rejected config leaves
# the previous file untouched.
KEA_DHCP4_CONF = "/etc/kea/kea-dhcp4.conf"
# Kea's memfile lease database: an append-only CSV, newest record per address wins.
KEA_LEASES4_CSV = "/var/lib/kea/kea-leases4.csv"
DHCP_LEASES_MAX = 2000
KEA_DHCP4_BIN = "kea-dhcp4"
KEA_DHCP4_UNIT = "kea-dhcp4-server"
KEA_CONFIG_MAX_BYTES = 2 * 1024 * 1024
KEA_MAX_SUBNETS = 64
MAC_RE = re.compile(r"^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$")

# ---------------------------------------------------------------- Diagnostics (ping)
# ICMP echo, the pfSense field set: a hostname, an IP protocol, an optional
# source address, and the ping count and interval. Everything is validated here
# before `ping` runs and the command is built as an argv list, so a host that
# looks like an option is refused rather than handed to `ping` as one.
PING_BIN = "ping"
PING6_BIN = "ping6"
TRACEROUTE_BIN = "traceroute"
TRACEROUTE6_BIN = "traceroute6"
DIAG_PING_MAX_COUNT = 10
DIAG_PING_MAX_WAIT = 10
DIAG_PING_TIMEOUT = 2
# Both traceroute and traceroute6 cap the TTL at 255; pfSense stops at 64
# because anything longer times out in the browser, and defaults to 18.
DIAG_TRACEROUTE_MAX_TTL = 64
DIAG_TRACEROUTE_DEFAULT_TTL = 18
DIAG_TRACEROUTE_WAIT = 2
DIAG_HOSTNAME_RE = re.compile(
    r"^(?=.{1,253}\.?$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
    r"(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?$"
)

# ---------------------------------------------------------------- Diagnostics (rest)
# The other Diagnostics tools shell out to the same kind of binary. Each argv is
# validated and built as a list here (never a shell string), so a field that looks
# like an option is refused rather than handed to the tool as one, and the payload
# the management plane reads back is the tool's own output.
#
# These are bare names on purpose — Diagnostics resolves its tools through PATH,
# unlike the generated units, which use the absolute IPROUTE_BIN defined with the
# tunnel constants above. Do not merge the two: one name for both is exactly what
# silently turned every unit's fixed argv into a PATH lookup.
DIG_BIN = "dig"
TCPDUMP_BIN = "tcpdump"
IP_BIN = "ip"
CONNTRACK_BIN = "conntrack"
DIAG_DNS_TYPES = ("A", "AAAA", "MX", "NS", "TXT", "CNAME", "PTR", "SOA")
DIAG_CAPTURE_MAX = 50
DIAG_CAPTURE_FILTER_MAX = 512
DIAG_CONNTRACK_MAX = 100
DIAG_LOG_MAX = 200
DIAG_ROUTE_MAX = 256
DIAG_IFACE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,15}$")
DIAG_LOG_TIME_RE = re.compile(r"(\d{2}:\d{2}:\d{2})")
# nftables `log prefix "MICRON-FW: "` lines reach the kernel ring buffer (journald),
# and on some builds rsyslog keeps them under /var/log.
FIREWALL_LOG_FILES = ("/var/log/nftables.log", "/var/log/firewall.log")
FIREWALL_LOG_PREFIXES = ("MICRON-FW:", "MICRON-DOS-DROP:")

# A live capture is a session, not one request: `tcpdump` writes as it captures,
# so the daemon starts it, hands back what it has produced since the last read,
# and stops it. Each session's output lands in its own file under CAPTURE_DIR, so
# a poll only ever reads the bytes that are new.
CAPTURE_DIR = "/run/micron/capture"
DIAG_CAPTURE_MAX_SESSIONS = 4
DIAG_CAPTURE_SECONDS = 120
DIAG_SESSION_READ_MAX = 65536

# A ping or a traceroute is a stream too: it prints a line per reply (or per hop)
# and can take a while, so it is a session the page polls rather than one response
# it waits on. The sessions live in their own registry — a trace can run far
# longer than a capture, so the two keep their own wall-clock caps.
DIAG_STREAM_DIR = "/run/micron/diagstream"
DIAG_STREAM_MAX_SESSIONS = 4
DIAG_STREAM_SECONDS = 300
# `ping` and `traceroute` block-buffer their output when stdout is a pipe, which
# would hold every line until the command exits. `stdbuf` re-enables line
# buffering; it is coreutils, so it is present wherever the diagnostics binaries
# already are, but it is resolved rather than assumed.
STDBUF_BIN = shutil.which("stdbuf")

# The socket probe and the resolver benchmark. The resolver list is fixed here
# rather than taken from the caller, so a diagnostics request can never aim the
# daemon's queries at an address of the caller's choosing.
DIAG_PROBE_TIMEOUT_DEFAULT = 2.5
DIAG_PROBE_TIMEOUT_MAX = 10.0
DIAG_BENCHMARK_RESOLVERS = (
    ("Cloudflare Primary", "1.1.1.1", "Public Anycast"),
    ("Google Public DNS", "8.8.8.8", "Public Anycast"),
    ("Quad9 Filtered", "9.9.9.9", "Malware Blocking"),
    ("Family-Shield Resolver", "208.67.222.222", "Malware Blocking"),
    ("Appliance Unbound", "127.0.0.1", "Local DNS Cache"),
)

# WireGuard: interface names as the kernel accepts them; configs live in the
# wg-quick directory so `wg-quick up <iface>` finds them.
WG_CONF_DIR = "/etc/wireguard"
WG_IFACE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_=+.-]{0,14}$")

# ------------------------------------------------------------- User Management (OS IAM)
PROTECTED_SYSTEM_USERS = frozenset({"root", "ministrator"})
OS_USER_RE = re.compile(r"^[a-zA-Z0-9_.][a-zA-Z0-9_.-]{0,31}$")
ALLOWED_SHELLS = frozenset({
    "/bin/bash",
    "/bin/sh",
    "/bin/dash",
    "/usr/sbin/nologin",
    "/bin/false",
    "/usr/local/bin/microctl",
})
DEFAULT_SHELL = "/bin/bash"
SUDOERS_DIR = "/etc/sudoers.d"
PASSWD_FILE = "/etc/passwd"
GROUP_FILE = "/etc/group"


def _run_checked(argv: list) -> "subprocess.CompletedProcess":
    """Run an argv list (never a shell), raising when the command fails."""
    return subprocess.run(argv, check=True)


ZTNA_CERT_PATH = "/etc/micron/pki/ztna.crt"
ZTNA_KEY_PATH = "/etc/micron/pki/ztna.key"


ZTNA_DOMAIN_RE = re.compile(r"^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$")


def _ensure_ztna_certificate(domain: str = "", force: bool = False) -> str:
    """Provision the TLS pair the ZTNA gateway listener references.

    The compiled nginx server block declares `listen 443 ssl` plus
    `ssl_certificate`/`ssl_certificate_key`. Without a certificate present,
    `nginx -t` fails and the gateway can never be applied, so generate a
    self-signed pair on first use (or when `force` asks for a fresh one, e.g.
    after the operator removes an uploaded certificate). Returns an error
    string, or "" on success.
    """
    try:
        if not force and os.path.exists(ZTNA_CERT_PATH) and os.path.exists(ZTNA_KEY_PATH):
            return ""
        if not domain or not ZTNA_DOMAIN_RE.match(domain):
            domain = "gateway.micronos.local"
        os.makedirs(os.path.dirname(ZTNA_CERT_PATH), mode=0o700, exist_ok=True)
        result = subprocess.run(
            [
                "openssl", "req", "-x509", "-nodes", "-newkey", "rsa:2048",
                "-days", "825", "-keyout", ZTNA_KEY_PATH, "-out", ZTNA_CERT_PATH,
                "-subj", "/CN=" + domain[:64],
                "-addext", "subjectAltName=DNS:" + domain,
            ],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            timeout=30,
        )
        if result.returncode != 0:
            return (
                "ztna: could not generate a self-signed certificate: "
                + result.stderr.decode(errors="replace").strip()
            )
        os.chmod(ZTNA_KEY_PATH, 0o600)
        os.chmod(ZTNA_CERT_PATH, 0o644)
        return ""
    except Exception as exc:  # pragma: no cover - environment dependent
        return f"ztna: certificate provisioning failed: {exc}"


class SafeTransactionManager:
    """Manages transactional configuration applies with automatic rollback watchdog."""
    def __init__(self, run=None):
        self.pending_timer: Optional[asyncio.TimerHandle] = None
        self.backup_path: Optional[str] = None
        # Injectable so the snapshot reload can be exercised without an `nft`
        # binary; the default still raises on failure, which is what lets an
        # operator-initiated rollback report that it did not happen.
        self.run = run or _run_checked

    def start_rollback_timer(self, loop: asyncio.AbstractEventLoop, timeout: int = ROLLBACK_TIMEOUT):
        self.cancel_timer()
        logger.warning(f"Starting auto-rollback watchdog timer ({timeout}s)...")
        self.pending_timer = loop.call_later(timeout, self._trigger_rollback)

    def confirm_commit(self):
        if self.pending_timer:
            self.pending_timer.cancel()
            self.pending_timer = None
            logger.info("Configuration transaction confirmed. Rollback watchdog disarmed.")

    def cancel_timer(self):
        if self.pending_timer:
            self.pending_timer.cancel()
            self.pending_timer = None

    def restore_snapshot(self) -> bool:
        """Reload the last verified configuration snapshot (the nftables ruleset
        captured before the most recent apply).

        Returns True when a snapshot existed and was reloaded, False when the
        appliance has nothing to fall back to (a first-ever apply). Raises when a
        snapshot exists but cannot be reloaded, so an operator-initiated rollback
        surfaces the failure instead of reporting a rollback that did not happen.
        """
        if not os.path.exists(NFTABLES_SNAPSHOT):
            return False
        self.run(["nft", "-f", NFTABLES_SNAPSHOT])
        logger.info(f"Successfully restored {NFTABLES_SNAPSHOT}")
        return True

    def rollback_now(self) -> bool:
        """Disarm the watchdog and roll back immediately (operator-initiated)."""
        self.cancel_timer()
        return self.restore_snapshot()

    def _trigger_rollback(self):
        logger.critical("Rollback timer expired without confirmation! Rolling back configuration...")
        try:
            if not self.restore_snapshot():
                logger.warning("No configuration snapshot was available to roll back to.")
        except Exception as e:
            logger.error(f"Failed to execute auto-rollback: {e}")


# =============================================================================
# RPC capability classes and caller authorization (Contract §7, Programme G6)
# =============================================================================
# Every handler is classified. The class bounds who may invoke it:
#
#   read         — observes state, changes nothing
#   configure    — changes configuration or runs a bounded, non-destructive probe
#   destructive  — can drop traffic, remove data, reboot, or run intrusive scans
#
# The UNIX socket is 0660 group www-data, so membership of that group is the
# outer boundary. Inside it, the caller's kernel-attested identity (SO_PEERCRED)
# decides the highest class it may use: root (console CLIs run through sudo)
# and the web plane's own uid get every class; any other process that happens
# to share the socket group is read-only unless /etc/micron/rpc-peers.json
# grants it more. An in-process call (the tests, the daemon itself) carries no
# peer and is not restricted.
RPC_CLASS_READ = "read"
RPC_CLASS_CONFIGURE = "configure"
RPC_CLASS_DESTRUCTIVE = "destructive"
RPC_CLASS_RANK = {RPC_CLASS_READ: 0, RPC_CLASS_CONFIGURE: 1, RPC_CLASS_DESTRUCTIVE: 2}
RPC_PEER_POLICY_FILE = "/etc/micron/rpc-peers.json"
RPC_TRUSTED_USERS = ("root", "www-data")

# Methods that only observe. Anything not listed here or in RPC_DESTRUCTIVE is
# `configure`. tests/test_micrond_rpc_contract.py fails when a handler that
# sounds read-only or destructive is left to the default.
RPC_READ_METHODS = frozenset({
    "system.ping", "system.info", "system.health", "system.updates_check", "hardware.nics", "network.status",
    "ospf.status",
    "system.service_logs", "system.services_status", "system.cron_logs",
    "system.rpc_methods", "system.package_list",
    "database.health", "storage.health",
    "interface.list", "interface.dynamic_dns", "interface.status",
    "firewall.status", "firewall.list_rules", "nat.list", "route.table",
    "vpn.wireguard.status", "vpn.wireguard.pubkey", "vpn.ipsec.status",
    "vpn.openvpn.status",
    "webfilter.status", "webfilter.access_log", "webfilter.av.status",
    "squid.status", "squid.access_log",
    "dhcp.status", "dhcp.leases", "dhcp.relay_status", "dhcp.relay_logs",
    "dns.status", "multicast.status", "wireless.status", "bond.status",
    "bridge.status", "pppoe.status", "pppoe.radius.status", "pppoe.server_status",
    "ha.status", "ids.status", "qos.status", "dpi.status", "dpi.stats",
    "dpi.live_flows", "siem.get_logs", "snmp.get_logs", "snmp.test_query",
    "ssl.ca_status", "ssl.sessions_active", "sdwan.probe_status",
    "sdwan.sla_report", "ztna.status", "ztna.test_guacd", "captive.status",
    "plugin.catalog", "plugin.status", "plugin.daemons_status",
    "plugin.daemon_logs", "plugin.repo_list", "plugin.repo_status",
    "plugin.updates", "plugin.lldpd.status", "plugin.lldpd.neighbours",
    "plugin.darkstat.status", "plugin.lightsquid.status",
    "plugin.lightsquid.report", "plugin.nat64.status", "plugin.netbird.status",
    "plugin.zerotier.status", "plugin.tunnel.status", "plugin.iperf3.status",
    "diag.ping", "diag.traceroute", "diag.dns_lookup", "diag.dns_query",
    "diag.route_lookup", "diag.routing_table", "diag.ndp_table",
    "diag.conntrack_states", "diag.firewall_logs", "diag.stream_poll",
    "diag.capture_poll", "diag.whois", "diag.sockets", "diag.tools",
    "diag.dns_benchmark", "diag.port_probe", "diag.arp_table",
    "user.list_os", "connector.status",
})

RPC_DESTRUCTIVE_METHODS = frozenset({
    "system.reboot", "system.halt", "system.rollback", "system.upgrade",
    "firewall.flush", "firewall.apply", "firewall.apply_rules",
    "diag.conntrack_kill", "diag.conntrack_kill_host", "diag.nmap",
    "diag.nmap_start", "diag.tcpdump", "diag.capture_start",
    "ha.failover", "user.delete", "plugin.remove", "plugin.repo_remove", "vpn.wireguard.remove",
    "plugin.upgrade", "ztna.session_kill",
    "backup.restore", "nat.apply", "routing.apply", "network.apply",
    "interface.apply", "bridge.delete", "bond.delete",
    "network.rollback", "system.ipv6.set",
})


# Dispatch resolves `a.b.c` to `rpc_a_b_c`, so classification is keyed on the
# handler name: `vpn.wireguard.sync` and `vpn.wireguard_sync` are one handler
# and must never be classified differently.
RPC_READ_HANDLERS = frozenset(m.replace(".", "_") for m in RPC_READ_METHODS)
RPC_DESTRUCTIVE_HANDLERS = frozenset(m.replace(".", "_") for m in RPC_DESTRUCTIVE_METHODS)


def rpc_method_class(method: str) -> str:
    key = method.replace(".", "_")
    if key in RPC_DESTRUCTIVE_HANDLERS:
        return RPC_CLASS_DESTRUCTIVE
    if key in RPC_READ_HANDLERS:
        return RPC_CLASS_READ
    return RPC_CLASS_CONFIGURE


def _uid_name(uid: int) -> str:
    try:
        import pwd
        return pwd.getpwuid(uid).pw_name
    except Exception:
        return str(uid)


def _load_peer_policy() -> Dict[str, str]:
    """`{"<user>": "<class>"}` grants from RPC_PEER_POLICY_FILE (optional)."""
    try:
        with open(RPC_PEER_POLICY_FILE, "r", encoding="utf-8") as fh:
            data = strict_json_loads(fh.read())
    except (OSError, ValueError):
        return {}
    if not isinstance(data, dict):
        return {}
    return {str(k): str(v) for k, v in data.items() if str(v) in RPC_CLASS_RANK}


def rpc_peer_max_class(peer: Optional[Dict[str, Any]]) -> str:
    """Highest class the connected peer may invoke."""
    if peer is None:
        return RPC_CLASS_DESTRUCTIVE           # in-process call
    uid = peer.get("uid")
    if uid is None:
        return RPC_CLASS_READ                  # identity unknown: fail closed
    name = _uid_name(int(uid))
    if int(uid) == 0 or name in RPC_TRUSTED_USERS:
        return RPC_CLASS_DESTRUCTIVE
    return _load_peer_policy().get(name, RPC_CLASS_READ)


def rpc_peer_credentials(writer) -> Optional[Dict[str, Any]]:
    """Kernel-attested (pid, uid, gid) of a UNIX-socket peer.

    Returns None for an in-process transport (no socket at all — test doubles),
    and {} when there is a socket but its credentials cannot be read, which
    `rpc_peer_max_class` treats as read-only.
    """
    if not hasattr(writer, "get_extra_info"):
        return None
    try:
        sock = writer.get_extra_info("socket")
        if sock is None or not hasattr(socket, "SO_PEERCRED"):
            return {}
        raw = sock.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
        pid, uid, gid = struct.unpack("3i", raw)
        return {"pid": pid, "uid": uid, "gid": gid}
    except Exception:
        return {}


# =============================================================================
# Managed configuration files (validate → write → check → reload → restore)
# =============================================================================
# The management plane compiles a subsystem's configuration from its database
# and hands the text over; the daemon owns *where* it goes, *how* it is checked
# and *which* unit is reloaded. Nothing the caller sends can choose a path, a
# checker or a unit. If the syntax check or the reload fails the previous file
# is put back (and reloaded), so a bad apply never leaves a service broken.
MANAGED_CONFIG_MAX_BYTES = 1024 * 1024
MANAGED_CONFIGS: Dict[str, Dict[str, Any]] = {
    "unbound": {
        "path": "/etc/unbound/unbound.conf.d/micron.conf",
        "mode": 0o644,
        "check": ["unbound-checkconf"],
        "reload": ["systemctl", "reload-or-restart", "unbound"],
        "unit": "unbound",
    },
    "frr": {
        "path": "/etc/frr/frr.conf",
        "mode": 0o640,
        "check": ["vtysh", "--dryrun", "--inputfile", "{path}"],
        "reload": ["systemctl", "reload-or-restart", "frr"],
        "unit": "frr",
    },
    "keepalived": {
        "path": "/etc/keepalived/keepalived.conf",
        "mode": 0o600,
        "check": ["keepalived", "--config-test", "--use-file", "{path}"],
        "reload": ["systemctl", "reload-or-restart", "keepalived"],
        "unit": "keepalived",
    },
    "snmpd": {
        "path": "/etc/snmp/snmpd.conf",
        "mode": 0o600,
        "check": None,
        "reload": ["systemctl", "restart", "snmpd"],
        "unit": "snmpd",
    },
    "rsyslog-siem": {
        "path": "/etc/rsyslog.d/60-micron-siem.conf",
        "mode": 0o644,
        "check": ["rsyslogd", "-N1"],
        "reload": ["systemctl", "restart", "rsyslog"],
        "unit": "rsyslog",
    },
    "openvpn": {
        "path": "/etc/openvpn/server.conf",
        "mode": 0o600,
        "check": None,
        "reload": ["systemctl", "restart", "openvpn@server"],
        "unit": "openvpn@server",
    },
    "suricata": {
        "path": "/etc/suricata/micron.yaml",
        "mode": 0o644,
        "check": ["suricata", "-T", "-c", "/etc/suricata/suricata.yaml"],
        "reload": ["systemctl", "reload-or-restart", "suricata"],
        "unit": "suricata",
    },
    "suricata-rules": {
        "path": "/etc/suricata/rules/micron.rules",
        "mode": 0o644,
        "check": None,
        "reload": None,
        "unit": "suricata",
    },
    "qos": {
        "path": "/etc/micron/qos.tc",
        "mode": 0o644,
        "check": None,
        "reload": ["tc", "-force", "-batch", "{path}"],
        "unit": None,
    },
    "cron": {
        "path": "/etc/cron.d/micron",
        "mode": 0o644,
        "check": None,
        "reload": None,
        "unit": "cron",
    },
    "ndpi": {
        "path": "/etc/ndpi/micron-protos.txt",
        "mode": 0o644,
        "check": None,
        "reload": None,
        "unit": None,
    },
}

# Units whose journal an operator may read through `system.service_logs`, in
# addition to SERVICE_UNITS. Keyed by the id the Services page sends.
LOG_UNITS_EXTRA = {
    "micrond": "micrond", "apache2": "apache2", "nginx": "nginx", "cron": "cron",
    "ssh": "ssh", "php-fpm": "php8.4-fpm", "lldpd": "lldpd", "darkstat": "darkstat",
    "zerotier": "zerotier-one", "netbird": "netbird", "guacd": "guacd",
}

# Cron: only these executables may be scheduled from the Web UI, and only for
# these users. A free-text command field must never become a root shell.
CRON_USERS = ("root", "www-data")
CRON_ALLOWED_PREFIXES = (
    "php spark ", "/usr/bin/php /usr/local/micron/spark ",
    "/usr/local/bin/", "/usr/local/sbin/", "/usr/sbin/logrotate ",
    "/usr/bin/freshclam", "/usr/sbin/ntpdate ",
)
CRON_FIELD_RE = re.compile(r"^[0-9*/,\-A-Za-z]{1,32}$")
CRON_COMMAND_RE = re.compile(r"^[A-Za-z0-9 _./:=,@%+-]{1,512}$")
CRON_APP_ROOT = "/usr/local/micron"

CONNTRACK_PROTOS = ("tcp", "udp", "icmp", "icmpv6", "sctp")
SNMP_COMMUNITY_RE = re.compile(r"^[A-Za-z0-9_.@#%+=-]{1,64}$")
SNMP_OID_RE = re.compile(r"^\.?[0-9]+(\.[0-9]+){1,63}$")
SIEM_PROTOS = ("udp", "tcp")
OPENVPN_STATUS_FILES = ("/run/openvpn/server.status", "/var/log/openvpn/status.log",
                        "/etc/openvpn/openvpn-status.log")
WEBGUI_CERT_DIR = "/etc/micron/ssl"
CERT_RELOAD_UNITS = ("apache2", "nginx", "squid", "openvpn@server")
VRF_NAME_RE = re.compile(r"^[A-Za-z][A-Za-z0-9_-]{0,14}$")


class OpsMixin:
    """Subsystem apply/status/log handlers the management plane already calls.

    Before this block every one of these method names was dispatched to
    "Method not found" on a real appliance while the Windows mock answered
    success — see docs/MICRONOS_IPC_RPC_CLI_CONTRACT.md §4.
    """

    # ---------------------------------------------------------------- helpers
    @staticmethod
    def _run_bounded(argv: list, timeout: float = 20.0, input_text: Optional[str] = None,
                     cap: int = 65536) -> Dict[str, Any]:
        """argv (never a shell) with a hard timeout and an output cap."""
        try:
            res = subprocess.run(argv, capture_output=True, text=True, timeout=timeout,
                                 input=input_text)
            out = (res.stdout or "") + (res.stderr or "")
            rc = res.returncode
            timed_out = False
        except subprocess.TimeoutExpired as exc:
            out = ((exc.stdout or b"").decode("utf-8", "replace") if isinstance(exc.stdout, bytes)
                   else (exc.stdout or ""))
            rc, timed_out = 124, True
        except OSError as exc:
            return {"rc": 127, "output": str(exc), "timed_out": False, "truncated": False,
                    "missing": True}
        truncated = len(out) > cap
        if truncated:
            out = out[:cap] + f"\n… output truncated at {cap // 1024} KiB\n"
        return {"rc": rc, "output": out, "timed_out": timed_out, "truncated": truncated,
                "missing": False}

    @staticmethod
    def _which(binary: str) -> Optional[str]:
        return shutil.which(binary) or next(
            (p for p in (f"/usr/sbin/{binary}", f"/sbin/{binary}", f"/usr/bin/{binary}")
             if os.path.exists(p)), None)

    @staticmethod
    def _lines_param(params: Dict[str, Any], default: int = 30, cap: int = 500) -> int:
        try:
            return max(1, min(int(params.get("lines") or default), cap))
        except (TypeError, ValueError):
            return default

    def _journal(self, unit: str, lines: int) -> str:
        res = self._run_bounded(["journalctl", "-u", unit, "-n", str(lines),
                                 "--no-pager", "-o", "short-iso"], timeout=10)
        return res["output"].strip()

    def _apply_managed_config(self, kind: str, content: Any, reload: bool = True) -> Dict[str, Any]:
        spec = MANAGED_CONFIGS.get(kind)
        steps: list = []
        warnings: list = []
        if spec is None:
            return {"success": False, "status": "error", "message": f"unknown configuration: {kind}"}
        if not isinstance(content, str) or not content.strip():
            return {"success": False, "status": "error", "steps": steps, "warnings": warnings,
                    "message": f"no {kind} configuration was supplied — nothing was changed"}
        if len(content.encode("utf-8")) > MANAGED_CONFIG_MAX_BYTES:
            return {"success": False, "status": "error", "steps": steps, "warnings": warnings,
                    "message": f"{kind} configuration exceeds {MANAGED_CONFIG_MAX_BYTES} bytes"}
        if "\x00" in content:
            return {"success": False, "status": "error", "steps": steps, "warnings": warnings,
                    "message": f"{kind} configuration contains a NUL byte"}

        path = spec["path"]
        previous: Optional[str] = None
        if os.path.exists(path):
            try:
                with open(path, "r", encoding="utf-8", errors="replace") as fh:
                    previous = fh.read()
            except OSError:
                previous = None
        if previous == content:
            steps.append(f"{path} unchanged")
        try:
            safe_atomic_write(path, content if content.endswith("\n") else content + "\n",
                              mode=spec["mode"])
            steps.append(f"wrote {path}")
        except OSError as exc:
            return {"success": False, "status": "error", "steps": steps, "warnings": warnings,
                    "message": f"could not write {path}: {exc}"}

        def restore(reason: str, reload_previous: bool = False) -> Dict[str, Any]:
            # A config rejected by its syntax check was never loaded, so the
            # running service is untouched and only the file goes back. After a
            # failed reload the service may be half-applied, so the previous
            # file is reloaded as well.
            if previous is not None:
                try:
                    safe_atomic_write(path, previous, mode=spec["mode"])
                    steps.append(f"restored previous {path}")
                    if reload_previous and spec.get("reload") and reload:
                        argv = [a.replace("{path}", path) for a in spec["reload"]]
                        self._run_bounded(argv, timeout=60)
                except OSError as exc:
                    warnings.append(f"could not restore {path}: {exc}")
            else:
                try:
                    os.remove(path)
                    steps.append(f"removed rejected {path}")
                except OSError:
                    pass
            return {"success": False, "status": "error", "steps": steps,
                    "warnings": warnings, "message": reason}

        check = spec.get("check")
        if check:
            argv = [a.replace("{path}", path) for a in check]
            if self._which(argv[0]) is None:
                warnings.append(f"{argv[0]} is not installed; the syntax check was skipped")
            else:
                res = self._run_bounded(argv, timeout=60)
                steps.append(f"{' '.join(argv)} rc={res['rc']}")
                if res["rc"] != 0:
                    detail = res["output"].strip().splitlines()
                    return restore(f"{kind} configuration was rejected by {argv[0]}: "
                                   + (" | ".join(detail[-3:]) if detail else "no detail"))

        reload_argv = spec.get("reload")
        if reload and reload_argv:
            argv = [a.replace("{path}", path) for a in reload_argv]
            if self._which(argv[0]) is None:
                warnings.append(f"{argv[0]} is not installed; the configuration was written but not loaded")
            else:
                res = self._run_bounded(argv, timeout=90)
                steps.append(f"{' '.join(argv)} rc={res['rc']}")
                if res["rc"] != 0:
                    detail = res["output"].strip().splitlines()
                    return restore(f"{kind} reload failed: " + (" | ".join(detail[-3:]) if detail else
                                                               f"exit {res['rc']}"), reload_previous=True)

        unit = spec.get("unit")
        return {
            "success": True,
            "status": "applied",
            "path": path,
            "unit": unit,
            "state": self._service_state(unit) if unit and "{" not in unit else None,
            "steps": steps,
            "warnings": warnings,
        }

    def _unit_snapshot(self, unit: str) -> Dict[str, Any]:
        res = self._run_bounded(["systemctl", "show", unit, "--no-pager",
                                 "--property=ActiveState,SubState,ActiveEnterTimestamp,MainPID,LoadState,UnitFileState"],
                                timeout=5)
        props: Dict[str, str] = {}
        for line in res["output"].splitlines():
            if "=" in line:
                k, v = line.split("=", 1)
                props[k.strip()] = v.strip()
        active = props.get("ActiveState", "unknown")
        if unit == "gateway-monitor" and props.get("LoadState", "not-found") in ("not-found", ""):
            probe_snap = self._unit_snapshot("micron-sdwan-probe")
            if probe_snap.get("installed"):
                probe_snap["unit"] = "gateway-monitor"
                return probe_snap
        unit_file = props.get("UnitFileState", "unknown")
        return {
            "unit": unit,
            "running": active == "active",
            "state": "running" if active == "active" else ("failed" if active == "failed" else "stopped"),
            "active_state": active,
            "sub_state": props.get("SubState", ""),
            "since": props.get("ActiveEnterTimestamp", ""),
            "pid": int(props.get("MainPID") or 0) if (props.get("MainPID") or "0").isdigit() else 0,
            "installed": props.get("LoadState", "not-found") not in ("not-found", ""),
            "enabled": unit_file in ("enabled", "enabled-runtime"),
            "unit_file_state": unit_file,
        }

    # ---------------------------------------------------------- system.health
    @staticmethod
    def _read_first(path: str) -> Optional[str]:
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as fh:
                return fh.read().strip()
        except OSError:
            return None

    # Kernel ARPHRD types the dashboard distinguishes (include/uapi/linux/if_arp.h).
    _ARPHRD_KIND = {"1": "ethernet", "772": "loopback", "65534": "tunnel", "776": "tunnel",
                    "778": "tunnel", "823": "tunnel", "512": "ppp", "801": "wireless", "803": "wireless"}

    def _nic_port_type(self, dev: str) -> str:
        """Connector of a physical port from ethtool ('tp', 'fibre', 'da', ...).

        Cached per device for the daemon's lifetime — the connector of a NIC
        does not change while it is plugged in — so the dashboard poll costs
        one ethtool run per port, ever. 'unknown' when ethtool is absent.
        """
        cache = self.__dict__.setdefault("_nic_port_cache", {})
        if dev in cache:
            return cache[dev]
        kind = "unknown"
        try:
            res = subprocess.run(["ethtool", dev], capture_output=True, text=True, timeout=3)
            for line in res.stdout.splitlines():
                line = line.strip()
                if line.startswith("Port:"):
                    value = line.split(":", 1)[1].strip().lower()
                    kind = {"twisted pair": "tp", "fibre": "fibre", "fiber": "fibre",
                            "direct attach copper": "da", "other": "other", "none": "none"}.get(value, value or "unknown")
                    break
        except (OSError, subprocess.SubprocessError):
            pass
        cache[dev] = kind
        return kind

    def _nic_hardware(self, dev: str) -> Dict[str, Any]:
        """What kind of port a network device is, read from /sys.

        `kind` separates physical Ethernet and Wi-Fi ports from bridges,
        VLANs, bonds, tunnels and other software devices, so the dashboard can
        draw the real front panel (enp7s0, eno1, wlp2s0 ...) and the Interfaces
        page can offer wireless settings only when a radio exists.
        """
        base = f"/sys/class/net/{dev}"
        physical = os.path.exists(f"{base}/device")
        wireless = os.path.isdir(f"{base}/wireless") or os.path.exists(f"{base}/phy80211")
        arphrd = self._read_first(f"{base}/type") or ""
        if dev == "lo" or arphrd == "772":
            kind = "loopback"
        elif wireless:
            kind = "wireless"
        elif os.path.isdir(f"{base}/bridge"):
            kind = "bridge"
        elif os.path.isdir(f"{base}/bonding"):
            kind = "bond"
        elif os.path.exists(f"/proc/net/vlan/{dev}"):
            kind = "vlan"
        elif physical:
            kind = "ethernet" if arphrd in ("1", "") else self._ARPHRD_KIND.get(arphrd, "other")
        else:
            kind = self._ARPHRD_KIND.get(arphrd, "virtual")
            if kind == "ethernet":
                kind = "virtual"
        driver = bus = slot = None
        if physical:
            try:
                driver = os.path.basename(os.readlink(f"{base}/device/driver"))
            except OSError:
                driver = None
            try:
                bus = os.path.basename(os.readlink(f"{base}/device/subsystem"))
            except OSError:
                bus = None
            try:
                slot = os.path.basename(os.path.realpath(f"{base}/device"))
            except OSError:
                slot = None
        out: Dict[str, Any] = {"kind": kind, "wireless": wireless, "driver": driver, "bus": bus, "slot": slot}
        if kind == "ethernet":
            out["port_type"] = self._nic_port_type(dev)
        return out

    async def rpc_hardware_nics(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: the network ports this machine actually has.

        Physical Ethernet and wireless ports with their driver, bus, connector
        and link state; software devices (bridges, VLANs, tunnels) are left
        out. This is what the Web UI uses to decide which hardware-specific
        options to show — e.g. Wi-Fi settings only when a radio is present.
        """
        nics = []
        names = sorted(os.listdir("/sys/class/net")) if os.path.isdir("/sys/class/net") else []
        for dev in names:
            hw = self._nic_hardware(dev)
            if hw["kind"] not in ("ethernet", "wireless"):
                continue
            base = f"/sys/class/net/{dev}"
            speed = self._read_first(f"{base}/speed")
            nics.append({
                "name": dev,
                "mac": self._read_first(f"{base}/address") or "",
                "operstate": self._read_first(f"{base}/operstate") or "unknown",
                "carrier": self._read_first(f"{base}/carrier") == "1",
                "speed_mbps": int(speed) if speed and speed.lstrip("-").isdigit() and int(speed) > 0 else None,
                **hw,
            })
        return {
            "success": True, "status": "ok", "nics": nics,
            "wireless": [n["name"] for n in nics if n["wireless"]],
            "ethernet": [n["name"] for n in nics if n["kind"] == "ethernet"],
        }

    def _health_snapshot(self) -> Dict[str, Any]:
        info: Dict[str, Any] = {}
        up = self._read_first("/proc/uptime")
        info["uptime_sec"] = int(float(up.split()[0])) if up else None
        try:
            info["load_avg"] = [round(x, 2) for x in os.getloadavg()] if hasattr(os, "getloadavg") else [0.0, 0.0, 0.0]
        except (OSError, AttributeError):
            info["load_avg"] = None
        info["cpu_count"] = os.cpu_count()
        model = None
        cpuinfo = self._read_first("/proc/cpuinfo") or ""
        for line in cpuinfo.splitlines():
            if line.lower().startswith("model name"):
                model = line.split(":", 1)[1].strip()
                break
        info["cpu_model"] = model
        mem: Dict[str, int] = {}
        for line in (self._read_first("/proc/meminfo") or "").splitlines():
            parts = line.replace(":", "").split()
            if len(parts) >= 2 and parts[1].isdigit():
                mem[parts[0]] = int(parts[1])
        if mem:
            total = mem.get("MemTotal", 0) // 1024
            avail = mem.get("MemAvailable", mem.get("MemFree", 0)) // 1024
            info["mem_total_mb"] = total
            info["mem_used_mb"] = max(0, total - avail)
            info["mem_available_mb"] = avail
            info["swap_total_mb"] = mem.get("SwapTotal", 0) // 1024
            info["swap_used_mb"] = max(0, (mem.get("SwapTotal", 0) - mem.get("SwapFree", 0)) // 1024)
        disks = []
        for mount in ("/", "/var/lib/micron", "/var/log"):
            if not os.path.isdir(mount):
                continue
            try:
                du = shutil.disk_usage(mount)
            except OSError:
                continue
            entry = {"mount": mount, "total_gb": round(du.total / 1073741824, 2),
                     "used_gb": round(du.used / 1073741824, 2),
                     "free_gb": round(du.free / 1073741824, 2),
                     "used_pct": round(du.used / du.total * 100, 1) if du.total else 0.0}
            if not any(d["total_gb"] == entry["total_gb"] and d["used_gb"] == entry["used_gb"] for d in disks):
                disks.append(entry)
        info["disks"] = disks
        ct_count = self._read_first("/proc/sys/net/netfilter/nf_conntrack_count")
        ct_max = self._read_first("/proc/sys/net/netfilter/nf_conntrack_max")
        info["conntrack_count"] = int(ct_count) if ct_count and ct_count.isdigit() else None
        info["conntrack_max"] = int(ct_max) if ct_max and ct_max.isdigit() else None
        temps = []
        for zone in sorted(glob.glob("/sys/class/thermal/thermal_zone*/temp")):
            raw = self._read_first(zone)
            if raw and raw.lstrip("-").isdigit():
                temps.append(int(raw) / 1000.0)
        info["temperature_c"] = round(max(temps), 1) if temps else None
        ifaces = []
        for dev in sorted(os.listdir("/sys/class/net")) if os.path.isdir("/sys/class/net") else []:
            base = f"/sys/class/net/{dev}"
            def num(name: str) -> Optional[int]:
                v = self._read_first(f"{base}/statistics/{name}")
                return int(v) if v and v.isdigit() else None
            speed = self._read_first(f"{base}/speed")
            ifaces.append({
                "name": dev,
                "operstate": self._read_first(f"{base}/operstate") or "unknown",
                "carrier": self._read_first(f"{base}/carrier") == "1",
                "mac": self._read_first(f"{base}/address") or "",
                "mtu": int(self._read_first(f"{base}/mtu") or 0),
                "speed_mbps": int(speed) if speed and speed.lstrip("-").isdigit() and int(speed) > 0 else None,
                "rx_bytes": num("rx_bytes"), "tx_bytes": num("tx_bytes"),
                "rx_packets": num("rx_packets"), "tx_packets": num("tx_packets"),
                "rx_errors": num("rx_errors"), "tx_errors": num("tx_errors"),
                "virtual": not os.path.exists(f"{base}/device"),
                **self._nic_hardware(dev),
            })
        info["interfaces"] = ifaces
        info["storage"] = self._storage_telemetry()
        info["database"] = self._database_health()
        info["timestamp"] = time.time()
        return info

    def _locate_database_path(self) -> Optional[str]:
        candidates = [
            "/usr/local/micron/writable/micron_config.db",
            os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), "writable", "micron_config.db"),
            os.path.abspath("writable/micron_config.db"),
        ]
        for c in candidates:
            if os.path.exists(c):
                return c
        return "/usr/local/micron/writable/micron_config.db"

    def _database_health(self) -> Dict[str, Any]:
        db_path = self._locate_database_path()
        if not db_path or not os.path.exists(db_path):
            return {
                "healthy": True,
                "status": "not_initialized",
                "path": db_path or "",
                "db_size_bytes": 0,
                "wal_size_bytes": 0,
                "shm_size_bytes": 0,
                "snapshot_present": False,
            }

        db_size = os.path.getsize(db_path)
        wal_path = f"{db_path}-wal"
        shm_path = f"{db_path}-shm"
        snap_path = f"{db_path}.snapshot"
        wal_size = os.path.getsize(wal_path) if os.path.exists(wal_path) else 0
        shm_size = os.path.getsize(shm_path) if os.path.exists(shm_path) else 0
        snap_present = os.path.exists(snap_path)
        snap_age_sec = (time.time() - os.path.getmtime(snap_path)) if snap_present else None

        healthy = False
        integrity_msg = "unknown"
        journal_mode = "unknown"
        synchronous = None
        checkpoint_status = None

        try:
            con = sqlite3.connect(db_path, timeout=3.0)
            cur = con.cursor()
            cur.execute("PRAGMA quick_check;")
            res = cur.fetchone()
            if res and res[0].lower() == "ok":
                healthy = True
                integrity_msg = "ok"
            else:
                integrity_msg = res[0] if res else "failed"

            cur.execute("PRAGMA journal_mode;")
            jm = cur.fetchone()
            if jm:
                journal_mode = str(jm[0]).lower()

            cur.execute("PRAGMA synchronous;")
            sync_row = cur.fetchone()
            if sync_row:
                synchronous = sync_row[0]

            # Auto-checkpoint if WAL exceeds 16MB
            if healthy and wal_size > 16 * 1024 * 1024:
                cur.execute("PRAGMA wal_checkpoint(PASSIVE);")
                cp = cur.fetchone()
                checkpoint_status = {"busy": cp[0], "log": cp[1], "checkpointed": cp[2]} if cp else "executed"
                wal_size = os.path.getsize(wal_path) if os.path.exists(wal_path) else 0

            con.close()
        except Exception as exc:
            integrity_msg = str(exc)
            healthy = False

        return {
            "healthy": healthy,
            "status": "ok" if healthy else "error",
            "integrity": integrity_msg,
            "path": db_path,
            "journal_mode": journal_mode,
            "synchronous": synchronous,
            "db_size_bytes": db_size,
            "wal_size_bytes": wal_size,
            "shm_size_bytes": shm_size,
            "snapshot_present": snap_present,
            "snapshot_age_sec": round(snap_age_sec, 1) if snap_age_sec is not None else None,
            "checkpoint_auto": checkpoint_status,
        }

    def maintain_database(self) -> Dict[str, Any]:
        """
        Periodic background maintenance called by daemon maintenance loop.
        Verifies quick_check and checkpoints WAL if > 8MB or every run.
        """
        db_path = self._locate_database_path()
        if not db_path or not os.path.exists(db_path):
            return {"status": "skipped", "reason": "no_db"}

        wal_path = f"{db_path}-wal"
        wal_size = os.path.getsize(wal_path) if os.path.exists(wal_path) else 0
        try:
            con = sqlite3.connect(db_path, timeout=5.0)
            cur = con.cursor()
            cur.execute("PRAGMA quick_check;")
            qc = cur.fetchone()
            healthy = qc and qc[0].lower() == "ok"
            cp_result = None
            if healthy:
                cur.execute("PRAGMA wal_checkpoint(PASSIVE);")
                cp = cur.fetchone()
                cp_result = {"busy": cp[0], "log": cp[1], "checkpointed": cp[2]} if cp else {}
            con.close()
            return {"status": "ok", "healthy": healthy, "checkpoint": cp_result}
        except Exception as exc:
            logger.error(f"Database maintenance exception: {exc}")
            return {"status": "error", "message": str(exc)}

    def _storage_telemetry(self) -> List[Dict[str, Any]]:
        devices: List[Dict[str, Any]] = []
        sys_block = "/sys/block"
        if not os.path.isdir(sys_block):
            return devices

        smartctl_bin = self._which("smartctl")

        for dev in sorted(os.listdir(sys_block)):
            if re.match(r"^(loop|ram|zram|dm-|sr|md)\d*", dev):
                continue

            base = f"{sys_block}/{dev}"
            dev_path = f"/dev/{dev}"

            size_sectors = self._read_first(f"{base}/size")
            size_gb = 0.0
            if size_sectors and size_sectors.isdigit():
                size_gb = round(int(size_sectors) * 512 / (1024 ** 3), 2)

            if size_gb <= 0:
                continue

            rotational_raw = self._read_first(f"{base}/queue/rotational")
            is_rotational = (rotational_raw == "1") if rotational_raw else None
            is_ssd = (rotational_raw == "0") if rotational_raw else None

            model = self._read_first(f"{base}/device/model")
            vendor = self._read_first(f"{base}/device/vendor")
            if not model:
                model = self._read_first(f"{base}/device/device/model")
            model_str = f"{(vendor + ' ') if vendor else ''}{model or dev}".strip()

            if dev.startswith("nvme"):
                transport = "nvme"
            elif dev.startswith("mmcblk"):
                transport = "emmc/sd"
            elif dev.startswith("vd"):
                transport = "virtio"
            elif dev.startswith("xvd"):
                transport = "xen"
            else:
                transport = "sata/scsi"

            io_stat_raw = self._read_first(f"{base}/stat")
            io_stats: Dict[str, Any] = {}
            if io_stat_raw:
                parts = io_stat_raw.split()
                if len(parts) >= 11:
                    try:
                        io_stats = {
                            "reads_completed": int(parts[0]),
                            "read_bytes": int(parts[2]) * 512,
                            "writes_completed": int(parts[4]),
                            "write_bytes": int(parts[6]) * 512,
                            "io_in_flight": int(parts[8]),
                        }
                    except (ValueError, IndexError):
                        pass

            smart_data: Dict[str, Any] = {
                "smart_supported": False,
                "health_status": "OK",
                "passed": True,
                "wear_level_pct": None,
                "temperature_c": None,
                "power_on_hours": None,
                "critical_warning": None,
            }

            if smartctl_bin:
                try:
                    res = self._run_bounded([smartctl_bin, "-j", "-H", "-A", dev_path], timeout=3.0)
                    out = res.get("output", "").strip()
                    if out:
                        json_start = out.find("{")
                        json_end = out.rfind("}")
                        if json_start != -1 and json_end != -1:
                            out = out[json_start:json_end+1]
                        parsed = strict_json_loads(out)
                        smart_status = parsed.get("smart_status", {})
                        passed = smart_status.get("passed")
                        if passed is not None:
                            smart_data["smart_supported"] = True
                            smart_data["passed"] = bool(passed)
                            smart_data["health_status"] = "PASSED" if passed else "FAILED"

                        temp_data = parsed.get("temperature", {})
                        if "current" in temp_data:
                            smart_data["temperature_c"] = temp_data["current"]
                        elif "nvme_smart_health_information_log" in parsed:
                            nvme_log = parsed["nvme_smart_health_information_log"]
                            if "temperature" in nvme_log:
                                smart_data["temperature_c"] = nvme_log["temperature"]
                            if "percentage_used" in nvme_log:
                                smart_data["wear_level_pct"] = nvme_log["percentage_used"]
                            if "critical_warning" in nvme_log:
                                smart_data["critical_warning"] = nvme_log["critical_warning"]
                            if "power_on_hours" in nvme_log:
                                smart_data["power_on_hours"] = nvme_log["power_on_hours"]

                        if "power_on_time" in parsed and "hours" in parsed["power_on_time"]:
                            smart_data["power_on_hours"] = parsed["power_on_time"]["hours"]

                        ata_table = parsed.get("ata_smart_attributes", {}).get("table", [])
                        for attr in ata_table:
                            attr_id = attr.get("id")
                            if attr_id in (231, 233, 177, 169) and smart_data["wear_level_pct"] is None:
                                val = attr.get("value")
                                if isinstance(val, (int, float)):
                                    smart_data["wear_level_pct"] = max(0, 100 - int(val))
                except Exception as exc:
                    logger.debug(f"smartctl probe on {dev_path} failed: {exc}")

            dev_info = {
                "device": dev,
                "path": dev_path,
                "model": model_str,
                "size_gb": size_gb,
                "transport": transport,
                "is_ssd": is_ssd,
                "is_rotational": is_rotational,
                "io_stats": io_stats,
                **smart_data
            }
            devices.append(dev_info)

        return devices

    async def rpc_database_health(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Inspects SQLite database integrity, WAL size, and snapshot freshness."""
        health = self._database_health()
        return {"success": health.get("healthy", False), "status": health.get("status", "ok"), "database": health}

    async def rpc_database_checkpoint(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Executes an on-demand SQLite WAL checkpoint (PASSIVE, FULL, RESTART, TRUNCATE)."""
        mode = str(params.get("mode", "TRUNCATE")).upper()
        if mode not in ("PASSIVE", "FULL", "RESTART", "TRUNCATE"):
            mode = "TRUNCATE"
        db_path = self._locate_database_path()
        if not db_path or not os.path.exists(db_path):
            return {"success": False, "status": "error", "message": "Database file not found"}

        try:
            con = sqlite3.connect(db_path, timeout=5.0)
            cur = con.cursor()
            cur.execute(f"PRAGMA wal_checkpoint({mode});")
            res = cur.fetchone()
            cur.execute("PRAGMA quick_check;")
            qc = cur.fetchone()
            con.close()

            wal_path = f"{db_path}-wal"
            wal_size = os.path.getsize(wal_path) if os.path.exists(wal_path) else 0

            return {
                "success": True,
                "status": "ok",
                "mode": mode,
                "checkpoint": {"busy": res[0], "log": res[1], "checkpointed": res[2]} if res else {},
                "integrity": qc[0] if qc else "unknown",
                "wal_size_bytes": wal_size,
            }
        except Exception as exc:
            return {"success": False, "status": "error", "message": str(exc)}

    async def rpc_storage_health(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Telemetry and SMART health status for all system storage devices."""
        devices = self._storage_telemetry()
        return {"success": True, "status": "ok", "count": len(devices), "devices": devices}

    async def rpc_system_package_list(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Lists installed Debian packages on the host appliance."""
        packages = []
        dpkg_query = self._which("dpkg-query")
        if dpkg_query:
            res = self._run_bounded([dpkg_query, "-W", "-f=${Package}\t${Version}\t${Architecture}\n"], timeout=10.0)
            if res["rc"] == 0 and res["output"]:
                for line in res["output"].splitlines():
                    parts = line.strip().split("\t")
                    if len(parts) >= 2:
                        packages.append({
                            "name": parts[0],
                            "version": parts[1],
                            "arch": parts[2] if len(parts) > 2 else "amd64",
                        })
        return {"success": True, "status": "ok", "count": len(packages), "packages": packages}

    async def rpc_system_health(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Real resource and link telemetry for the dashboard (read-only).

        Every value is read from /proc and /sys at call time. A value the kernel
        does not expose is returned as null — the UI shows "—", never a
        made-up number.
        """
        snap = self._health_snapshot()
        snap.update({"success": True, "status": "ok"})
        return snap

    async def rpc_system_services_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Live systemd state for every unit the management plane manages."""
        services = {sid: self._unit_snapshot(unit) for sid, unit in SERVICE_UNITS.items()}
        return {"success": True, "status": "ok", "services": services}

    async def rpc_system_service_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        sid = str(params.get("service") or "")
        unit = SERVICE_UNITS.get(sid) or LOG_UNITS_EXTRA.get(sid)
        if unit is None:
            return {"success": False, "status": "error", "message": f"refusing unknown service: {sid}"}
        return {"success": True, "status": "ok", "unit": unit,
                "logs": self._journal(unit, self._lines_param(params))}

    async def rpc_system_rpc_methods(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """The daemon's own method catalogue with each method's class."""
        methods = []
        for attr in sorted(n for n in dir(self) if n.startswith("rpc_")):
            methods.append({"handler": attr[4:], "class": rpc_method_class(attr[4:])})
        return {"success": True, "status": "ok", "count": len(methods), "methods": methods}

    async def rpc_system_commit_watchdog(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Arm the auto-rollback watchdog for a staged transaction."""
        try:
            seconds = int(params.get("watchdog_sec") or ROLLBACK_TIMEOUT)
        except (TypeError, ValueError):
            seconds = ROLLBACK_TIMEOUT
        seconds = max(15, min(seconds, 600))
        self.tx_mgr.start_rollback_timer(self.loop, seconds)
        return {"success": True, "status": "pending_confirm", "watchdog_sec": seconds,
                "transaction_id": str(params.get("transaction_id") or "")[:64]}

    async def rpc_system_confirm(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_system_commit(params)

    # ---------------------------------------------------------- cron
    def _cron_line_refusal(self, line: str) -> Optional[str]:
        stripped = line.strip()
        if stripped == "" or stripped.startswith("#"):
            return None
        if re.match(r"^[A-Z_]+=", stripped):
            return None if re.match(r"^(SHELL|PATH|MAILTO)=[A-Za-z0-9/:._-]*$", stripped) else \
                "only SHELL, PATH and MAILTO may be set"
        parts = stripped.split(None, 6)
        if len(parts) < 7:
            return "a job needs five schedule fields, a user and a command"
        for field in parts[:5]:
            if not CRON_FIELD_RE.match(field):
                return f"invalid schedule field: {field}"
        if parts[5] not in CRON_USERS:
            return f"jobs may only run as {', '.join(CRON_USERS)}"
        return self._cron_command_refusal(parts[6])

    @staticmethod
    def _cron_command_refusal(command: str) -> Optional[str]:
        command = command.strip()
        if not CRON_COMMAND_RE.match(command):
            return "the command contains characters that are not allowed (no shell syntax)"
        if command.startswith("cd ") or ".." in command:
            return "the command may not change directory or use relative parent paths"
        if not any(command.startswith(p) or command == p.strip() for p in CRON_ALLOWED_PREFIXES):
            return ("the command must be a `php spark` task or an executable under "
                    "/usr/local/bin, /usr/local/sbin or a whitelisted system tool")
        return None

    async def rpc_system_crontab_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        crontab = params.get("crontab")
        if not isinstance(crontab, str):
            return {"success": False, "status": "error", "message": "crontab must be a string"}
        out_lines = ["# Managed by Micron OS — edits are overwritten on the next sync",
                     "SHELL=/bin/sh", "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"]
        refused = []
        for raw in crontab.splitlines():
            reason = self._cron_line_refusal(raw)
            if reason:
                refused.append({"line": raw[:200], "reason": reason})
                continue
            stripped = raw.strip()
            parts = stripped.split(None, 6)
            if len(parts) == 7 and parts[6].startswith("php spark "):
                parts[6] = f"cd {CRON_APP_ROOT} && /usr/bin/php spark " + parts[6][len("php spark "):]
                stripped = " ".join(parts)
            out_lines.append(stripped)
        if refused:
            return {"success": False, "status": "error", "refused": refused,
                    "message": f"{len(refused)} cron line(s) were refused; nothing was changed"}
        result = self._apply_managed_config("cron", "\n".join(out_lines) + "\n")
        result["jobs"] = sum(1 for l in out_lines if l and not l.startswith("#") and "=" not in l.split()[0])
        return result

    async def rpc_system_cron_run(self, params: Dict[str, Any]) -> Dict[str, Any]:
        command = str(params.get("command") or "").strip()
        reason = self._cron_command_refusal(command)
        if reason:
            return {"success": False, "status": "error", "message": reason}
        argv = command.split()
        cwd = None
        if argv[:2] == ["php", "spark"]:
            argv = ["/usr/bin/php", os.path.join(CRON_APP_ROOT, "spark")] + argv[2:]
            cwd = CRON_APP_ROOT
        if self._which(argv[0]) is None and not os.path.exists(argv[0]):
            return {"success": False, "status": "error", "returncode": 127,
                    "message": f"{argv[0]} is not installed on this appliance"}
        started = time.time()
        try:
            res = subprocess.run(argv, capture_output=True, text=True, timeout=300, cwd=cwd)
            rc, output = res.returncode, (res.stdout or "") + (res.stderr or "")
        except subprocess.TimeoutExpired:
            rc, output = 124, "the task did not finish within 300 s and was stopped"
        except OSError as exc:
            rc, output = 127, str(exc)
        return {"success": rc == 0, "status": "ok" if rc == 0 else "error", "returncode": rc,
                "output": output[-16384:], "duration_ms": int((time.time() - started) * 1000)}

    async def rpc_system_cron_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        text = self._journal("cron", self._lines_param(params))
        logs = [l for l in text.splitlines() if l.strip() and not l.startswith("-- ")]
        return {"success": True, "status": "ok", "logs": logs}

    # ---------------------------------------------------------- DNS
    async def rpc_dns_config_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply the compiled Unbound include and reload Unbound."""
        if params.get("config") is None:
            res = self._run_bounded(["systemctl", "reload-or-restart", "unbound"], timeout=60)
            return {"success": res["rc"] == 0, "status": "applied" if res["rc"] == 0 else "error",
                    "message": "" if res["rc"] == 0 else res["output"].strip()[:300],
                    "warnings": ["no configuration supplied; Unbound was reloaded as-is"]}
        return self._apply_managed_config("unbound", params.get("config"))

    async def rpc_dns_hosts_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_dns_config_reload(params)

    async def rpc_dns_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        snap = self._unit_snapshot("unbound")
        snap.update({"success": True, "status": "ok"})
        return snap

    # ---------------------------------------------------------- FRR
    async def _frr_apply(self, params: Dict[str, Any], protocol: str) -> Dict[str, Any]:
        result = self._apply_managed_config("frr", params.get("config"))
        result["protocol"] = protocol
        return result

    async def rpc_frr_bgp_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._frr_apply(params, "bgp")

    async def rpc_frr_ospf_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._frr_apply(params, "ospf")

    async def rpc_frr_rip_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._frr_apply(params, "rip")

    async def rpc_frr_isis_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._frr_apply(params, "isis")

    async def rpc_frr_bfd_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._frr_apply(params, "bfd")

    async def rpc_frr_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read live FRR daemon state and vtysh summary."""
        snap = self._unit_snapshot("frr")
        running = snap.get("running", False)
        version = ""
        uptime = snap.get("since", "")
        router_id = ""
        bgp_as = ""

        if running:
            res = self._run_bounded(["vtysh", "-c", "show version"], timeout=3)
            if res["rc"] == 0 and res["output"]:
                first_line = res["output"].splitlines()[0]
                version = first_line.strip()
            res_bgp = self._run_bounded(["vtysh", "-c", "show ip bgp summary json"], timeout=3)
            if res_bgp["rc"] == 0 and res_bgp["output"]:
                try:
                    data = strict_json_loads(res_bgp["output"])
                    router_id = str(data.get("routerId") or "")
                    bgp_as = str(data.get("as") or "")
                except Exception:
                    pass

        return {
            "success": True,
            "status": "ok",
            "running": running,
            "enabled": snap.get("enabled", False),
            "version": version,
            "uptime": uptime,
            "bgp_as": bgp_as,
            "router_id": router_id,
            "unit": snap,
        }

    async def rpc_ospf_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read live OSPF state through fixed, read-only vtysh commands."""
        commands = {
            "general": "show ip ospf",
            "neighbors": "show ip ospf neighbor detail",
            "routes": "show ip ospf route",
            "database": "show ip ospf database",
            "router_database": "show ip ospf database router",
            "interfaces": "show ip ospf interface",
        }
        sections = {}
        for key, command in commands.items():
            # 4 s each keeps the six queries inside the web UI's 30 s IPC timeout.
            result = self._run_bounded(["vtysh", "-c", command], timeout=4, cap=131072)
            output = str(result.get("output") or "").strip()
            if not output:
                if result.get("missing"):
                    output = "vtysh is unavailable on this system."
                elif result.get("timed_out"):
                    output = "Command timed out while reading OSPF state."
                elif result.get("rc", 1) != 0:
                    output = "No OSPF data is available; FRR may be stopped or OSPF may not be configured."
                else:
                    output = "No OSPF data returned."
            sections[key] = {
                "command": command,
                "available": result.get("rc", 1) == 0,
                "output": output,
            }

        process = self._run_bounded(
            ["ps", "-C", "ospfd", "-o", "%cpu=,%mem=,rss=,pid="], timeout=5, cap=4096
        )
        cpu_total = 0.0
        mem_total = 0.0
        rss_total = 0
        pids = []
        if process.get("rc") == 0:
            for line in str(process.get("output") or "").splitlines():
                fields = line.split()
                if len(fields) < 4:
                    continue
                try:
                    cpu_total += float(fields[0])
                    mem_total += float(fields[1])
                    rss_total += int(fields[2])
                    pids.append(int(fields[3]))
                except ValueError:
                    continue

        if pids:
            cpu_output = f"ospfd CPU average: {cpu_total:.1f}%\nProcesses: {len(pids)}"
            memory_output = f"Resident memory: {rss_total / 1024:.1f} MiB\nProcess memory: {mem_total:.1f}%"
            process_available = True
        else:
            cpu_output = "ospfd is not running; CPU usage is unavailable."
            memory_output = "ospfd is not running; memory usage is unavailable."
            process_available = False

        return {
            "success": True,
            "status": "ok",
            "sections": sections,
            "resources": {
                "available": process_available,
                "cpu_percent": round(cpu_total, 1) if process_available else None,
                "memory_percent": round(mem_total, 1) if process_available else None,
                "memory_bytes": rss_total * 1024 if process_available else None,
                "pids": pids,
                "cpu_output": cpu_output,
                "memory_output": memory_output,
            },
        }

    async def rpc_network_vrf_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Reconcile Linux VRF devices with the configured list."""
        vrfs = params.get("vrfs")
        if not isinstance(vrfs, list):
            return {"success": False, "status": "error", "message": "vrfs must be a list"}
        steps, warnings, wanted = [], [], {}
        for v in vrfs[:64]:
            if not isinstance(v, dict):
                continue
            name = str(v.get("name") or "")
            try:
                table = int(v.get("table_id") or 0)
            except (TypeError, ValueError):
                table = 0
            if not VRF_NAME_RE.match(name) or not 1 <= table <= 4294967294 or table in (253, 254, 255):
                warnings.append(f"skipped invalid VRF {name!r} (table {table})")
                continue
            members = [m for m in (v.get("interfaces") or []) if isinstance(m, str) and IF_NAME_RE.match(m)]
            wanted[name] = (table, members)
        existing = set()
        res = self._run_bounded([IP_BIN, "-j", "link", "show", "type", "vrf"], timeout=5)
        try:
            for link in strict_json_loads(res["output"] or "[]"):
                existing.add(link.get("ifname"))
        except ValueError:
            pass
        for name in sorted(existing - set(wanted)):
            if name:
                r = self._run_bounded([IP_BIN, "link", "del", name], timeout=5)
                steps.append(f"ip link del {name} rc={r['rc']}")
        for name, (table, members) in wanted.items():
            if name not in existing:
                r = self._run_bounded([IP_BIN, "link", "add", name, "type", "vrf", "table", str(table)], timeout=5)
                steps.append(f"ip link add {name} type vrf table {table} rc={r['rc']}")
                if r["rc"] != 0:
                    warnings.append(r["output"].strip()[:200])
                    continue
            self._run_bounded([IP_BIN, "link", "set", name, "up"], timeout=5)
            for m in members:
                r = self._run_bounded([IP_BIN, "link", "set", m, "master", name], timeout=5)
                steps.append(f"ip link set {m} master {name} rc={r['rc']}")
        return {"success": not any("rc=" in s and not s.endswith("rc=0") for s in steps),
                "status": "applied", "vrfs": len(wanted), "steps": steps, "warnings": warnings}

    async def rpc_network_multiwan_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Gateway changes take effect through the policy-routing apply."""
        if params.get("rules") is None and params.get("routes") is None and not params.get("ruleset"):
            return {"success": False, "status": "error",
                    "message": "no gateway policy was supplied — nothing was changed"}
        return await self.rpc_routing_apply(params)

    # ---------------------------------------------------------- HA
    async def rpc_ha_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._apply_managed_config("keepalived", params.get("config"))

    async def rpc_ha_failover(self, params: Dict[str, Any]) -> Dict[str, Any]:
        target = str(params.get("target") or "").lower()
        if target not in ("master", "backup"):
            return {"success": False, "status": "error", "message": "target must be master or backup"}
        result = self._apply_managed_config("keepalived", params.get("config"))
        result["target"] = target
        return result

    async def rpc_ha_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        keep = self._unit_snapshot("keepalived")
        ctd = self._unit_snapshot("conntrackd")
        state = "UNKNOWN"
        for path in ("/run/keepalived/state", "/var/run/keepalived.state", "/tmp/keepalived.state"):
            raw = self._read_first(path)
            if raw:
                state = raw.split()[0].upper()
                break
        if not keep["running"]:
            state = "STOPPED"
        return {"success": True, "status": "ok", "state": state, "keepalived": keep,
                "conntrackd": ctd, "vrrp_instances": [],
                "config_present": os.path.exists(MANAGED_CONFIGS["keepalived"]["path"])}

    # ---------------------------------------------------------- IDS
    async def rpc_ids_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        files = params.get("files")
        if not isinstance(files, dict) or not files:
            return {"success": False, "status": "error",
                    "message": "no Suricata configuration was supplied — nothing was changed"}
        steps, warnings = [], []
        rules = files.get("rules")
        if isinstance(rules, str):
            r = self._apply_managed_config("suricata-rules", rules, reload=False)
            if not r["success"]:
                return r
            steps += r["steps"]
        result = self._apply_managed_config("suricata", files.get("yaml"))
        result["steps"] = steps + result.get("steps", [])
        result["warnings"] = warnings + result.get("warnings", [])
        return result

    async def rpc_ids_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        snap = self._unit_snapshot("suricata")
        stats = {}
        eve = "/var/log/suricata/stats.log"
        if os.path.exists(eve):
            stats["stats_log"] = eve
        snap.update({"success": True, "status": "ok", "engine": "Suricata",
                     "installed": snap["installed"] or self._which("suricata") is not None, **stats})
        return snap

    # ---------------------------------------------------------- QoS
    async def rpc_qos_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        script = params.get("script")
        if isinstance(script, str):
            for line in script.splitlines():
                s = line.strip()
                if not s or s.startswith("#"):
                    continue
                if not re.match(r"^(qdisc|class|filter) (add|del|replace|change) dev [A-Za-z0-9._-]{1,15}( [A-Za-z0-9:./_-]+)*$", s):
                    return {"success": False, "status": "error",
                            "message": f"refusing tc batch line: {s[:120]}"}
        return self._apply_managed_config("qos", script)

    async def rpc_qos_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = self._run_bounded(["tc", "-s", "-j", "qdisc", "show"], timeout=5)
        qdiscs = []
        try:
            qdiscs = strict_json_loads(res["output"]) if res["rc"] == 0 else []
        except ValueError:
            qdiscs = []
        shaped = [q for q in qdiscs if isinstance(q, dict) and q.get("kind") in ("cake", "fq_codel", "htb", "hfsc")
                  and q.get("root")]
        return {"success": True, "status": "ok", "available": res["rc"] == 0,
                "qdiscs": qdiscs[:64], "shaped_interfaces": sorted({q.get("dev") for q in shaped if q.get("dev")})}

    # ---------------------------------------------------------- DPI
    async def rpc_dpi_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        steps, warnings = [], []
        snippet = params.get("nftables_snippet")
        if isinstance(snippet, str) and snippet.strip():
            with tempfile.NamedTemporaryFile("w", suffix=".nft", delete=False) as tmp:
                tmp.write(snippet)
                tmp_path = tmp.name
            try:
                chk = self._run_bounded(["nft", "-c", "-f", tmp_path], timeout=20)
                steps.append(f"nft -c rc={chk['rc']}")
                if chk["rc"] != 0:
                    return {"success": False, "status": "error", "steps": steps,
                            "message": "the DPI nftables snippet was rejected: " + chk["output"].strip()[:300]}
                load = self._run_bounded(["nft", "-f", tmp_path], timeout=20)
                steps.append(f"nft -f rc={load['rc']}")
                if load["rc"] != 0:
                    return {"success": False, "status": "error", "steps": steps,
                            "message": "the DPI nftables snippet failed to load: " + load["output"].strip()[:300]}
            finally:
                try:
                    os.remove(tmp_path)
                except OSError:
                    pass
        conf = params.get("ndpi_config")
        if isinstance(conf, str) and conf.strip():
            r = self._apply_managed_config("ndpi", conf, reload=False)
            steps += r.get("steps", [])
            warnings += r.get("warnings", [])
            if not r["success"]:
                return r
        if not steps:
            return {"success": False, "status": "error", "message": "no DPI configuration was supplied"}
        if not self._dpi_engine_present():
            warnings.append("no nDPI engine (xt_ndpi kernel module or ndpid) is installed; "
                            "application signatures are stored but only port/host rules enforce")
        return {"success": True, "status": "applied", "steps": steps, "warnings": warnings}

    @staticmethod
    def _dpi_engine_present() -> bool:
        return os.path.exists("/proc/net/xt_ndpi") or shutil.which("ndpid") is not None

    async def rpc_dpi_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        present = self._dpi_engine_present()
        return {"success": True, "status": "ok", "running": present, "installed": present,
                "engine": "nDPI" if present else "not installed", "mode": "inline_block",
                "active_flows": None, "total_analyzed": None,
                "message": "" if present else "No nDPI engine is installed on this appliance."}

    async def rpc_dpi_stats(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {"success": True, "status": "ok", "stats": [], "available": self._dpi_engine_present()}

    async def rpc_dpi_live_flows(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {"success": True, "status": "ok", "flows": [], "available": self._dpi_engine_present()}

    # ---------------------------------------------------------- SNMP
    async def rpc_snmp_reconfigure(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._apply_managed_config("snmpd", params.get("config"))

    async def rpc_snmp_sync_traps(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._apply_managed_config("snmpd", params.get("config"))

    async def rpc_snmp_sync_users(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._apply_managed_config("snmpd", params.get("config"))

    async def rpc_snmp_get_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        text = self._journal("snmpd", self._lines_param(params, 40))
        return {"success": True, "status": "ok",
                "logs": [l for l in text.splitlines() if l.strip() and not l.startswith("-- ")]}

    async def rpc_snmp_test_query(self, params: Dict[str, Any]) -> Dict[str, Any]:
        oid = str(params.get("oid") or "1.3.6.1.2.1.1").strip()
        community = str(params.get("community") or "").strip()
        version = str(params.get("version") or "2c").strip().lower().lstrip("v")
        if not SNMP_OID_RE.match(oid):
            return {"success": False, "status": "error", "message": "OID must be numeric, e.g. 1.3.6.1.2.1.1"}
        if version not in ("1", "2c"):
            return {"success": False, "status": "error", "message": "only SNMP v1/v2c can be queried here"}
        if not SNMP_COMMUNITY_RE.match(community):
            return {"success": False, "status": "error", "message": "community string is not valid"}
        if self._which("snmpwalk") is None:
            return {"success": False, "status": "error", "message": "snmpwalk (snmp package) is not installed"}
        res = self._run_bounded(["snmpwalk", "-v", version, "-c", community, "-t", "2", "-r", "1",
                                 "127.0.0.1", oid], timeout=15, cap=32768)
        return {"success": res["rc"] == 0, "status": "ok" if res["rc"] == 0 else "error",
                "output": res["output"], "returncode": res["rc"]}

    async def rpc_snmp_send_test_trap(self, params: Dict[str, Any]) -> Dict[str, Any]:
        receiver = str(params.get("receiver") or "").strip()
        community = str(params.get("community") or "public").strip()
        oid = str(params.get("oid") or "1.3.6.1.4.1.59999.0.1").strip()
        try:
            port = int(params.get("port") or 162)
        except (TypeError, ValueError):
            port = 0
        if self._diag_ip_literal(receiver) is None:
            return {"success": False, "status": "error", "message": "trap receiver must be an IP address"}
        if not 1 <= port <= 65535 or not SNMP_OID_RE.match(oid) or not SNMP_COMMUNITY_RE.match(community):
            return {"success": False, "status": "error", "message": "port, OID or community is not valid"}
        if self._which("snmptrap") is None:
            return {"success": False, "status": "error", "message": "snmptrap (snmp package) is not installed"}
        msg = re.sub(r"[^A-Za-z0-9 .,:_-]", "", str(params.get("msg") or "Micron OS test trap"))[:120]
        target = f"[{receiver}]:{port}" if ":" in receiver else f"{receiver}:{port}"
        res = self._run_bounded(["snmptrap", "-v", "2c", "-c", community, target, "", oid,
                                 "1.3.6.1.2.1.1.1.0", "s", msg], timeout=10)
        return {"success": res["rc"] == 0, "status": "ok" if res["rc"] == 0 else "error",
                "message": "trap sent" if res["rc"] == 0 else res["output"].strip()[:300]}

    # ---------------------------------------------------------- SIEM
    async def rpc_siem_reconfigure(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._apply_managed_config("rsyslog-siem", params.get("config"))

    async def rpc_siem_get_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        lines = self._lines_param(params, 30, 200)
        res = self._run_bounded(["journalctl", "-n", str(lines), "--no-pager", "-o", "json"], timeout=10,
                                cap=262144)
        events = []
        for raw in res["output"].splitlines():
            try:
                rec = strict_json_loads(raw)
            except ValueError:
                continue
            if not isinstance(rec, dict):
                continue
            try:
                ts = datetime.datetime.fromtimestamp(int(rec.get("__REALTIME_TIMESTAMP", "0")) / 1e6)
                stamp = ts.strftime("%Y-%m-%d %H:%M:%S")
            except (ValueError, OSError):
                stamp = ""
            msg = rec.get("MESSAGE")
            events.append({"time": stamp, "host": rec.get("_HOSTNAME", ""),
                           "source": rec.get("SYSLOG_IDENTIFIER") or rec.get("_COMM") or "",
                           "priority": rec.get("PRIORITY", ""),
                           "message": msg if isinstance(msg, str) else ""})
        return {"success": True, "status": "ok", "events": events}

    async def rpc_siem_send_test(self, params: Dict[str, Any]) -> Dict[str, Any]:
        host = str(params.get("host") or "").strip()
        proto = str(params.get("proto") or "udp").strip().lower()
        try:
            port = int(params.get("port") or 514)
        except (TypeError, ValueError):
            port = 0
        if not self._diag_target_ok(host) or host.startswith("-"):
            return {"success": False, "status": "error", "message": "collector host is not valid"}
        if proto not in SIEM_PROTOS or not 1 <= port <= 65535:
            return {"success": False, "status": "error", "message": "protocol or port is not valid"}
        payload = str(params.get("payload") or "<14>micron-os: SIEM connectivity test")[:2048]
        try:
            if proto == "udp":
                info = socket.getaddrinfo(host, port, type=socket.SOCK_DGRAM)[0]
                with socket.socket(info[0], socket.SOCK_DGRAM) as s:
                    s.settimeout(3)
                    s.sendto(payload.encode("utf-8"), info[4])
            else:
                with socket.create_connection((host, port), timeout=3) as s:
                    s.sendall(payload.encode("utf-8") + b"\n")
        except OSError as exc:
            return {"success": False, "status": "error", "message": f"could not reach {host}:{port}/{proto}: {exc}"}
        return {"success": True, "status": "ok",
                "message": f"test event sent to {host}:{port}/{proto}" + (" (UDP has no delivery receipt)" if proto == "udp" else "")}

    # ---------------------------------------------------------- OpenVPN
    async def rpc_vpn_openvpn_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return self._apply_managed_config("openvpn", params.get("config"))

    async def rpc_vpn_openvpn_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        snap = self._unit_snapshot("openvpn@server")
        clients = []
        for path in OPENVPN_STATUS_FILES:
            raw = self._read_first(path)
            if not raw:
                continue
            for line in raw.splitlines():
                cols = line.split(",")
                if cols[0] == "CLIENT_LIST" and len(cols) >= 8:
                    clients.append({"common_name": cols[1], "real_address": cols[2],
                                    "virtual_address": cols[3], "bytes_received": cols[5],
                                    "bytes_sent": cols[6], "connected_since": cols[7]})
            break
        snap.update({"success": True, "status": "ok", "clients": clients, "client_count": len(clients)})
        return snap

    # ---------------------------------------------------------- Web filter AV
    async def rpc_webfilter_av_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        clam = self._unit_snapshot("clamav-daemon")
        icap = self._unit_snapshot("c-icap")
        version = None
        if self._which("clamdscan"):
            v = self._run_bounded(["clamdscan", "--version"], timeout=10)
            version = v["output"].strip() if v["rc"] == 0 else None
        return {"success": True, "status": "ok", "running": clam["running"], "engine": "ClamAV",
                "icap_running": icap["running"], "definitions": version,
                "installed": clam["installed"]}

    async def rpc_webfilter_clamav_update(self, params: Dict[str, Any]) -> Dict[str, Any]:
        if self._which("freshclam") is None:
            return {"success": False, "status": "error", "message": "freshclam is not installed"}
        res = self._run_bounded(["freshclam", "--stdout", "--no-warnings"], timeout=240)
        ok = res["rc"] in (0, 1)
        tail = res["output"].strip().splitlines()
        return {"success": ok, "status": "ok" if ok else "error",
                "output": res["output"][-8192:], "returncode": res["rc"],
                "message": "signature databases are current" if ok
                else "freshclam failed: " + (tail[-1] if tail else f"exit {res['rc']}")}

    # ---------------------------------------------------------- Certificates
    async def rpc_cert_install(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Install a certificate (PEM) for a service and reload what serves it.

        service "webgui" (default) writes /etc/micron/ssl/webgui.{crt,key};
        service "ztna" writes the Clientless Gateway pair nginx reads, and with
        self_signed=true replaces it with a fresh self-signed one for `domain`.
        """
        service = str(params.get("service") or "webgui")
        if service not in ("webgui", "ztna"):
            return {"success": False, "status": "error", "message": "unknown certificate service"}
        if service == "ztna" and params.get("self_signed") is True:
            domain = str(params.get("domain") or "")
            err = _ensure_ztna_certificate(domain, force=True)
            if err:
                return {"success": False, "status": "error", "message": err}
            return {"success": True, "status": "applied",
                    "steps": [f"generated a self-signed certificate in {ZTNA_CERT_PATH}"],
                    "services_reloaded": self._ztna_nginx_reload()}
        cert = params.get("cert_pem")
        key = params.get("key_pem")
        if not isinstance(cert, str) or "BEGIN CERTIFICATE" not in cert:
            return {"success": False, "status": "error", "message": "a PEM certificate is required"}
        if not isinstance(key, str) or "PRIVATE KEY" not in key:
            return {"success": False, "status": "error", "message": "the matching PEM private key is required"}
        if len(cert) > 65536 or len(key) > 65536:
            return {"success": False, "status": "error", "message": "certificate or key is too large"}
        steps = []
        if service == "ztna":
            crt_path, key_path = ZTNA_CERT_PATH, ZTNA_KEY_PATH
        else:
            crt_path = os.path.join(WEBGUI_CERT_DIR, "webgui.crt")
            key_path = os.path.join(WEBGUI_CERT_DIR, "webgui.key")
        if self._which("openssl"):
            with tempfile.TemporaryDirectory() as td:
                c, k = os.path.join(td, "c.pem"), os.path.join(td, "k.pem")
                with open(c, "w") as fh:
                    fh.write(cert)
                with open(k, "w") as fh:
                    fh.write(key)
                os.chmod(k, 0o600)
                pc = self._run_bounded(["openssl", "x509", "-noout", "-pubkey", "-in", c], timeout=10)
                pk = self._run_bounded(["openssl", "pkey", "-pubout", "-in", k], timeout=10)
                if pc["rc"] != 0 or pk["rc"] != 0 or pc["output"].strip() != pk["output"].strip():
                    return {"success": False, "status": "error",
                            "message": "the private key does not match the certificate"}
                steps.append("certificate and key match")
        try:
            os.makedirs(os.path.dirname(crt_path), mode=0o700, exist_ok=True)
            safe_atomic_write(crt_path, cert, mode=0o644)
            safe_atomic_write(key_path, key, mode=0o600)
            steps.append(f"wrote {crt_path} and {key_path}")
        except OSError as exc:
            return {"success": False, "status": "error", "message": f"could not write certificate: {exc}"}
        reloaded = self._ztna_nginx_reload() if service == "ztna" else self._cert_reload_units()
        return {"success": True, "status": "applied", "steps": steps, "services_reloaded": reloaded}

    def _ztna_nginx_reload(self) -> list:
        """Reload nginx (the gateway) only if it is running and its config is valid."""
        if not self._unit_snapshot("nginx")["running"]:
            return []
        if self._run_bounded(["nginx", "-t"], timeout=10)["rc"] != 0:
            return []
        r = self._run_bounded(["systemctl", "reload", "nginx"], timeout=30)
        return ["nginx"] if r["rc"] == 0 else []

    def _cert_reload_units(self) -> list:
        reloaded = []
        for unit in CERT_RELOAD_UNITS:
            if self._unit_snapshot(unit)["running"]:
                r = self._run_bounded(["systemctl", "reload-or-restart", unit], timeout=60)
                if r["rc"] == 0:
                    reloaded.append(unit)
        return reloaded

    async def rpc_cert_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {"success": True, "status": "applied", "services_reloaded": self._cert_reload_units()}

    # ---------------------------------------------------------- Firewall counters
    async def rpc_firewall_list_rules(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Live nftables rules with their hit counters (`nft -j list ruleset`)."""
        res = self._run_bounded(["nft", "-j", "list", "ruleset"], timeout=10, cap=4 * 1024 * 1024)
        if res["rc"] != 0:
            return {"success": False, "status": "error", "rules": [],
                    "message": res["output"].strip()[:300] or "nft is not available"}
        try:
            doc = strict_json_loads(res["output"])
        except ValueError:
            return {"success": False, "status": "error", "rules": [], "message": "nft returned invalid JSON"}
        rules = []
        for item in (doc.get("nftables", []) if isinstance(doc, dict) else []):
            rule = item.get("rule") if isinstance(item, dict) else None
            if not isinstance(rule, dict):
                continue
            packets = bytes_ = None
            verdict = None
            for expr in rule.get("expr", []) or []:
                if isinstance(expr, dict) and "counter" in expr and isinstance(expr["counter"], dict):
                    packets = expr["counter"].get("packets")
                    bytes_ = expr["counter"].get("bytes")
                for v in ("accept", "drop", "reject", "jump", "goto", "return"):
                    if isinstance(expr, dict) and v in expr:
                        verdict = v
            rules.append({"family": rule.get("family"), "table": rule.get("table"),
                          "chain": rule.get("chain"), "handle": rule.get("handle"),
                          "comment": rule.get("comment", ""), "packets": packets, "bytes": bytes_,
                          "verdict": verdict})
            if len(rules) >= 5000:
                break
        return {"success": True, "status": "ok", "rules": rules, "count": len(rules)}

    # ---------------------------------------------------------- Conntrack
    def _conntrack_refusal(self, value: str, what: str) -> Optional[str]:
        if self._diag_ip_literal(value) is None:
            return f"{what} must be an IP address"
        return None

    async def rpc_diag_conntrack_kill(self, params: Dict[str, Any]) -> Dict[str, Any]:
        src = str(params.get("src") or "").strip()
        dst = str(params.get("dst") or "").strip()
        proto = str(params.get("proto") or "tcp").strip().lower()
        for value, what in ((src, "source"), (dst, "destination")):
            refusal = self._conntrack_refusal(value, what)
            if refusal:
                return {"success": False, "status": "error", "message": refusal}
        if proto not in CONNTRACK_PROTOS:
            return {"success": False, "status": "error", "message": "protocol is not valid"}
        argv = [CONNTRACK_BIN, "-D", "-p", proto, "-s", src, "-d", dst]
        for key, flag in (("sport", "--sport"), ("dport", "--dport")):
            raw = params.get(key)
            if raw in (None, "", 0, "0") or proto not in ("tcp", "udp", "sctp"):
                continue
            try:
                port = int(raw)
            except (TypeError, ValueError):
                return {"success": False, "status": "error", "message": f"{key} must be a port number"}
            if not 1 <= port <= 65535:
                return {"success": False, "status": "error", "message": f"{key} must be 1-65535"}
            argv += [flag, str(port)]
        res = self._run_bounded(argv, timeout=10)
        killed = self._conntrack_deleted(res["output"])
        ok = res["rc"] == 0 or killed > 0
        return {"success": ok, "status": "applied" if ok else "error", "killed_count": killed,
                "message": f"{killed} state(s) removed" if ok else
                ("no matching state" if "0 flow entries" in res["output"] else res["output"].strip()[:200])}

    @staticmethod
    def _conntrack_deleted(output: str) -> int:
        m = re.search(r"(\d+) flow entries have been deleted", output)
        return int(m.group(1)) if m else 0

    async def rpc_diag_conntrack_kill_host(self, params: Dict[str, Any]) -> Dict[str, Any]:
        host = str(params.get("host") or "").strip()
        refusal = self._conntrack_refusal(host, "host")
        if refusal:
            return {"success": False, "status": "error", "message": refusal}
        total = 0
        for flag in ("-s", "-d"):
            res = self._run_bounded([CONNTRACK_BIN, "-D", flag, host], timeout=15)
            total += self._conntrack_deleted(res["output"])
        return {"success": True, "status": "applied", "killed_count": total,
                "message": f"{total} state(s) for {host} removed"}


# =============================================================================
# Network & security utility toolkit (docs/MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md)
# =============================================================================
# Safety model, enforced here (the daemon), not only in the UI:
#   * argv lists only, flags are server-side constants chosen by an enum;
#   * no target may start with "-" or contain whitespace/shell metacharacters;
#   * every invocation has a hard timeout and a 64 KiB output cap;
#   * intrusive scans need an explicit acknowledgement, are limited in size,
#     refuse public address space unless the caller passes `allow_public`
#     (granted by a dedicated permission in the Web UI), and are audit-logged.
TOOLKIT_OUTPUT_CAP = 64 * 1024
TOOLKIT_BINARIES = {
    "ping": ("ping", "iputils-ping"), "traceroute": ("traceroute", "traceroute"),
    "mtr": ("mtr", "mtr-tiny"), "nmap": ("nmap", "nmap"), "nc": ("nc", "netcat-openbsd"),
    "telnet": ("telnet", "telnet"), "dig": ("dig", "bind9-dnsutils"),
    "whois": ("whois", "whois"), "arping": ("arping", "iputils-arping"),
    "ss": ("ss", "iproute2"), "openssl": ("openssl", "openssl"), "curl": ("curl", "curl"),
    "iperf3": ("iperf3", "iperf3"), "tcpdump": ("tcpdump", "tcpdump"),
    "ethtool": ("ethtool", "ethtool"), "ip": ("ip", "iproute2"), "conntrack": ("conntrack", "conntrack"),
    "nslookup": ("nslookup", "bind9-dnsutils"), "tracepath": ("tracepath", "iputils-tracepath"),
}
NMAP_PROFILES = {
    # name: (flags, description)
    "ping-sweep": (["-sn"], "Host discovery only (no port scan)"),
    "quick": (["-T4", "-F"], "Top 100 TCP ports"),
    "top1000": (["-T4"], "Top 1000 TCP ports"),
    "service": (["-T4", "-sV", "--version-light"], "Top 1000 TCP ports with service/version detection"),
    "udp-top": (["-T4", "-sU", "--top-ports", "50"], "Top 50 UDP ports (slow)"),
    "ports": (["-T4"], "An explicit port list"),
}
NMAP_MAX_HOSTS = 256      # one /24
NMAP_MAX_LIST = 32
NMAP_MAX_PORTS = 128
NMAP_SECONDS = 300
MTR_MAX_CYCLES = 20
IPERF_MAX_SECONDS = 30
HTTP_METHODS = ("GET", "HEAD")
HTTP_MAX_REDIRECTS = 5
TLS_VERSIONS = ("auto", "tls1_2", "tls1_3")
WHOIS_OBJECT_RE = re.compile(r"^(?:AS\d{1,10}|[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|[0-9a-fA-F:.]{2,45}(?:/\d{1,3})?)$")
PORT_LIST_RE = re.compile(r"^\d{1,5}(?:-\d{1,5})?(?:,\d{1,5}(?:-\d{1,5})?)*$")
TOOLKIT_METACHARS = set(";|&`$<>\\\"'(){}[]*?!~\n\r\t \x00")
METADATA_HOSTS = ("metadata.google.internal", "metadata", "169.254.169.254", "fd00:ec2::254")


class ToolkitMixin:
    """diag.* toolkit handlers. Reuses the existing target validation
    (`_diag_target_ok`), the streamed-session machinery (`_stream_open`) and
    the bounded runner."""

    def _tk_host_refusal(self, host: str) -> Optional[str]:
        if not host:
            return "a target host is required"
        if host.startswith("-"):
            return "the target may not start with '-'"
        if any(ch in TOOLKIT_METACHARS for ch in host):
            return "the target contains characters that are not allowed"
        if not self._diag_target_ok(host):
            return "the target must be a valid hostname or IP address"
        return None

    @staticmethod
    def _tk_missing(tool: str) -> Optional[Dict[str, Any]]:
        binary, package = TOOLKIT_BINARIES[tool]
        if OpsMixin._which(binary) is None:
            return {"success": False, "status": "degraded", "missing": binary, "package": package,
                    "message": f"`{binary}` is not installed on this appliance (package: {package}). "
                               f"Install it from System → Packages or add it to the image."}
        return None

    @staticmethod
    def _tk_int(params: Dict[str, Any], key: str, default: int, lo: int, hi: int, label: str) -> tuple:
        raw = params.get(key)
        if raw is None or (isinstance(raw, str) and raw.strip() == ""):
            return default, None
        try:
            val = int(raw)
        except (TypeError, ValueError):
            return None, f"{label} must be a whole number"
        if not lo <= val <= hi:
            return None, f"{label} must be between {lo} and {hi}"
        return val, None

    @staticmethod
    def _tk_audit(tool: str, target: str, outcome: str, params: Dict[str, Any]) -> None:
        logger.info("AUDIT diag tool=%s target=%s actor=%s source=%s outcome=%s",
                    tool, target[:255], str(params.get("_actor") or "-")[:64],
                    str(params.get("_source") or "-")[:16], outcome)

    def _tk_result(self, tool: str, argv: list, res: Dict[str, Any], parsed: Optional[dict] = None,
                   warnings: Optional[list] = None) -> Dict[str, Any]:
        status = "ok" if res["rc"] == 0 else ("timeout" if res["timed_out"] else "error")
        out = {"success": res["rc"] == 0, "status": status, "tool": tool, "output": res["output"],
               "returncode": res["rc"], "truncated": res["truncated"], "cmd": " ".join(argv),
               "parsed": parsed or {}, "warnings": warnings or []}
        if res["timed_out"]:
            out["warnings"].append("the command reached its time limit; output may be partial")
        return out

    async def rpc_diag_tools(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Which toolkit binaries exist on this appliance."""
        tools = {}
        for tool, (binary, package) in TOOLKIT_BINARIES.items():
            path = OpsMixin._which(binary)
            tools[tool] = {"binary": binary, "package": package, "installed": path is not None,
                           "path": path}
        return {"success": True, "status": "ok", "tools": tools,
                "nmap_profiles": {k: v[1] for k, v in NMAP_PROFILES.items()}}

    # --------------------------------------------------- TCP service probe
    async def rpc_diag_telnet(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Connect to host:port, report whether it accepted and what it said.

        Implemented with a socket (no `telnet`/`nc` child at all), so there is no
        flag to inject and nothing to leave running. Optionally sends one line
        (e.g. `HEAD / HTTP/1.0`) so text protocols reveal a banner.
        """
        host = str(params.get("host") or "").strip()
        refusal = self._tk_host_refusal(host)
        if refusal:
            return {"success": False, "status": "error", "message": refusal}
        port, err = self._tk_int(params, "port", 23, 1, 65535, "Port")
        if err:
            return {"success": False, "status": "error", "message": err}
        timeout, err = self._tk_int(params, "timeout", 5, 1, 30, "Timeout")
        if err:
            return {"success": False, "status": "error", "message": err}
        send = str(params.get("send") or "")
        if len(send) > 256 or any(c in send for c in "\x00\r\n"):
            return {"success": False, "status": "error", "message": "the line to send must be one line of at most 256 characters"}
        started = time.time()
        banner = b""
        try:
            with socket.create_connection((host, port), timeout=timeout) as sock:
                connect_ms = round((time.time() - started) * 1000, 1)
                peer = sock.getpeername()
                sock.settimeout(min(3, timeout))
                if send:
                    sock.sendall(send.encode("utf-8", "replace") + b"\r\n\r\n")
                try:
                    while len(banner) < 4096:
                        chunk = sock.recv(1024)
                        if not chunk:
                            break
                        banner += chunk
                except socket.timeout:
                    pass
        except socket.timeout:
            self._tk_audit("telnet", f"{host}:{port}", "filtered", params)
            return {"success": False, "status": "filtered", "tool": "telnet",
                    "parsed": {"open": False, "state": "filtered"},
                    "output": f"Trying {host} port {port}/tcp …\nNo answer within {timeout}s — the port is filtered or the host is down.\n",
                    "message": f"{host}:{port} did not answer within {timeout}s"}
        except OSError as exc:
            self._tk_audit("telnet", f"{host}:{port}", "closed", params)
            return {"success": False, "status": "closed", "tool": "telnet",
                    "parsed": {"open": False, "state": "closed", "error": str(exc)},
                    "output": f"Trying {host} port {port}/tcp …\nConnection failed: {exc}\n",
                    "message": f"{host}:{port} refused or is unreachable: {exc}"}
        text = banner.decode("utf-8", "replace").replace("\r\n", "\n").replace("\r", "\n")
        printable = "".join(ch if ch.isprintable() or ch in "\n\t" else "·" for ch in text)
        self._tk_audit("telnet", f"{host}:{port}", "open", params)
        out = f"Trying {host} port {port}/tcp …\nConnected to {peer[0]} in {connect_ms} ms.\n"
        out += ("--- banner ---\n" + printable.rstrip() + "\n") if printable.strip() else "(the service sent no banner)\n"
        return {"success": True, "status": "ok", "tool": "telnet", "output": out,
                "parsed": {"open": True, "state": "open", "connect_ms": connect_ms,
                           "banner": printable.strip()[:1024], "peer": peer[0]},
                "warnings": ["Telnet is unencrypted — use it only to test reachability; administer with SSH."]}

    # --------------------------------------------------- nmap
    def _nmap_targets(self, raw: str, allow_public: bool) -> tuple:
        raw = raw.strip()
        if not raw:
            return None, "a scan target is required"
        items = [t.strip() for t in raw.split(",") if t.strip()]
        if len(items) > NMAP_MAX_LIST:
            return None, f"at most {NMAP_MAX_LIST} targets may be listed"
        hosts = 0
        targets = []
        for item in items:
            if item.startswith("-") or any(ch in TOOLKIT_METACHARS for ch in item):
                return None, f"target {item!r} contains characters that are not allowed"
            if "/" in item:
                try:
                    net = ipaddress.ip_network(item, strict=False)
                except ValueError:
                    return None, f"{item!r} is not a valid network"
                size = net.num_addresses
                if (net.version == 4 and net.prefixlen < 24) or (net.version == 6 and net.prefixlen < 120):
                    return None, "networks larger than a /24 (IPv4) or /120 (IPv6) cannot be scanned"
                addrs = [net.network_address]
            else:
                literal = self._diag_ip_literal(item)
                if literal is None:
                    if not self._diag_target_ok(item):
                        return None, f"{item!r} is not a valid host"
                    try:
                        resolved = {ipaddress.ip_address(ai[4][0].split("%")[0])
                                    for ai in socket.getaddrinfo(item, None)}
                    except OSError:
                        return None, f"{item} does not resolve"
                    addrs = list(resolved)
                else:
                    addrs = [literal]
                size = 1
            hosts += size
            for addr in addrs:
                if addr.is_loopback or addr.is_link_local or addr.is_multicast or addr.is_unspecified:
                    return None, f"{item} is a loopback, link-local, multicast or unspecified address"
                if not allow_public and addr.is_global:
                    return None, (f"{item} is public address space; scanning it needs the "
                                  "'Scan public addresses' permission and explicit selection")
            targets.append(item)
        if hosts > NMAP_MAX_HOSTS:
            return None, f"the scan would cover {hosts} addresses (limit {NMAP_MAX_HOSTS})"
        return targets, None

    def _nmap_argv(self, params: Dict[str, Any]) -> tuple:
        if not self._diag_checkbox(params.get("authorized")):
            return None, None, "confirm that you are authorised to scan this target before running a scan"
        profile = str(params.get("profile") or "quick")
        if profile not in NMAP_PROFILES:
            return None, None, "unknown scan profile"
        targets, err = self._nmap_targets(str(params.get("target") or ""),
                                          self._diag_checkbox(params.get("allow_public")))
        if err:
            return None, None, err
        argv = ["nmap", "-Pn" if profile != "ping-sweep" else "-n", "--reason", "--max-retries", "2",
                "--host-timeout", "120s"] + NMAP_PROFILES[profile][0]
        if profile == "ports":
            ports = str(params.get("ports") or "").replace(" ", "")
            if not PORT_LIST_RE.match(ports):
                return None, None, "ports must be a list such as 22,80,443 or 8000-8010"
            count = 0
            for part in ports.split(","):
                lo, _, hi = part.partition("-")
                lo_i, hi_i = int(lo), int(hi or lo)
                if not (1 <= lo_i <= hi_i <= 65535):
                    return None, None, f"invalid port range {part}"
                count += hi_i - lo_i + 1
            if count > NMAP_MAX_PORTS:
                return None, None, f"at most {NMAP_MAX_PORTS} ports may be listed"
            argv += ["-p", ports]
        if self._diag_ip_literal(targets[0]) is not None and self._diag_ip_literal(targets[0]).version == 6:
            argv.append("-6")
        return argv + targets, targets, None

    async def rpc_diag_nmap(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Run a bounded scan to completion (CLI path)."""
        missing = self._tk_missing("nmap")
        if missing:
            return missing
        argv, targets, err = self._nmap_argv(params)
        if err:
            return {"success": False, "status": "blocked" if "authoris" in err or "public" in err else "error",
                    "message": err}
        res = self._run_bounded(argv, timeout=NMAP_SECONDS, cap=TOOLKIT_OUTPUT_CAP)
        self._tk_audit("nmap", ",".join(targets), f"rc={res['rc']}", params)
        open_ports = re.findall(r"^(\d+)/(tcp|udp)\s+open\s+(\S+)", res["output"], re.M)
        return self._tk_result("nmap", argv, res, {"open_ports": [
            {"port": int(p), "proto": pr, "service": s} for p, pr, s in open_ports]})

    async def rpc_diag_nmap_start(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Start a scan as a streamed session (Web UI path); poll with diag.stream_poll."""
        missing = self._tk_missing("nmap")
        if missing:
            return missing
        argv, targets, err = self._nmap_argv(params)
        if err:
            return {"success": False, "status": "blocked" if "authoris" in err or "public" in err else "error",
                    "message": err}
        self._tk_audit("nmap", ",".join(targets), "started", params)
        return self._stream_open(argv, "nmap", ",".join(targets))

    # --------------------------------------------------- mtr
    def _mtr_argv(self, params: Dict[str, Any]) -> tuple:
        host = str(params.get("host") or "").strip()
        refusal = self._tk_host_refusal(host)
        if refusal:
            return None, refusal
        cycles, err = self._tk_int(params, "cycles", 10, 1, MTR_MAX_CYCLES, "Cycles")
        if err:
            return None, err
        argv = ["mtr", "--report", "--report-wide", "--no-dns" if not self._diag_checkbox(params.get("resolve")) else "--show-ips",
                "-c", str(cycles)]
        if str(params.get("ipproto") or "").lower() == "ipv6":
            argv.append("-6")
        elif str(params.get("ipproto") or "").lower() == "ipv4":
            argv.append("-4")
        if self._diag_checkbox(params.get("tcp")):
            argv.append("--tcp")
        return argv + [host], None

    async def rpc_diag_mtr(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("mtr")
        if missing:
            return missing
        argv, err = self._mtr_argv(params)
        if err:
            return {"success": False, "status": "error", "message": err}
        res = self._run_bounded(argv, timeout=120)
        hops = []
        for line in res["output"].splitlines():
            m = re.match(r"^\s*(\d+)\.\|--\s+(\S+)\s+([\d.]+)%\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)", line)
            if m:
                hops.append({"hop": int(m.group(1)), "host": m.group(2), "loss_pct": float(m.group(3)),
                             "sent": int(m.group(4)), "last": float(m.group(5)), "avg": float(m.group(6)),
                             "best": float(m.group(7)), "worst": float(m.group(8)), "stdev": float(m.group(9))})
        self._tk_audit("mtr", argv[-1], f"rc={res['rc']}", params)
        return self._tk_result("mtr", argv, res, {"hops": hops})

    async def rpc_diag_mtr_start(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("mtr")
        if missing:
            return missing
        argv, err = self._mtr_argv(params)
        if err:
            return {"success": False, "status": "error", "message": err}
        return self._stream_open(argv, "mtr", argv[-1])

    # --------------------------------------------------- DNS / whois
    async def rpc_diag_dns_query(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """dig with a short or full answer and optional +trace / DNSSEC."""
        missing = self._tk_missing("dig")
        if missing:
            return missing
        name = str(params.get("name") or "").strip()
        refusal = self._tk_host_refusal(name)
        if refusal:
            return {"success": False, "status": "error", "message": refusal}
        qtype = str(params.get("type") or "A").strip().upper()
        if qtype not in DIAG_DNS_TYPES + ("SRV", "CAA", "DS", "DNSKEY", "ANY"):
            return {"success": False, "status": "error", "message": "unsupported record type"}
        argv = ["dig", "+time=3", "+tries=2"]
        server = str(params.get("server") or "").strip()
        if server:
            if self._diag_ip_literal(server) is None:
                return {"success": False, "status": "error", "message": "DNS server must be an IP address"}
            argv.append(f"@{server}")
        if self._diag_checkbox(params.get("dnssec")):
            argv.append("+dnssec")
        if self._diag_checkbox(params.get("trace")):
            argv.append("+trace")
        if self._diag_checkbox(params.get("short")):
            argv.append("+short")
        argv += [name, qtype]
        res = self._run_bounded(argv, timeout=30)
        answers = [l for l in res["output"].splitlines() if l and not l.startswith(";")]
        status = re.search(r"status: ([A-Z]+)", res["output"])
        return self._tk_result("dns", argv, res, {"rcode": status.group(1) if status else None,
                                                    "answers": answers[:50]})

    async def rpc_diag_whois(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("whois")
        if missing:
            return missing
        obj = str(params.get("object") or params.get("query") or "").strip()
        if not obj or obj.startswith("-") or not WHOIS_OBJECT_RE.match(obj):
            return {"success": False, "status": "error",
                    "message": "enter a domain, IP address, prefix or AS number (e.g. AS13335)"}
        argv = ["whois", "--", obj]
        res = self._run_bounded(argv, timeout=20)
        return self._tk_result("whois", argv, res)

    # --------------------------------------------------- layer 2 / sockets
    async def rpc_diag_arping(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("arping")
        if missing:
            return missing
        iface = str(params.get("interface") or "").strip()
        target = str(params.get("target") or params.get("host") or "").strip()
        if DIAG_IFACE_RE.fullmatch(iface) is None:
            return {"success": False, "status": "error", "message": "interface name is not valid"}
        addr = self._diag_ip_literal(target)
        if addr is None or addr.version != 4:
            return {"success": False, "status": "error", "message": "target must be an IPv4 address (use NDP for IPv6)"}
        count, err = self._tk_int(params, "count", 3, 1, 10, "Count")
        if err:
            return {"success": False, "status": "error", "message": err}
        argv = ["arping", "-c", str(count), "-w", str(count + 2), "-I", iface, str(addr)]
        res = self._run_bounded(argv, timeout=count + 5)
        macs = sorted(set(re.findall(r"\[([0-9a-fA-F:]{17})\]", res["output"])))
        return self._tk_result("arping", argv, res, {"replies": len(re.findall(r"reply from", res["output"], re.I)),
                                                     "macs": macs})

    async def rpc_diag_arp_table(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = self._run_bounded([IP_BIN, "-j", "neigh", "show"], timeout=5)
        try:
            rows = strict_json_loads(res["output"]) if res["rc"] == 0 else []
        except ValueError:
            rows = []
        entries = [{"ip": r.get("dst"), "mac": r.get("lladdr", ""), "interface": r.get("dev"),
                    "state": ",".join(r.get("state", [])) if isinstance(r.get("state"), list) else r.get("state")}
                   for r in rows if isinstance(r, dict)]
        return {"success": res["rc"] == 0, "status": "ok", "entries": entries}

    async def rpc_diag_sockets(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("ss")
        if missing:
            return missing
        flags = "-H"
        flags += "l" if self._diag_checkbox(params.get("listen", True)) else "a"
        want_tcp = self._diag_checkbox(params.get("tcp", True))
        want_udp = self._diag_checkbox(params.get("udp", True))
        if not want_tcp and not want_udp:
            want_tcp = want_udp = True
        flags += ("t" if want_tcp else "") + ("u" if want_udp else "") + "np"
        argv = ["ss", flags]
        res = self._run_bounded(argv, timeout=10)
        sockets = []
        for line in res["output"].splitlines():
            cols = line.split()
            if len(cols) < 5:
                continue
            proc = re.search(r'users:\(\("([^"]+)",pid=(\d+)', line)
            sockets.append({"netid": cols[0], "state": cols[1], "local": cols[4],
                            "peer": cols[5] if len(cols) > 5 else "",
                            "process": proc.group(1) if proc else None,
                            "pid": int(proc.group(2)) if proc else None})
        return self._tk_result("sockets", argv, res, {"sockets": sockets[:500]})

    # --------------------------------------------------- TLS / HTTP
    async def rpc_diag_tls_probe(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Certificate, protocol and cipher a TLS endpoint offers."""
        missing = self._tk_missing("openssl")
        if missing:
            return missing
        host = str(params.get("host") or "").strip()
        refusal = self._tk_host_refusal(host)
        if refusal:
            return {"success": False, "status": "error", "message": refusal}
        port, err = self._tk_int(params, "port", 443, 1, 65535, "Port")
        if err:
            return {"success": False, "status": "error", "message": err}
        sni = str(params.get("sni") or "").strip() or (host if self._diag_ip_literal(host) is None else "")
        if sni and (self._tk_host_refusal(sni) or self._diag_ip_literal(sni) is not None):
            return {"success": False, "status": "error", "message": "SNI must be a hostname"}
        version = str(params.get("version") or "auto")
        if version not in TLS_VERSIONS:
            return {"success": False, "status": "error", "message": "unsupported TLS version"}
        target = f"[{host}]:{port}" if ":" in host else f"{host}:{port}"
        argv = ["openssl", "s_client", "-connect", target]
        if sni:
            argv += ["-servername", sni]
        if version != "auto":
            argv.append("-" + version)
        res = self._run_bounded(argv, timeout=15, input_text="Q\n")
        out = res["output"]
        parsed = {
            "protocol": (re.search(r"Protocol\s*:\s*(\S+)", out) or re.search(r"New, (TLSv[\d.]+)", out) or [None, None])[1],
            "cipher": (re.search(r"Cipher\s*(?:is|:)\s*(\S+)", out) or [None, None])[1],
            "subject": (re.search(r"^subject=(.*)$", out, re.M) or [None, None])[1],
            "issuer": (re.search(r"^issuer=(.*)$", out, re.M) or [None, None])[1],
            "verify": (re.search(r"Verify return code: (\d+ \([^)]*\))", out) or [None, None])[1],
        }
        cert = re.search(r"(-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----)", out, re.S)
        if cert:
            dates = self._run_bounded(["openssl", "x509", "-noout", "-dates", "-ext", "subjectAltName"],
                                      timeout=5, input_text=cert.group(1))
            nb = re.search(r"notBefore=(.*)", dates["output"])
            na = re.search(r"notAfter=(.*)", dates["output"])
            parsed["not_before"] = nb.group(1).strip() if nb else None
            parsed["not_after"] = na.group(1).strip() if na else None
            san = re.search(r"Subject Alternative Name:\s*\n\s*(.*)", dates["output"])
            parsed["san"] = san.group(1).strip() if san else None
            out = out.replace(cert.group(1), "[certificate PEM omitted — see the parsed summary]")
            res["output"] = out
        ok = bool(parsed["protocol"] or parsed["cipher"]) and "Cipher is (NONE)" not in out
        res["rc"] = 0 if ok else (res["rc"] or 1)
        self._tk_audit("tls", target, "ok" if ok else "fail", params)
        return self._tk_result("tls", argv, res, parsed)

    @staticmethod
    def _http_refusal(url: str) -> Optional[str]:
        if len(url) > 2048 or any(c in url for c in "\x00\r\n \t\\\"'`<>"):
            return "the URL contains characters that are not allowed"
        try:
            parts = urllib.parse.urlsplit(url)
        except ValueError:
            return "the URL is not valid"
        if parts.scheme not in ("http", "https"):
            return "only http:// and https:// URLs may be probed"
        host = (parts.hostname or "").lower()
        if not host or host.startswith("-"):
            return "the URL needs a host"
        if host in METADATA_HOSTS:
            return "cloud metadata endpoints may not be probed"
        try:
            addr = ipaddress.ip_address(host)
            if addr.is_link_local:
                return "link-local addresses may not be probed"
        except ValueError:
            pass
        return None

    async def rpc_diag_http(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Status, headers and timing of an HTTP(S) endpoint (curl, no file writes)."""
        missing = self._tk_missing("curl")
        if missing:
            return missing
        url = str(params.get("url") or "").strip()
        refusal = self._http_refusal(url)
        if refusal:
            return {"success": False, "status": "error", "message": refusal}
        method = str(params.get("method") or "GET").upper()
        if method not in HTTP_METHODS:
            return {"success": False, "status": "error", "message": "method must be GET or HEAD"}
        timeout, err = self._tk_int(params, "timeout", 10, 1, 30, "Timeout")
        if err:
            return {"success": False, "status": "error", "message": err}
        argv = ["curl", "--silent", "--show-error", "--proto", "=http,https", "--proto-redir", "=http,https",
                "--max-time", str(timeout), "--max-filesize", "1048576", "--output", "/dev/null",
                "--dump-header", "-", "--write-out",
                "\n---\nhttp_code=%{http_code}\nremote_ip=%{remote_ip}\ntime_namelookup=%{time_namelookup}\n"
                "time_connect=%{time_connect}\ntime_appconnect=%{time_appconnect}\n"
                "time_starttransfer=%{time_starttransfer}\ntime_total=%{time_total}\n"
                "num_redirects=%{num_redirects}\nurl_effective=%{url_effective}\nssl_verify_result=%{ssl_verify_result}\n"]
        if self._diag_checkbox(params.get("follow")):
            argv += ["--location", "--max-redirs", str(HTTP_MAX_REDIRECTS)]
        if self._diag_checkbox(params.get("insecure")):
            argv.append("--insecure")
        if method == "HEAD":
            argv.append("--head")
        argv += ["--", url]
        res = self._run_bounded(argv, timeout=timeout + 5)
        meta = dict(re.findall(r"^(http_code|remote_ip|time_\w+|num_redirects|url_effective|ssl_verify_result)=(.*)$",
                               res["output"], re.M))
        eff = meta.get("url_effective", "")
        if eff and self._http_refusal(eff):
            res["output"] += "\nRedirect target refused: " + str(self._http_refusal(eff))
            res["rc"] = 1
        code = int(meta.get("http_code") or 0)
        parsed = {"http_code": code, "remote_ip": meta.get("remote_ip"),
                  "redirects": int(meta.get("num_redirects") or 0), "effective_url": eff,
                  "timing_ms": {k[5:]: round(float(v) * 1000, 1) for k, v in meta.items()
                                if k.startswith("time_") and re.match(r"^[\d.]+$", v or "")}}
        if res["rc"] == 0 and code == 0:
            res["rc"] = 1
        self._tk_audit("http", url, f"code={code}", params)
        return self._tk_result("http", argv, res, parsed)

    # --------------------------------------------------- iperf3 client
    async def rpc_diag_iperf3(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("iperf3")
        if missing:
            return missing
        host = str(params.get("host") or "").strip()
        refusal = self._tk_host_refusal(host)
        if refusal:
            return {"success": False, "status": "error", "message": refusal}
        port, err = self._tk_int(params, "port", 5201, 1, 65535, "Port")
        if err:
            return {"success": False, "status": "error", "message": err}
        seconds, err = self._tk_int(params, "time", 10, 1, IPERF_MAX_SECONDS, "Duration")
        if err:
            return {"success": False, "status": "error", "message": err}
        streams, err = self._tk_int(params, "parallel", 1, 1, 8, "Parallel streams")
        if err:
            return {"success": False, "status": "error", "message": err}
        argv = ["iperf3", "-c", host, "-p", str(port), "-t", str(seconds), "-P", str(streams), "-J"]
        if self._diag_checkbox(params.get("reverse")):
            argv.append("-R")
        if self._diag_checkbox(params.get("udp")):
            argv += ["-u", "-b", "100M"]
        res = self._run_bounded(argv, timeout=seconds + 15, cap=262144)
        parsed: Dict[str, Any] = {}
        try:
            data = strict_json_loads(res["output"])
            end = data.get("end", {}) if isinstance(data, dict) else {}
            sent = end.get("sum_sent") or end.get("sum") or {}
            recv = end.get("sum_received") or {}
            parsed = {"sent_mbps": round(sent.get("bits_per_second", 0) / 1e6, 2),
                      "received_mbps": round(recv.get("bits_per_second", 0) / 1e6, 2),
                      "retransmits": sent.get("retransmits"),
                      "error": data.get("error") if isinstance(data, dict) else None}
            if parsed.get("error"):
                res["rc"] = res["rc"] or 1
            summary = (f"Sender:   {parsed['sent_mbps']} Mbit/s\nReceiver: {parsed['received_mbps']} Mbit/s\n"
                       + (f"Retransmits: {parsed['retransmits']}\n" if parsed.get("retransmits") is not None else "")
                       + (f"Error: {parsed['error']}\n" if parsed.get("error") else ""))
            res["output"] = summary
        except ValueError:
            pass
        self._tk_audit("iperf3", f"{host}:{port}", f"rc={res['rc']}", params)
        return self._tk_result("iperf3", argv, res, parsed)

    # --------------------------------------------------- interface details
    async def rpc_diag_ethtool(self, params: Dict[str, Any]) -> Dict[str, Any]:
        missing = self._tk_missing("ethtool")
        if missing:
            return missing
        iface = str(params.get("interface") or "").strip()
        if DIAG_IFACE_RE.fullmatch(iface) is None:
            return {"success": False, "status": "error", "message": "interface name is not valid"}
        mode = str(params.get("mode") or "link")
        flag = {"link": [], "driver": ["-i"], "stats": ["-S"], "ring": ["-g"], "offload": ["-k"]}.get(mode)
        if flag is None:
            return {"success": False, "status": "error", "message": "mode must be link, driver, stats, ring or offload"}
        argv = ["ethtool"] + flag + [iface]
        res = self._run_bounded(argv, timeout=10)
        return self._tk_result("ethtool", argv, res)



# =============================================================================
# Network configuration transactions (network.apply / network.confirm /
# network.status / system.ipv6.set)
# =============================================================================
# One engine (system/micron_netcfg.py) makes the kernel match the committed
# plan; the same file runs at boot, so what is committed here is what comes
# back after a reboot.
NETCFG_STATE_DIR = "/var/lib/micron/network"
NETCFG_PENDING = os.path.join(NETCFG_STATE_DIR, "pending.json")
NETCFG_LAST = os.path.join(NETCFG_STATE_DIR, "last-result.json")
NFTABLES_CONF = "/etc/nftables.conf"
KEA_MANAGED_MARKER = "/var/lib/micron/managed/kea-dhcp4.applied"
NETCFG_CONFIRM_MIN, NETCFG_CONFIRM_MAX = 30, 600


def _load_netcfg():
    """Import system/micron_netcfg.py from the application tree."""
    import importlib.util
    here = os.path.dirname(os.path.abspath(__file__))
    for path in (os.path.join(here, "..", "micron_netcfg.py"), "/usr/local/micron/system/micron_netcfg.py"):
        path = os.path.normpath(path)
        if os.path.exists(path):
            spec = importlib.util.spec_from_file_location("micron_netcfg", path)
            mod = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(mod)
            return mod
    raise RuntimeError("micron_netcfg.py not found")


netcfg = _load_netcfg()


class NetworkMixin:
    """Transactional network apply: validate → snapshot → apply → verify →
    commit, or restore everything that was touched."""

    _net_timer = None

    def _net_run(self, argv):
        return self._run(argv)

    @staticmethod
    def _read(path: str) -> Optional[str]:
        return netcfg.read_text(path)

    def _nft_check(self, ruleset: str) -> Optional[str]:
        fd, tmp = tempfile.mkstemp(prefix="micron-nft-", suffix=".conf")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(ruleset)
            res = self._run(["nft", "-c", "-f", tmp])
            if res.returncode == 127:
                return None
            return None if res.returncode == 0 else (res.stderr or res.stdout or "nft -c failed").strip()[:600]
        finally:
            try:
                os.remove(tmp)
            except OSError:
                pass

    def _nft_load_text(self, ruleset: str) -> Optional[str]:
        fd, tmp = tempfile.mkstemp(prefix="micron-nft-", suffix=".conf")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(ruleset)
            res = self._run(["nft", "-f", tmp])
            if res.returncode == 127:
                return None
            return None if res.returncode == 0 else (res.stderr or "nft -f failed").strip()[:600]
        finally:
            try:
                os.remove(tmp)
            except OSError:
                pass

    def _net_snapshot(self, plan: Dict[str, Any]) -> Dict[str, Any]:
        devices = sorted({i["device"] for i in plan["interfaces"]})
        prev = None
        try:
            prev = strict_json_loads(self._read(netcfg.PLAN_PATH) or "null")
        except ValueError:
            prev = None
        if isinstance(prev, dict):
            devices = sorted(set(devices) | {i.get("device") for i in prev.get("interfaces", []) if i.get("device")})
        snap = netcfg.snapshot(self._net_run, devices)
        live = self._run(["nft", "list", "ruleset"])
        snap["nft_live"] = live.stdout if live.returncode == 0 else None
        snap["nft_conf"] = self._read(NFTABLES_CONF)
        snap["kea_conf"] = self._read(KEA_DHCP4_CONF)
        snap["previous_plan"] = prev
        return snap

    def _net_restore(self, snap: Dict[str, Any], kea_touched: bool) -> List[str]:
        steps = netcfg.restore(self._net_run, snap)
        if snap.get("nft_live"):
            err = self._nft_load_text(snap["nft_live"])
            steps.append("firewall ruleset restored" if err is None else f"firewall restore failed: {err}")
        if snap.get("nft_conf") is not None:
            safe_atomic_write(NFTABLES_CONF, snap["nft_conf"], mode=0o644)
        if kea_touched:
            if snap.get("kea_conf") is not None:
                safe_atomic_write(KEA_DHCP4_CONF, snap["kea_conf"], mode=0o600)
            self._run(["systemctl", "restart", KEA_DHCP4_UNIT])
            steps.append("DHCP server configuration restored")
        return steps

    def _net_kea_apply(self, config: Any, steps: list) -> Optional[str]:
        data, errors = self._dhcp_kea_validate(config)
        if errors:
            return "; ".join(errors)
        content = json.dumps(data, indent=4, ensure_ascii=False, allow_nan=False)
        fd, tmp = tempfile.mkstemp(prefix="micron-kea-", suffix=".conf")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(content)
            res = self._run([KEA_DHCP4_BIN, "-t", tmp])
            if res.returncode not in (0, 127):
                return "kea-dhcp4 rejected the configuration: " + (res.stdout + res.stderr).strip()[:400]
        finally:
            try:
                os.remove(tmp)
            except OSError:
                pass
        err = self._write_config(KEA_DHCP4_CONF, content)
        if err:
            return err
        os.makedirs(os.path.dirname(KEA_MANAGED_MARKER), exist_ok=True)
        safe_atomic_write(KEA_MANAGED_MARKER, time.strftime("%Y-%m-%d %H:%M:%S") + "\n", mode=0o644)
        steps.append(f"wrote {KEA_DHCP4_CONF}")
        if bool(data.get("Dhcp4", {}).get("subnet4")):
            res = self._run(["systemctl", "restart", KEA_DHCP4_UNIT])
            steps.append(f"systemctl restart {KEA_DHCP4_UNIT} rc={res.returncode}")
        return None

    def _net_commit_files(self, plan: Dict[str, Any], ruleset: Optional[str]) -> None:
        os.makedirs(os.path.dirname(netcfg.PLAN_PATH), exist_ok=True)
        safe_atomic_write(netcfg.PLAN_PATH, json.dumps(plan, indent=2, sort_keys=True) + "\n", mode=0o644)
        if ruleset:
            safe_atomic_write(NFTABLES_CONF, ruleset, mode=0o644)

    def _net_record(self, payload: Dict[str, Any]) -> None:
        try:
            os.makedirs(os.path.dirname(NETCFG_LAST), exist_ok=True)
            safe_atomic_write(NETCFG_LAST, json.dumps(payload, indent=2, sort_keys=True, default=str) + "\n", mode=0o600)
        except OSError:
            pass

    def _net_services_for_ipv6(self, plan: Dict[str, Any], prev: Optional[Dict[str, Any]], steps: list) -> None:
        """Services that bind IPv6 sockets follow the switch."""
        before = bool((prev or {}).get("ipv6_enabled", not plan["ipv6_enabled"]))
        if before == plan["ipv6_enabled"]:
            return
        if not plan["ipv6_enabled"]:
            for unit in ("kea-dhcp6-server", "radvd"):
                self._run(["systemctl", "stop", unit])
            steps.append("stopped DHCPv6 / router advertisements")
        for unit in ("unbound", "ssh"):
            res = self._run(["systemctl", "try-restart", unit])
            steps.append(f"systemctl try-restart {unit} rc={res.returncode}")

    async def rpc_network_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply a complete network plan (+ firewall ruleset + DHCP config) as
        one transaction. With `confirm_timeout` the change is kept only if
        `network.confirm` arrives in time — for changes that can cut off the
        management session; otherwise it is committed when verification passes.
        """
        plan, errors = netcfg.validate_plan(params.get("plan"))
        if errors:
            return {"success": False, "status": "invalid", "errors": errors,
                    "message": "network plan rejected: " + "; ".join(errors)}
        ruleset = params.get("ruleset")
        if ruleset is not None and (not isinstance(ruleset, str) or not ruleset.strip()):
            return {"success": False, "status": "invalid", "message": "ruleset must be non-empty text"}
        if ruleset:
            err = self._nft_check(ruleset)
            if err:
                return {"success": False, "status": "invalid", "message": "firewall ruleset rejected: " + err}
        kea = params.get("dhcp4")
        try:
            confirm = int(params.get("confirm_timeout") or 0)
        except (TypeError, ValueError):
            confirm = 0
        if confirm:
            confirm = max(NETCFG_CONFIRM_MIN, min(NETCFG_CONFIRM_MAX, confirm))

        if os.path.exists(NETCFG_PENDING):
            return {"success": False, "status": "busy",
                    "message": "another network change is waiting for confirmation; confirm or let it roll back first"}

        snap = self._net_snapshot(plan)
        prev = snap.get("previous_plan") if isinstance(snap.get("previous_plan"), dict) else None
        steps: list = []
        warnings: list = []
        kea_touched = False

        result = netcfg.apply_plan(self._net_run, plan, prev, persist=False)
        steps += result["steps"]
        warnings += result["warnings"]
        failure = list(result["errors"])

        if not failure and ruleset:
            err = self._nft_load_text(ruleset)
            if err:
                failure.append("firewall ruleset failed to load: " + err)
            else:
                steps.append("firewall ruleset loaded")
        if not failure and kea is not None:
            kea_touched = True
            err = self._net_kea_apply(kea, steps)
            if err:
                failure.append("DHCP: " + err)
        if not failure:
            failure += netcfg.verify(self._net_run, plan)

        if failure:
            restored = self._net_restore(snap, kea_touched)
            payload = {"success": False, "status": "rolled_back", "errors": failure,
                       "steps": steps, "restored": restored, "warnings": warnings,
                       "message": "network change not applied — previous configuration restored: " + "; ".join(failure)}
            self._net_record(payload)
            return payload

        self._net_services_for_ipv6(plan, prev, steps)

        if confirm:
            tx = secrets.token_hex(8)
            pending = {"tx": tx, "deadline": time.time() + confirm, "plan": plan, "ruleset": ruleset,
                       "snapshot": snap, "kea_touched": kea_touched}
            os.makedirs(os.path.dirname(NETCFG_PENDING), exist_ok=True)
            safe_atomic_write(NETCFG_PENDING, json.dumps(pending, default=str), mode=0o600)
            self._net_arm(tx, confirm)
            payload = {"success": True, "status": "pending_confirm", "tx": tx, "confirm_timeout": confirm,
                       "steps": steps, "warnings": warnings,
                       "message": f"network change applied; confirm within {confirm}s or it is rolled back"}
            self._net_record(payload)
            return payload

        self._net_commit_files(plan, ruleset)
        steps.append(f"committed {netcfg.PLAN_PATH}")
        payload = {"success": True, "status": "applied", "steps": steps, "warnings": warnings,
                   "message": "network configuration applied and verified"}
        self._net_record(payload)
        return payload

    def _net_arm(self, tx: str, seconds: int) -> None:
        loop = getattr(self, "loop", None)
        if loop is None:
            return
        if self._net_timer is not None:
            self._net_timer.cancel()
        self._net_timer = loop.call_later(seconds, lambda: self._net_expire(tx))

    def _net_load_pending(self) -> Optional[Dict[str, Any]]:
        try:
            data = strict_json_loads(self._read(NETCFG_PENDING) or "null")
            return data if isinstance(data, dict) else None
        except ValueError:
            return None

    def _net_expire(self, tx: str) -> None:
        pending = self._net_load_pending()
        if not pending or pending.get("tx") != tx:
            return
        logger.critical("network change %s was not confirmed in time; rolling back", tx)
        restored = self._net_restore(pending.get("snapshot") or {}, bool(pending.get("kea_touched")))
        try:
            os.remove(NETCFG_PENDING)
        except OSError:
            pass
        self._net_record({"success": False, "status": "rolled_back", "tx": tx, "restored": restored,
                          "message": "network change was not confirmed in time and was rolled back"})

    def net_recover_on_start(self) -> Optional[str]:
        """Called once at daemon start: an unconfirmed change left behind by a
        crash or reboot is rolled back, not silently kept."""
        pending = self._net_load_pending()
        if not pending:
            return None
        if time.time() < float(pending.get("deadline", 0)):
            self._net_arm(str(pending.get("tx")), int(float(pending["deadline"]) - time.time()) + 1)
            return "pending"
        self._net_expire(str(pending.get("tx")))
        return "rolled_back"

    async def rpc_network_confirm(self, params: Dict[str, Any]) -> Dict[str, Any]:
        tx = str(params.get("tx", ""))
        pending = self._net_load_pending()
        if not pending:
            last = {}
            try:
                last = strict_json_loads(self._read(NETCFG_LAST) or "{}")
            except ValueError:
                pass
            if last.get("tx") == tx and last.get("status") == "rolled_back":
                return {"success": False, "status": "rolled_back", "message": "too late: the change was already rolled back"}
            return {"success": False, "status": "none", "message": "no network change is waiting for confirmation"}
        if pending.get("tx") != tx:
            return {"success": False, "status": "mismatch", "message": "unknown transaction id"}
        if self._net_timer is not None:
            self._net_timer.cancel()
            self._net_timer = None
        self._net_commit_files(pending["plan"], pending.get("ruleset"))
        try:
            os.remove(NETCFG_PENDING)
        except OSError:
            pass
        payload = {"success": True, "status": "applied", "tx": tx, "message": "network change confirmed and committed"}
        self._net_record(payload)
        return payload

    async def rpc_network_rollback(self, params: Dict[str, Any]) -> Dict[str, Any]:
        pending = self._net_load_pending()
        if not pending:
            return {"success": False, "status": "none", "message": "no network change is waiting for confirmation"}
        self._net_expire(str(pending.get("tx")))
        return {"success": True, "status": "rolled_back", "message": "network change rolled back"}

    async def rpc_network_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: committed plan, pending transaction, last result and the
        kernel's effective IPv4/IPv6 state for the planned devices."""
        try:
            plan = strict_json_loads(self._read(netcfg.PLAN_PATH) or "null")
        except ValueError:
            plan = None
        pending = self._net_load_pending()
        try:
            last = strict_json_loads(self._read(NETCFG_LAST) or "null")
        except ValueError:
            last = None
        effective = {}
        for i in (plan or {}).get("interfaces", []) if isinstance(plan, dict) else []:
            dev = i.get("device", "")
            if netcfg.DEVICE_RE.fullmatch(str(dev)) and netcfg.device_exists(dev):
                effective[dev] = {"ipv4": netcfg.current_ipv4_all(self._net_run, dev),
                                  "ipv6": netcfg.current_ipv6_global(self._net_run, dev)}
        return {
            "success": True, "status": "ok",
            "plan": plan,
            "pending": ({"tx": pending.get("tx"), "seconds_left": max(0, int(float(pending.get("deadline", 0)) - time.time()))}
                        if pending else None),
            "last": ({k: last.get(k) for k in ("status", "message", "tx", "errors")} if isinstance(last, dict) else None),
            "ipv6_effective": netcfg.effective_ipv6(),
            "routes": netcfg.default_routes(self._net_run),
            "effective": effective,
        }

    async def rpc_system_ipv6_set(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Switch IPv6 using the committed plan (kept for CLI compatibility).

        The Web UI sends the whole plan through `network.apply` so the firewall
        and the interfaces change in the same transaction; this handler flips
        only the switch on the committed plan, persistently, with the same
        verification and restore.
        """
        enabled = bool(params.get("enabled", False))
        try:
            plan = strict_json_loads(self._read(netcfg.PLAN_PATH) or "null")
        except ValueError:
            plan = None
        if not isinstance(plan, dict):
            return {"success": False, "status": "error",
                    "message": "no committed network plan yet; apply the network configuration from the Web UI or `php spark network:apply` first"}
        plan["ipv6_enabled"] = enabled
        return await self.rpc_network_apply({"plan": plan, "ruleset": params.get("ruleset")})


class RpcHandler(NetworkMixin, OpsMixin, ToolkitMixin):
    """Handles JSON-RPC 2.0 requests from the PHP Control Plane."""

    def __init__(self, tx_mgr: SafeTransactionManager, loop: asyncio.AbstractEventLoop):
        self.tx_mgr = tx_mgr
        self.loop = loop
        # Live packet-capture sessions, keyed by session id. The handler is one
        # instance for the daemon's whole lifetime, so a capture started by one
        # connection is visible to the polls and stop calls that follow it.
        self._captures: Dict[str, Dict[str, Any]] = {}
        # Streamed probes (ping, traceroute), the same shape as a capture but
        # their own registry and wall-clock cap.
        self._streams: Dict[str, Dict[str, Any]] = {}

    async def handle_request(self, req: Dict[str, Any],
                             peer: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        if not isinstance(req, dict):
            return {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "Invalid Request: not an object"}}
        req_id = req.get("id")
        method = req.get("method", "")
        params = req.get("params", {})

        if not method or not isinstance(method, str):
            return {"jsonrpc": "2.0", "id": req_id, "error": {"code": -32600, "message": "Invalid Request: method missing"}}
        # PHP's json_encode([]) produces a list, so an empty list is the
        # management plane's way of saying "no parameters".
        if params is None or params == []:
            params = {}
        if not isinstance(params, dict):
            return {"jsonrpc": "2.0", "id": req_id,
                    "error": {"code": -32602, "message": "Invalid params: params must be an object"}}
        if not re.fullmatch(r"[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}", method):
            return {"jsonrpc": "2.0", "id": req_id, "error": {"code": -32601, "message": f"Method not found: {method[:80]}"}}

        logger.info(f"Dispatching RPC method: {method}")

        try:
            handler_method = getattr(self, f"rpc_{method.replace('.', '_')}", None)
            if not handler_method:
                return {"jsonrpc": "2.0", "id": req_id, "error": {"code": -32601, "message": f"Method not found: {method}"}}

            needed = rpc_method_class(method)
            allowed = rpc_peer_max_class(peer)
            if RPC_CLASS_RANK[needed] > RPC_CLASS_RANK[allowed]:
                logger.warning("AUDIT rpc refused method=%s class=%s uid=%s pid=%s",
                               method, needed, (peer or {}).get("uid"), (peer or {}).get("pid"))
                return {"jsonrpc": "2.0", "id": req_id, "error": {
                    "code": -32003,
                    "message": f"Permission denied: {method} is a {needed} method and this caller may only use {allowed} methods"}}
            if needed != RPC_CLASS_READ:
                logger.info("AUDIT rpc method=%s class=%s uid=%s pid=%s", method, needed,
                            (peer or {}).get("uid", "internal"), (peer or {}).get("pid", "-"))

            result = await handler_method(params)
            return {"jsonrpc": "2.0", "id": req_id, "result": result}
        except Exception as e:
            logger.error(f"Error handling method {method}: {e}", exc_info=True)
            return {"jsonrpc": "2.0", "id": req_id, "error": {"code": -32000, "message": str(e)}}

    # ------------------ System Methods ------------------
    async def rpc_system_ping(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {"status": "pong", "timestamp": time.time(), "version": "1.0.0"}

    async def rpc_system_info(self, params: Dict[str, Any]) -> Dict[str, Any]:
        uptime_str = "0 days, 0 hours"
        try:
            with open("/proc/uptime", "r") as f:
                uptime_seconds = float(f.readline().split()[0])
                days = int(uptime_seconds // 86400)
                hours = int((uptime_seconds % 86400) // 3600)
                minutes = int((uptime_seconds % 3600) // 60)
                uptime_str = f"{days}d {hours}h {minutes}m"
        except Exception:
            uptime_str = "N/A"

        load_avg = [0.0, 0.0, 0.0]
        try:
            load_avg = list(os.getloadavg())
        except Exception:
            pass

        os_name = "Micron OS 2.0 (amd64)"
        if os.path.exists("/etc/os-release"):
            try:
                with open("/etc/os-release") as f:
                    for line in f:
                        if line.startswith("PRETTY_NAME="):
                            os_name = line.split("=", 1)[1].strip().strip('"')
                            break
            except Exception:
                pass

        return {
            "hostname": os.uname().nodename if hasattr(os, "uname") else "micron-os",
            "kernel": os.uname().release if hasattr(os, "uname") else "Linux 6.6",
            "uptime": uptime_str,
            "load_avg": load_avg,
            "os": os_name
        }

    async def rpc_system_reboot(self, params: Dict[str, Any]) -> Dict[str, Any]:
        logger.warning("System reboot requested via RPC.")
        self.loop.call_later(1.0, lambda: subprocess.run(["systemctl", "reboot"]))
        return {"status": "reboot_scheduled"}

    async def rpc_system_halt(self, params: Dict[str, Any]) -> Dict[str, Any]:
        logger.warning("System poweroff requested via RPC.")
        self.loop.call_later(1.0, lambda: subprocess.run(["systemctl", "poweroff"]))
        return {"status": "halt_scheduled"}

    async def rpc_system_commit(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Confirm the in-flight configuration transaction.

        Disarms the auto-rollback watchdog so the applied candidate stays put.
        The management plane calls this after persisting a configuration it has
        already accepted (and after every console restore).
        """
        self.tx_mgr.confirm_commit()
        return {
            "success": True,
            "status": "ok",
            "message": "Configuration committed; the rollback watchdog is disarmed.",
        }

    async def rpc_system_rollback(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Roll the running configuration back to the last verified snapshot.

        Disarms the watchdog and reloads the snapshot captured before the last
        apply. `restored` distinguishes an actual rollback from the benign case
        where the appliance had no snapshot to fall back to. A snapshot that
        exists but cannot be reloaded raises, so the caller sees a failure rather
        than a false success.
        """
        restored = self.tx_mgr.rollback_now()
        return {
            "success": True,
            "status": "ok",
            "restored": restored,
            "message": "Reloaded the last verified configuration snapshot."
            if restored else "No configuration snapshot was available to roll back to.",
        }

    # ------------- System Updates & Upgrade (system.updates_*, system.upgrade)
    # The appliance-wide counterpart to the per-plugin package manager: a full
    # OS/package update surface for the Web UI. Both handlers shell out to apt
    # with a *fixed* argv — no caller-supplied package name or repository ever
    # reaches the command line, and an upgrade never removes packages or
    @classmethod
    def _read_project_version(cls) -> str:
        for p in ("/usr/local/micron/VERSION", "VERSION", "/etc/micron/VERSION"):
            try:
                with open(p, "r", encoding="utf-8") as f:
                    v = f.read().strip()
                    if v:
                        return v
            except OSError:
                pass
        return "0.0.41"

    MICRONOS_VERSION = "0.0.41"

    async def rpc_system_updates_check(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Refresh apt metadata and report the packages an upgrade would move.

        Runs a *simulated* `apt-get -s upgrade` so the check is read-only, and
        parses the `Inst <name> [<old>] (<new> ...)` lines the upgrade planner
        prints. Nothing the caller sends is used.
        """
        steps: list = []
        refresh = self._run(["apt-get", "update", "-q"])
        steps.append(f"apt-get update rc={refresh.returncode}")

        sim = self._run(["apt-get", "-s", "-y", "upgrade"])
        steps.append(f"apt-get -s upgrade rc={sim.returncode}")

        packages: list = []
        for line in (sim.stdout or "").splitlines():
            if not line.startswith("Inst "):
                continue
            parts = line.split()
            if len(parts) < 2:
                continue
            current = ""
            candidate = ""
            if "[" in line and "]" in line:
                current = line.split("[", 1)[1].split("]", 1)[0].strip()
            if "(" in line and ")" in line:
                candidate = line.split("(", 1)[1].split(")", 1)[0].strip().split()[0]
            packages.append({"name": parts[1], "current": current, "candidate": candidate})

        os_name = "Micron OS 2.0 (amd64)"
        if os.path.exists("/etc/os-release"):
            try:
                with open("/etc/os-release") as f:
                    for line in f:
                        if line.startswith("PRETTY_NAME="):
                            os_name = line.split("=", 1)[1].strip().strip('"')
                            break
            except Exception:
                pass

        return {
            "success": sim.returncode == 0,
            "status": "ok" if sim.returncode == 0 else "error",
            "os": os_name,
            "version": self.MICRONOS_VERSION,
            "kernel": os.uname().release if hasattr(os, "uname") else "Linux 6.6",
            "count": len(packages),
            "packages": packages,
            "steps": steps,
            "checked_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
            "warnings": [] if sim.returncode == 0 else ["apt-get simulation returned a non-zero exit code"],
        }

    async def rpc_system_upgrade(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Upgrade every installed package on the appliance (`apt-get -y upgrade`).

        This is the appliance-wide counterpart to `plugin.upgrade`: it keeps the
        configured repositories and never removes packages, so it is a safe
        in-place point release rather than a dist-upgrade.
        """
        steps: list = []
        warnings: list = []

        refresh = self._run(["apt-get", "update", "-q"])
        steps.append(f"apt-get update rc={refresh.returncode}")
        if refresh.returncode != 0:
            warnings.append("apt-get update returned a non-zero exit code")

        res = self._run(["apt-get", "-y", "upgrade"])
        steps.append(f"apt-get -y upgrade rc={res.returncode}")
        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            warnings.append(detail[:200] if detail else "apt-get upgrade returned a non-zero exit code")

        return {
            "success": res.returncode == 0,
            "status": "applied" if res.returncode == 0 else "error",
            "version": self.MICRONOS_VERSION,
            "steps": steps,
            "warnings": warnings,
        }

    # ------------------ Network Interface Methods ------------------
    async def rpc_interface_list(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = subprocess.run(["ip", "-j", "addr", "show"], capture_output=True, text=True)
        if res.returncode == 0:
            try:
                return {"interfaces": strict_json_loads(res.stdout)}
            except Exception:
                pass
        return {"interfaces": []}

    def _dynamic_dns_sources(self) -> list:
        """
        Collects the DNS servers the dynamic links currently hold.

        Best-effort and read-only: the interface hooks write one file per link
        under DYNAMIC_DNS_DIR/<source>/<link>.dns with one address per line. A
        missing directory, an unreadable file, a comment or an unparseable line
        simply contributes nothing — a partial picture is fine, but a crash or
        an exception here would take down the RPC, so nothing is raised.
        """
        sources = []
        for source in DYNAMIC_DNS_SOURCES:
            directory = os.path.join(DYNAMIC_DNS_DIR, source)
            if not os.path.isdir(directory):
                continue

            for path in sorted(glob.glob(os.path.join(directory, "*.dns"))):
                link = os.path.splitext(os.path.basename(path))[0]
                try:
                    with open(path, "r", encoding="utf-8", errors="replace") as fh:
                        lines = fh.read().splitlines()
                except OSError:
                    continue

                servers = []
                for raw in lines:
                    candidate = raw.strip()
                    if not candidate or candidate.startswith("#"):
                        continue
                    try:
                        address = str(ipaddress.ip_address(candidate))
                    except ValueError:
                        continue
                    if address not in servers:
                        servers.append(address)
                    if len(servers) >= DYNAMIC_DNS_MAX_SERVERS:
                        break

                if servers:
                    sources.append({"source": source, "interface": link, "servers": servers})

        return sources

    async def rpc_interface_dynamic_dns(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Reports the DNS servers assigned to the appliance by dynamic links.

        The interface path calls this after a successful apply and merges the
        result into the appliance stub resolver while the DNS Server Override is
        enabled. These addresses are the *firewall's* own resolvers: they are
        never handed to DHCP clients, and the Unbound resolver that serves the
        LAN is unaffected.
        """
        sources = self._dynamic_dns_sources()
        return {
            "success": True,
            "status": "ok",
            "directory": DYNAMIC_DNS_DIR,
            "sources": sources,
            "servers": [address for entry in sources for address in entry["servers"]],
        }

    async def rpc_interface_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Legacy name for network.apply (same transaction engine)."""
        return await self.rpc_network_apply(params)

    async def rpc_interface_confirm(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Legacy name for network.confirm."""
        return await self.rpc_network_confirm(params)

    # ------------------ Bridge Interface Methods ------------------
    async def rpc_bridge_list(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Enumerate active Linux kernel network bridges and their member ports."""
        bridges = []
        try:
            net_path = "/sys/class/net"
            if os.path.isdir(net_path):
                for dev in os.listdir(net_path):
                    br_dir = os.path.join(net_path, dev, "bridge")
                    if os.path.isdir(br_dir):
                        slaves = []
                        brif_dir = os.path.join(net_path, dev, "brif")
                        if os.path.isdir(brif_dir):
                            slaves = sorted(os.listdir(brif_dir))

                        stp_val = 0
                        stp_file = os.path.join(br_dir, "stp_state")
                        if os.path.isfile(stp_file):
                            try:
                                with open(stp_file, "r") as f:
                                    stp_val = int(f.read().strip())
                            except Exception:
                                pass

                        operstate = "unknown"
                        oper_file = os.path.join(net_path, dev, "operstate")
                        if os.path.isfile(oper_file):
                            try:
                                with open(oper_file, "r") as f:
                                    operstate = f.read().strip()
                            except Exception:
                                pass

                        bridges.append({
                            "id": dev,
                            "name": dev,
                            "slaves": slaves,
                            "stp": stp_val,
                            "status": operstate,
                        })
        except Exception as e:
            logger.warning(f"Failed scanning bridges from sysfs: {e}")

        return {"success": True, "status": "ok", "bridges": bridges}

    async def rpc_bridge_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Create or update a Linux kernel network bridge and attach slave interfaces."""
        br_id = str(params.get("id", "br0")).strip().lower()
        if not br_id or not IF_NAME_RE.match(br_id):
            raise ValueError(f"Invalid bridge identifier: {br_id}")

        slaves = [str(s).strip() for s in params.get("slaves", []) if str(s).strip()]
        for s in slaves:
            if not IF_NAME_RE.match(s):
                raise ValueError(f"Invalid member interface: {s}")

        stp = 1 if params.get("stp") in (1, "1", True, "on") else 0
        mtu = int(params.get("mtu", 1500) or 1500)
        forward_delay = int(params.get("forward_delay", 2) or 2)

        # 1. Create bridge if not exists
        net_path = f"/sys/class/net/{br_id}"
        if not os.path.isdir(net_path):
            logger.info(f"Creating bridge interface: {br_id}")
            proc = await asyncio.create_subprocess_exec(
                "ip", "link", "add", "name", br_id, "type", "bridge",
                stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
            )
            await proc.communicate()

        # 2. Configure STP & forward delay
        stp_proc = await asyncio.create_subprocess_exec(
            "ip", "link", "set", br_id, "type", "bridge", "stp_state", str(stp), "forward_delay", str(forward_delay),
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
        )
        await stp_proc.communicate()

        # 3. Set MTU & bring bridge up
        await (await asyncio.create_subprocess_exec("ip", "link", "set", "dev", br_id, "mtu", str(mtu), "up")).communicate()

        # 4. Attach member interfaces
        for slave in slaves:
            if os.path.isdir(f"/sys/class/net/{slave}"):
                # Flush IP from member interface (slaves should not have L3 IP addresses)
                await (await asyncio.create_subprocess_exec("ip", "addr", "flush", "dev", slave)).communicate()
                # Attach to bridge
                await (await asyncio.create_subprocess_exec("ip", "link", "set", "dev", slave, "master", br_id, "up")).communicate()

        return {"success": True, "status": "applied", "id": br_id, "slaves": slaves, "stp": stp}

    async def rpc_bridge_delete(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Release member interfaces and delete bridge link."""
        br_id = str(params.get("id", "")).strip().lower()
        if not br_id:
            raise ValueError("Bridge ID required")

        # Detach any existing slaves
        brif_dir = f"/sys/class/net/{br_id}/brif"
        if os.path.isdir(brif_dir):
            for slave in os.listdir(brif_dir):
                await (await asyncio.create_subprocess_exec("ip", "link", "set", "dev", slave, "nomaster")).communicate()

        # Bring down and delete
        await (await asyncio.create_subprocess_exec("ip", "link", "set", "dev", br_id, "down")).communicate()
        await (await asyncio.create_subprocess_exec("ip", "link", "delete", br_id, "type", "bridge")).communicate()

        return {"success": True, "status": "deleted", "id": br_id}

    # ------------------ Firewall & NAT Methods ------------------
    async def rpc_firewall_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Atomically checks and swaps the nftables ruleset."""
        ruleset_content = params.get("ruleset", "")
        if not ruleset_content:
            return {"status": "error", "message": "No ruleset provided"}

        tmp_rules = "/tmp/nftables.test"
        with open(tmp_rules, "w") as f:
            f.write(ruleset_content)

        # 1. Syntax check
        check = subprocess.run(["nft", "-c", "-f", tmp_rules], capture_output=True, text=True)
        if check.returncode != 0:
            return {"status": "syntax_error", "error": check.stderr}

        # 2. Backup current ruleset
        try:
            snap_proc = subprocess.run(["nft", "list", "ruleset"], capture_output=True, text=True)
            if snap_proc.returncode == 0 and snap_proc.stdout:
                safe_atomic_write(NFTABLES_SNAPSHOT, snap_proc.stdout, mode=0o600)
        except Exception as exc:
            logger.warning("failed to backup nftables snapshot: %s", exc)

        # 3. Start rollback timer if safe mode enabled
        if params.get("safe_mode", False):
            self.tx_mgr.start_rollback_timer(self.loop)

        # 4. Atomic load
        load = subprocess.run(["nft", "-f", tmp_rules], capture_output=True, text=True)
        if load.returncode == 0:
            # Overwrite active /etc/nftables.conf atomically with durability barriers
            try:
                safe_atomic_write("/etc/nftables.conf", ruleset_content, mode=0o644)
            except OSError as exc:
                logger.warning("failed to write /etc/nftables.conf atomically: %s", exc)
            return {"status": "applied", "message": "Firewall rules loaded atomically"}
        else:
            return {"status": "apply_failed", "error": load.stderr}

    async def rpc_firewall_confirm(self, params: Dict[str, Any]) -> Dict[str, Any]:
        self.tx_mgr.confirm_commit()
        return {"status": "confirmed"}

    async def rpc_firewall_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = subprocess.run(["nft", "list", "ruleset"], capture_output=True, text=True)
        return {"ruleset": res.stdout, "status": "active" if res.returncode == 0 else "error"}

    # ------------------ VPN: WireGuard Methods ------------------
    async def rpc_vpn_wireguard_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Materialise /etc/wireguard/<iface>.conf and bring the live interface in line.

        The management plane compiles the configuration (WireguardCompiler —
        enabled peers only) and sends it as `config`; this handler validates
        it, writes it atomically (0600), and then:

          * `enabled` false  → `wg-quick down` (if the interface exists)
          * interface absent → `wg-quick up`
          * addresses changed → `wg-quick down` + `up` (syncconf cannot re-address)
          * otherwise        → `wg syncconf` with the `wg-quick strip`ped config,
                               which updates peers without dropping sessions.

        Before this, the handler ran `wg syncconf` against a file that nothing
        ever wrote, so every UI "reloaded" message was false (Programme G1/G2).
        """
        interface = str(params.get("interface", "wg0"))
        if not WG_IFACE_RE.fullmatch(interface):
            return {"success": False, "status": "error", "message": f"invalid WireGuard interface name: {interface[:32]!r}"}
        path = os.path.join(WG_CONF_DIR, f"{interface}.conf")
        enabled = self._diag_checkbox(params.get("enabled", True))
        steps: list = []
        warnings: list = []
        config = params.get("config")
        previous = self._read_first(path) if os.path.exists(path) else None

        if config is not None:
            refusal = self._wg_config_refusal(config)
            if refusal:
                return {"success": False, "status": "error", "message": refusal}
            try:
                safe_atomic_write(path, config if config.endswith("\n") else config + "\n", mode=0o600)
                steps.append(f"wrote {path}")
            except OSError as exc:
                return {"success": False, "status": "error", "message": f"could not write {path}: {exc}"}
        elif not os.path.exists(path):
            return {"success": False, "status": "error",
                    "message": f"no configuration has been written for {interface}; nothing to sync"}

        exists = os.path.isdir(f"/sys/class/net/{interface}")
        if not enabled:
            if exists:
                res = self._run_bounded(["wg-quick", "down", interface], timeout=30)
                steps.append(f"wg-quick down {interface} rc={res['rc']}")
                if res["rc"] != 0:
                    return {"success": False, "status": "error", "steps": steps,
                            "message": res["output"].strip()[-300:] or "wg-quick down failed"}
            return {"success": True, "status": "down", "interface": interface, "steps": steps,
                    "warnings": warnings, "message": f"{interface} is down"}

        def addresses(text: Optional[str]) -> list:
            return sorted(re.findall(r"^\s*Address\s*=\s*(.+)$", text or "", re.M))

        if not exists or addresses(previous) != addresses(config if config is not None else previous):
            if exists:
                res = self._run_bounded(["wg-quick", "down", interface], timeout=30)
                steps.append(f"wg-quick down {interface} rc={res['rc']}")
            res = self._run_bounded(["wg-quick", "up", interface], timeout=30)
            steps.append(f"wg-quick up {interface} rc={res['rc']}")
            if res["rc"] != 0:
                return {"success": False, "status": "error", "steps": steps, "interface": interface,
                        "message": res["output"].strip()[-300:] or "wg-quick up failed"}
            return {"success": True, "status": "up", "interface": interface, "steps": steps,
                    "warnings": warnings, "message": f"{interface} brought up"}

        strip = self._run_bounded(["wg-quick", "strip", interface], timeout=15)
        if strip["rc"] != 0:
            return {"success": False, "status": "error", "steps": steps,
                    "message": "wg-quick strip failed: " + strip["output"].strip()[-200:]}
        with tempfile.NamedTemporaryFile("w", suffix=".conf", delete=False) as tmp:
            tmp.write(strip["output"])
            tmp_path = tmp.name
        os.chmod(tmp_path, 0o600)
        try:
            res = self._run_bounded(["wg", "syncconf", interface, tmp_path], timeout=15)
        finally:
            try:
                os.remove(tmp_path)
            except OSError:
                pass
        steps.append(f"wg syncconf {interface} rc={res['rc']}")
        if res["rc"] != 0:
            return {"success": False, "status": "error", "steps": steps,
                    "message": res["output"].strip()[-300:] or "wg syncconf failed"}
        return {"success": True, "status": "synced", "interface": interface, "steps": steps,
                "warnings": warnings, "message": f"{interface} peers synchronised"}

    async def rpc_vpn_wireguard_service(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply one lifecycle action to all configured WireGuard interfaces."""
        action = str(params.get("action") or "")
        if action not in SERVICE_ACTIONS:
            return {"success": False, "status": "error", "message": f"unsupported action: {action}"}

        tunnels = params.get("tunnels")
        if not isinstance(tunnels, list):
            return {"success": False, "status": "error", "message": "tunnels must be a list"}

        validated = []
        seen = set()
        config_bytes = 0
        for tunnel in tunnels:
            if not isinstance(tunnel, dict):
                return {"success": False, "status": "error", "message": "each tunnel must be an object"}
            interface = str(tunnel.get("interface") or "")
            config = tunnel.get("config")
            enabled = tunnel.get("enabled")
            if not WG_IFACE_RE.fullmatch(interface) or interface in seen:
                return {"success": False, "status": "error", "message": f"invalid or duplicate WireGuard interface: {interface[:32]!r}"}
            if not isinstance(enabled, bool):
                return {"success": False, "status": "error", "message": f"enabled must be boolean for {interface}"}
            refusal = self._wg_config_refusal(config)
            if refusal:
                return {"success": False, "status": "error", "message": f"{interface}: {refusal}"}
            config_bytes += len(config.encode("utf-8"))
            if config_bytes > 8 * 1024 * 1024:
                return {"success": False, "status": "error", "message": "aggregate WireGuard configs exceed the RPC size limit"}
            seen.add(interface)
            validated.append({"interface": interface, "config": config, "enabled": enabled})

        if not validated:
            return {"success": True, "status": "noop", "service": "wireguard", "action": action,
                    "state": "stopped", "tunnels": [], "message": "No WireGuard tunnels are configured."}

        failures = []
        results = []

        async def apply(tunnel: Dict[str, Any], enabled: bool) -> bool:
            result = await self.rpc_vpn_wireguard_sync({
                "interface": tunnel["interface"],
                "enabled": enabled,
                "config": tunnel["config"],
            })
            ok = result.get("success") is True and result.get("status") != "error"
            results.append({"interface": tunnel["interface"], "enabled": enabled, "success": ok,
                            "status": result.get("status", "error")})
            if not ok:
                failures.append(f"{tunnel['interface']}: {result.get('message', 'daemon reported a failure')}")
            return ok

        if action == "restart":
            stopped = set()
            for tunnel in validated:
                if await apply(tunnel, False):
                    stopped.add(tunnel["interface"])
            for tunnel in validated:
                if tunnel["enabled"] and tunnel["interface"] in stopped:
                    await apply(tunnel, True)
        else:
            for tunnel in validated:
                await apply(tunnel, action == "start" and tunnel["enabled"])

        success = not failures
        state = "stopped" if action == "stop" or not any(t["enabled"] for t in validated) else "running"
        message = f"WireGuard tunnels {action}ed." if action != "stop" else "WireGuard tunnels stopped."
        if failures:
            message = "WireGuard tunnel action failed: " + "; ".join(failures)

        return {"success": success, "status": "applied" if success else "error", "service": "wireguard",
                "action": action, "state": state if success else "unknown", "tunnels": results,
                "message": message, "warnings": failures}

    @staticmethod
    def _wg_config_refusal(config: Any) -> Optional[str]:
        if not isinstance(config, str) or "[Interface]" not in config:
            return "config must be a WireGuard configuration with an [Interface] section"
        if len(config.encode("utf-8")) > 262144 or "\x00" in config:
            return "config is too large or contains a NUL byte"
        # wg-quick runs these hooks through a root shell; a compiled
        # configuration never needs them, so none may arrive over the socket.
        for line in config.splitlines():
            key = line.split("=", 1)[0].strip().lower()
            if key in ("preup", "postup", "predown", "postdown", "saveconfig"):
                return f"{key} hooks are not accepted in a managed WireGuard configuration"
        return None

    async def rpc_vpn_wireguard_remove(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Tear down an interface and delete its configuration file."""
        interface = str(params.get("interface") or "").strip()
        if not WG_IFACE_RE.fullmatch(interface):
            return {"success": False, "status": "error", "message": "invalid WireGuard interface name"}
        steps = []
        if os.path.isdir(f"/sys/class/net/{interface}"):
            res = self._run_bounded(["wg-quick", "down", interface], timeout=30)
            steps.append(f"wg-quick down {interface} rc={res['rc']}")
        path = os.path.join(WG_CONF_DIR, f"{interface}.conf")
        if os.path.exists(path):
            os.remove(path)
            steps.append(f"removed {path}")
        return {"success": True, "status": "removed", "interface": interface, "steps": steps}

    async def rpc_vpn_wireguard_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        interface = str(params.get("interface") or "").strip()
        if interface and not WG_IFACE_RE.fullmatch(interface):
            return {"status": "error", "interfaces": {}, "peers": [],
                    "message": "invalid WireGuard interface name"}

        res = self._run_bounded(["wg", "show", "all", "dump"], timeout=10)
        if res["rc"] != 0:
            return {"status": "error", "interfaces": {}, "peers": [],
                    "message": res["output"].strip()[-300:] or "wg show failed"}

        interfaces: Dict[str, Dict[str, Any]] = {}
        peers: list = []
        for line in res["output"].splitlines():
            fields = line.split("\t")
            if len(fields) == 5:
                name, _private_key, public_key, listen_port, _fwmark = fields
                if interface and name != interface:
                    continue
                try:
                    port = int(listen_port)
                except ValueError:
                    port = 0
                interfaces[name] = {
                    "interface": name,
                    "public_key": public_key,
                    "listen_port": port,
                    "status": "up",
                }
            elif len(fields) >= 9:
                name, public_key, _preshared_key, endpoint, allowed_ips, handshake, rx, tx, keepalive = fields[:9]
                if interface and name != interface:
                    continue
                try:
                    latest_handshake = int(handshake)
                    rx_bytes = int(rx)
                    tx_bytes = int(tx)
                    keepalive_seconds = int(keepalive)
                except ValueError:
                    continue
                peers.append({
                    "interface": name,
                    "peer": public_key,
                    "endpoint": "" if endpoint == "(none)" else endpoint,
                    "allowed_ips": allowed_ips,
                    "latest_handshake": latest_handshake,
                    "rx_bytes": rx_bytes,
                    "tx_bytes": tx_bytes,
                    "persistent_keepalive": keepalive_seconds,
                })

        return {"status": "ok", "interfaces": interfaces, "peers": peers}

    async def rpc_vpn_wireguard_genkey(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Generates a WireGuard private/public keypair using wg genkey | wg pubkey, with cryptographic fallback."""
        priv_key = ""
        pub_key = ""
        try:
            p_gen = subprocess.run(["wg", "genkey"], capture_output=True, text=True, timeout=5)
            if p_gen.returncode == 0 and p_gen.stdout.strip():
                priv_key = p_gen.stdout.strip()
                p_pub = subprocess.run(["wg", "pubkey"], input=priv_key, capture_output=True, text=True, timeout=5)
                if p_pub.returncode == 0 and p_pub.stdout.strip():
                    pub_key = p_pub.stdout.strip()
        except Exception as e:
            logger.warning(f"wg genkey execution failed: {e}")

        if not priv_key or not pub_key:
            try:
                from cryptography.hazmat.primitives.asymmetric import x25519
                from cryptography.hazmat.primitives import serialization
                priv_obj = x25519.X25519PrivateKey.generate()
                priv_bytes = priv_obj.private_bytes(
                    encoding=serialization.Encoding.Raw,
                    format=serialization.PrivateFormat.Raw,
                    encryption_algorithm=serialization.NoEncryption()
                )
                pub_bytes = priv_obj.public_key().public_bytes(
                    encoding=serialization.Encoding.Raw,
                    format=serialization.PublicFormat.Raw
                )
                priv_key = base64.b64encode(priv_bytes).decode("ascii")
                pub_key = base64.b64encode(pub_bytes).decode("ascii")
            except Exception as e:
                # Never fabricate a pair: a random public key unrelated to the
                # private key produces a tunnel that can never handshake.
                logger.error(f"Fallback keypair generation failed: {e}")
                return {"success": False, "status": "error", "private_key": "", "public_key": "",
                        "message": "neither `wg` nor the cryptography module is available to generate a keypair"}

        return {"success": True, "status": "ok", "private_key": priv_key, "public_key": pub_key}

    async def rpc_vpn_wireguard_pubkey(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Calculates public key from private key."""
        priv_key = params.get("private_key", "").strip()
        pub_key = ""
        if priv_key:
            try:
                p_pub = subprocess.run(["wg", "pubkey"], input=priv_key, capture_output=True, text=True, timeout=5)
                if p_pub.returncode == 0 and p_pub.stdout.strip():
                    pub_key = p_pub.stdout.strip()
            except Exception:
                pass
            if not pub_key:
                try:
                    from cryptography.hazmat.primitives.asymmetric import x25519
                    from cryptography.hazmat.primitives import serialization
                    priv_bytes = base64.b64decode(priv_key, validate=True)
                    priv_obj = x25519.X25519PrivateKey.from_private_bytes(priv_bytes)
                    pub_bytes = priv_obj.public_key().public_bytes(
                        encoding=serialization.Encoding.Raw,
                        format=serialization.PublicFormat.Raw
                    )
                    pub_key = base64.b64encode(pub_bytes).decode("ascii")
                except Exception:
                    pass
        return {"public_key": pub_key}

    async def rpc_vpn_wireguard_pskgen(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Generates a random 32-byte WireGuard pre-shared key."""
        psk = ""
        try:
            p_psk = subprocess.run(["wg", "genpsk"], capture_output=True, text=True, timeout=5)
            if p_psk.returncode == 0 and p_psk.stdout.strip():
                psk = p_psk.stdout.strip()
        except Exception:
            pass
        if not psk:
            psk = base64.b64encode(os.urandom(32)).decode("ascii")
        return {"preshared_key": psk}

    # ------------------ VPN: strongSwan IPsec Methods ------------------
    async def rpc_vpn_ipsec_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Reloads swanctl IPsec configuration."""
        res = subprocess.run(["swanctl", "--load-all"], capture_output=True, text=True)
        return {"status": "reloaded" if res.returncode == 0 else "error", "output": res.stdout + res.stderr}

    async def rpc_vpn_ipsec_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = subprocess.run(["swanctl", "--list-sas"], capture_output=True, text=True)
        return {"sas": res.stdout, "status": "ok" if res.returncode == 0 else "error"}

    # ------------------ Web Filter & Proxy Methods ------------------
    async def rpc_webfilter_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Reloads the running Squid configuration, reporting success explicitly."""
        res = self._run(["squid", "-k", "reconfigure"])
        ok = res.returncode == 0
        return {"success": ok, "status": "reconfigured" if ok else "error", "output": res.stdout + res.stderr}

    async def rpc_webfilter_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Live Squid telemetry for the Web Filter dashboard.

        The cache manager (`mgr:info`) is the authoritative source for hit
        ratios, memory and swap usage, so it is preferred whenever it answers.
        When it is unreachable those three figures fall back to figures derived
        from `ps` and the access log; if even that yields nothing the value is
        null rather than a fabricated number. Every source is best-effort so a
        single missing tool degrades one field, not the whole panel:
          * `systemctl is-active squid`   -> running state
          * Squid cache manager           -> hit ratio, memory, swap usage
          * `ps`                          -> uptime and resident memory
          * the access log (bounded)      -> today's requests, denials, hit ratio
          * squid.conf + the spool dir    -> disk cache used / capacity
        """
        running = self._run(["systemctl", "is-active", "squid"]).stdout.strip() == "active"
        log_stats = self._squid_access_log_stats(SQUID_ACCESS_LOG)

        port = int(params.get("port") or 0) or self._squid_conf_int(r"^http_port\s+(\d+)", SQUID_CONF) or 3128
        manager = self._squid_manager_info(port)

        hit_rate = self._squid_manager_hit_ratio(manager) if manager else None
        mem_usage = self._squid_manager_memory(manager) if manager else None
        disk_usage = self._squid_manager_disk(manager) if manager else None

        return {
            "running": running,
            "cache_manager": manager is not None,
            "uptime": self._squid_uptime(),
            "requests_today": log_stats["requests"],
            "blocked_today": log_stats["denied"],
            "cache_hit_rate": hit_rate if hit_rate is not None else log_stats["hit_rate"],
            "mem_usage": mem_usage if mem_usage is not None else self._squid_mem_usage(),
            "disk_usage": disk_usage if disk_usage is not None else self._squid_disk_usage(),
        }

    async def rpc_squid_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_webfilter_status(params)

    async def rpc_webfilter_cache_clear(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Flushes and rebuilds the Squid disk cache.

        Stop -> wipe the spool contents -> `squid -z` (recreate the swap
        directories) -> start. The spool path is pinned to a recognised Squid
        cache directory so a malformed request can never widen the delete to
        an arbitrary path, and every external call is an argv list (no shell).
        """
        spool = str(params.get("spool_dir") or SQUID_SPOOL_DIR).rstrip("/")
        if not re.fullmatch(r"/var/spool/squid[0-9]*", spool):
            logger.warning(f"Refusing to purge unrecognised Squid spool path: {spool}")
            return {"success": False, "status": "error",
                    "message": f"Refusing to purge unrecognised spool path: {spool}"}

        freed = self._dir_size_bytes(spool)

        stop = self._run(["systemctl", "stop", "squid"])
        if stop.returncode != 0:
            return {"success": False, "status": "error",
                    "message": "Could not stop squid: " + stop.stderr.strip()}

        removed = self._purge_directory(spool)

        rebuild = self._run(["squid", "-z"])
        if rebuild.returncode != 0:
            # Bring the proxy back up anyway rather than leaving it down.
            self._run(["systemctl", "start", "squid"])
            return {"success": False, "status": "error",
                    "message": "Cache rebuild failed: " + (rebuild.stderr or rebuild.stdout).strip(),
                    "bytes_freed": freed, "entries_removed": removed}

        start = self._run(["systemctl", "start", "squid"])
        if start.returncode != 0:
            return {"success": False, "status": "error",
                    "message": "Cache rebuilt but squid failed to restart: " + start.stderr.strip(),
                    "bytes_freed": freed, "entries_removed": removed}

        logger.info(f"Squid cache purged: {removed} entries, {freed} bytes freed.")
        return {"success": True, "status": "purged",
                "bytes_freed": freed, "entries_removed": removed}

    async def rpc_squid_cache_clear(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_webfilter_cache_clear(params)

    async def rpc_webfilter_access_log(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Most recent Squid access-log entries for the Real-Time monitor.

        Reads only the tail of the log (the newest lines) so the call stays
        cheap on a busy proxy, then parses each line into the structured shape
        the dashboard renders. The list is newest-first.
        """
        try:
            limit = int(params.get("limit") or 40)
        except (TypeError, ValueError):
            limit = 40
        limit = max(1, min(200, limit))

        entries = self._squid_access_log_tail(SQUID_ACCESS_LOG, limit)
        return {"success": True, "status": "ok", "entries": entries, "count": len(entries)}

    async def rpc_squid_access_log(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_webfilter_access_log(params)

    # ------------------ Squid telemetry helpers ------------------
    @staticmethod
    def _dir_size_bytes(path: str) -> int:
        """Recursive size of a directory tree, best-effort (0 when absent)."""
        total = 0
        for root, _dirs, files in os.walk(path):
            for name in files:
                try:
                    total += os.path.getsize(os.path.join(root, name))
                except OSError:
                    continue
        return total

    @staticmethod
    def _purge_directory(path: str) -> int:
        """Removes the *contents* of a directory, keeping the directory itself."""
        import shutil
        try:
            entries = os.listdir(path)
        except OSError:
            return 0

        removed = 0
        for name in entries:
            target = os.path.join(path, name)
            try:
                if os.path.isdir(target) and not os.path.islink(target):
                    shutil.rmtree(target, ignore_errors=True)
                else:
                    os.remove(target)
                removed += 1
            except OSError as exc:
                logger.warning(f"Could not purge {target}: {exc}")
        return removed

    def _squid_uptime(self) -> Optional[str]:
        """Age of the longest-running squid process, formatted like `6 days, 14:22:10`."""
        res = self._run(["ps", "-o", "etimes=", "-C", "squid"])
        seconds = 0
        for token in res.stdout.split():
            try:
                seconds = max(seconds, int(token))
            except ValueError:
                continue
        if seconds <= 0:
            return None
        days, rem = divmod(seconds, 86400)
        hours, minutes, secs = (rem // 3600), (rem % 3600) // 60, rem % 60
        return f"{days} days, {hours:02d}:{minutes:02d}:{secs:02d}"

    def _squid_mem_usage(self) -> str:
        """Resident memory across squid processes against the configured cache_mem."""
        res = self._run(["ps", "-o", "rss=", "-C", "squid"])
        rss_kb = 0
        for token in res.stdout.split():
            try:
                rss_kb += int(token)
            except ValueError:
                continue
        configured = self._squid_conf_int(r"^cache_mem\s+(\d+)\s*MB", SQUID_CONF)
        if configured:
            return f"{rss_kb / 1024:.0f} MB / {configured} MB"
        return f"{rss_kb / 1024:.0f} MB"

    def _squid_disk_usage(self) -> str:
        """Spool directory size against the configured cache_dir capacity."""
        used = self._dir_size_bytes(SQUID_SPOOL_DIR)
        configured_mb = self._squid_conf_int(r"^cache_dir\s+ufs\s+\S+\s+(\d+)", SQUID_CONF)
        if configured_mb:
            return f"{self._fmt_size(used)} / {self._fmt_size(configured_mb * 1024 * 1024)}"
        return self._fmt_size(used)

    def _squid_manager_info(self, port: int) -> Optional[str]:
        """
        Fetches Squid's `mgr:info` cache-manager page over the loopback proxy.

        Uses a proxy-free urllib opener so an outbound HTTP proxy on the
        appliance can never intercept a localhost management request. Returns
        None whenever the manager is unreachable, which the caller treats as
        "fall back to the derived figures".
        """
        url = f"http://127.0.0.1:{port}/squid-internal-mgr/info"
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(url, timeout=3) as response:
                return response.read().decode("utf-8", "replace")
        except Exception as exc:
            logger.info(f"Squid cache manager unreachable on port {port}: {exc}")
            return None

    @staticmethod
    def _squid_manager_hit_ratio(text: Optional[str]) -> Optional[str]:
        """Request hit ratio from `mgr:info`, preferring the 5-minute window."""
        if not text:
            return None
        match = re.search(r"Request Hit Ratios:\s*(.+)", text)
        if not match:
            return None
        window = match.group(1)
        five = re.search(r"5min:\s*([\d.]+)%", window, re.IGNORECASE)
        if five:
            return f"{five.group(1)}%"
        any_pct = re.search(r"([\d.]+)%", window)
        return f"{any_pct.group(1)}%" if any_pct else None

    def _squid_manager_memory(self, text: Optional[str]) -> Optional[str]:
        """Squid's own memory figure from `mgr:info` against configured cache_mem."""
        if not text:
            return None
        match = re.search(r"Memory usage for squid:\s*([\d.]+)\s*KB", text, re.IGNORECASE)
        if not match:
            return None
        used = int(float(match.group(1)) * 1024)
        configured = self._squid_conf_int(r"^cache_mem\s+(\d+)\s*MB", SQUID_CONF)
        if configured:
            return f"{used / (1024 ** 2):.0f} MB / {configured} MB"
        return self._fmt_size(used)

    def _squid_manager_disk(self, text: Optional[str]) -> Optional[str]:
        """Swap capacity/usage from `mgr:info`, falling back to the spool dir."""
        if not text:
            return None
        capacity = re.search(
            r"Storage Swap capacity:\s*([\d.]+)\s*(TB|GB|MB|KB),\s*([\d.]+)%", text, re.IGNORECASE
        )
        if capacity:
            total = self._to_bytes(float(capacity.group(1)), capacity.group(2))
            used = total * float(capacity.group(3)) / 100
            return f"{self._fmt_size(used)} / {self._fmt_size(total)}"
        size = re.search(r"Storage (?:Swap|Mem) size:\s*([\d.]+)\s*KB", text, re.IGNORECASE)
        if size:
            used = int(float(size.group(1)) * 1024)
            configured = self._squid_conf_int(r"^cache_dir\s+ufs\s+\S+\s+(\d+)", SQUID_CONF)
            if configured:
                return f"{self._fmt_size(used)} / {self._fmt_size(configured * 1024 ** 2)}"
            return self._fmt_size(used)
        return None

    @staticmethod
    def _to_bytes(value: float, unit: str) -> float:
        factor = {"B": 1, "KB": 1024, "MB": 1024 ** 2, "GB": 1024 ** 3, "TB": 1024 ** 4}
        return value * factor.get(unit.upper(), 1)

    @staticmethod
    def _fmt_size(num_bytes: float) -> str:
        if num_bytes >= 1024 ** 3:
            return f"{num_bytes / (1024 ** 3):.1f} GB"
        return f"{num_bytes / (1024 ** 2):.1f} MB"

    @staticmethod
    def _squid_conf_int(pattern: str, path: str) -> int:
        """Reads the first integer captured by `pattern` from a Squid config file."""
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                for line in handle:
                    match = re.match(pattern, line.strip())
                    if match:
                        return int(match.group(1))
        except OSError:
            pass
        return 0

    def _squid_access_log_stats(self, path: str) -> Dict[str, Any]:
        """
        Today's request/denial counts and hit ratio from the Squid access log.

        Only the last few MB are read (the tail holds today's entries) and the
        first, possibly partial, line after the seek is discarded.
        """
        hit_tags = {"TCP_HIT", "TCP_MEM_HIT", "TCP_IMS_HIT", "TCP_NEGATIVE_HIT",
                    "TCP_REFRESH_UNMODIFIED", "TCP_REFRESH_HIT", "TCP_REPEAT"}
        miss_tags = {"TCP_MISS", "TCP_REFRESH_MISS", "TCP_CLIENT_REFRESH_MISS",
                     "TCP_SWAPFAIL_MISS", "TCP_REFRESH_MODIFIED"}

        stats: Dict[str, Any] = {"requests": 0, "denied": 0, "hits": 0, "misses": 0, "hit_rate": None}
        try:
            size = os.path.getsize(path)
        except OSError:
            return stats

        max_bytes = 32 * 1024 * 1024
        day_start = time.mktime(time.localtime()[:3] + (0, 0, 0, 0, 0, -1))

        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                if size > max_bytes:
                    handle.seek(size - max_bytes)
                    handle.readline()  # discard the partial leading line
                for line in handle:
                    parts = line.split()
                    if len(parts) < 4:
                        continue
                    try:
                        if float(parts[0]) < day_start:
                            continue
                    except ValueError:
                        continue
                    tag = parts[3].split("/", 1)[0]
                    if not tag.startswith("TCP_"):
                        continue
                    stats["requests"] += 1
                    if tag == "TCP_DENIED":
                        stats["denied"] += 1
                    elif tag in miss_tags:
                        stats["misses"] += 1
                    elif tag in hit_tags:
                        stats["hits"] += 1
        except OSError:
            return stats

        considered = stats["hits"] + stats["misses"]
        if considered:
            stats["hit_rate"] = f"{stats['hits'] / considered * 100:.1f}%"
        return stats

    # Cache outcome tags that mean the request was served from cache.
    SQUID_HIT_TAGS = {"TCP_HIT", "TCP_MEM_HIT", "TCP_IMS_HIT", "TCP_NEGATIVE_HIT",
                      "TCP_REFRESH_UNMODIFIED", "TCP_REFRESH_HIT", "TCP_REPEAT"}

    def _read_tail_lines(self, path: str, limit: int, max_bytes: int = 1024 * 1024) -> list:
        """Last `limit` non-empty lines of a file, in file order (cheap tail)."""
        try:
            size = os.path.getsize(path)
        except OSError:
            return []
        if size == 0:
            return []

        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                if size > max_bytes:
                    handle.seek(size - max_bytes)
                    handle.readline()  # discard the partial leading line
                lines = [ln for ln in (line.rstrip("\n") for line in handle) if ln]
        except OSError:
            return []

        return lines[-limit:]

    def _parse_squid_access_line(self, line: str) -> Optional[Dict[str, Any]]:
        """Parses one native Squid access-log line into a dashboard entry."""
        parts = line.split()
        if len(parts) < 7:
            return None
        try:
            ts = float(parts[0])
        except ValueError:
            return None

        result = parts[3]
        tag = result.split("/", 1)[0]
        status = result.split("/", 1)[1] if "/" in result else ""

        if tag == "TCP_DENIED":
            action = "BLOCK"
        elif tag in ("TCP_TUNNEL", "TCP_TUNNEL_PEEK"):
            action = "BYPASS_SSL"
        else:
            action = "ALLOW"

        url = parts[6]
        try:
            domain = urllib.parse.urlparse(url).hostname or ""
        except ValueError:
            domain = ""

        def _as_int(value: str) -> int:
            try:
                return int(value)
            except (TypeError, ValueError):
                return 0

        return {
            "time": time.strftime("%H:%M:%S", time.localtime(ts)),
            "timestamp": ts,
            "client": parts[2],
            "result": result,
            "cache_status": tag,
            "cached": tag in self.SQUID_HIT_TAGS,
            "action": action,
            "status": _as_int(status),
            "bytes": _as_int(parts[4]),
            "duration_ms": _as_int(parts[1]),
            "method": parts[5],
            "url": url,
            "domain": domain,
            "user": parts[7] if len(parts) > 7 and parts[7] != "-" else "",
        }

    def _squid_access_log_tail(self, path: str, limit: int) -> list:
        """Newest-first parsed access-log entries (best-effort, [] on failure)."""
        entries = []
        for line in self._read_tail_lines(path, limit):
            entry = self._parse_squid_access_line(line)
            if entry is not None:
                entries.append(entry)
        # Newest-first by event time (robust even if the log is not strictly
        # chronological, e.g. after log rotation).
        entries.sort(key=lambda e: e["timestamp"], reverse=True)
        return entries

    # ------------------ Multicast & IGMP Methods ------------------
    async def rpc_multicast_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Applies the multicast/IGMP configuration compiled by the PHP control
        plane:
          * igmpproxy        -> /etc/igmpproxy.conf + restart
          * FRR pimd         -> a `vtysh -f` script (PIM + IGMP enablement)
          * bridge snooping  -> /sys/class/net/<bridge>/bridge/* (whitelisted)
          * mDNS repeater    -> /etc/mdns-repeater.conf + restart

        Every write/command is validated first and each step's outcome is
        reported, so a partial failure is visible rather than reported as a
        clean success.
        """
        steps: list = []
        warnings = list(params.get("warnings") or [])
        enabled = bool(params.get("enabled"))

        igmp = params.get("igmp_proxy") or {}
        pim = params.get("pim") or {}
        snooping = params.get("snooping") or {}
        mdns = params.get("mdns") or {}

        # 1. IGMP proxy
        if enabled and igmp.get("enabled") and str(igmp.get("config") or "").strip():
            if self._write_text(IGMPPROXY_CONF, str(igmp["config"])):
                steps.append("wrote " + IGMPPROXY_CONF)
                res = self._run(["systemctl", "restart", "igmpproxy"])
                steps.append(f"igmpproxy restart rc={res.returncode}")
            else:
                warnings.append("could not write " + IGMPPROXY_CONF)
        else:
            self._run(["systemctl", "stop", "igmpproxy"])
            steps.append("igmpproxy stopped")

        # 2. PIM/IGMP via FRR pimd
        if enabled and pim.get("enabled") and str(pim.get("commands") or "").strip():
            if self._write_text(MULTICAST_PIM_FILE, str(pim["commands"])):
                res = self._run(["vtysh", "-f", MULTICAST_PIM_FILE])
                steps.append(f"vtysh -f rc={res.returncode}")
            else:
                warnings.append("could not write " + MULTICAST_PIM_FILE)

        # 3. Bridge snooping (sysfs), strictly whitelisted
        bridge = str(snooping.get("bridge") or "")
        sysfs = snooping.get("sysfs") or {}
        if enabled and bridge and sysfs:
            if not IF_NAME_RE.match(bridge):
                warnings.append("refusing invalid bridge name: " + bridge)
            else:
                for attr, value in sysfs.items():
                    if attr not in SNOOPING_SYSFS_ATTRS or not str(value).isdigit():
                        warnings.append("refusing sysfs write: " + str(attr))
                        continue
                    path = "/sys/class/net/{}/bridge/{}".format(bridge, attr)
                    if self._write_text(path, str(value)):
                        steps.append(f"set {path}={value}")
                    else:
                        warnings.append("could not set " + path)

        # 4. mDNS repeater
        mdns_ifaces = [str(i) for i in (mdns.get("interfaces") or []) if IF_NAME_RE.match(str(i))]
        if enabled and mdns.get("enabled") and len(mdns_ifaces) >= 2:
            conf = "# MicronOS mDNS repeater - generated.\n" + "\n".join(mdns_ifaces) + "\n"
            if self._write_text(MDNS_REPEATER_CONF, conf):
                steps.append("wrote " + MDNS_REPEATER_CONF)
                res = self._run(["systemctl", "restart", "mdns-repeater"])
                steps.append(f"mdns-repeater restart rc={res.returncode}")
            else:
                warnings.append("could not write " + MDNS_REPEATER_CONF)
        else:
            self._run(["systemctl", "stop", "mdns-repeater"])
            steps.append("mdns-repeater stopped")

        # Report the *actual* systemd run-state of the daemons we just touched,
        # so the management plane can mirror it into system_services and the
        # page-header service widget stays truthful. FRR is deliberately not
        # included: it also serves routing, so its run-state is not ours to own.
        return {
            "success": True,
            "status": "applied" if enabled else "disabled",
            "steps": steps,
            "warnings": warnings,
            "services": {
                "igmpproxy": self._service_state("igmpproxy"),
                "mdns-repeater": self._service_state("mdns-repeater"),
            },
        }

    def _service_state(self, service: str) -> str:
        """Live systemd run-state: 'running' when active, else 'stopped'."""
        res = self._run(["systemctl", "is-active", service])
        return "running" if res.stdout.strip() == "active" else "stopped"

    async def rpc_multicast_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Best-effort running state of the multicast daemons."""
        return {
            "available": True,
            "igmpproxy": self._service_state("igmpproxy"),
            "pim": self._service_state("frr"),
            "mdns": self._service_state("mdns-repeater"),
        }

    @staticmethod
    def _write_text(path: str, content: str) -> bool:
        """Writes a file atomically with fsync durability, creating its parent directory when needed."""
        try:
            safe_atomic_write(path, content, mode=0o644)
            return True
        except OSError as exc:
            logger.warning("multicast: write failed for %s: %s", path, exc)
            return False

    # ------------------ System Service Control (system.service) ------------------
    async def rpc_system_service(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Start/stop/restart a whitelisted systemd unit — the **admin** path.

        The Services controller calls this for every page's service-control
        widget, so it keeps the full SERVICE_UNITS whitelist: an authenticated
        operator may legitimately restart nftables or unbound.

        Connectors must NOT use this method. They call `connector.service`,
        which is held to CONNECTOR_SAFE_UNITS (see rpc_connector_service).
        """
        return self._service_action(params, SERVICE_UNITS)

    async def rpc_connector_service(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Connector-scoped service control.

        Manifest-driven connectors are *untrusted data*, so they are held to a
        strict allowlist and can never stop a safety-critical unit — nftables,
        unbound, strongswan, wireguard, keepalived, FRR or anything else outside
        CONNECTOR_SAFE_UNITS.

        This is deliberately a separate RPC rather than a `scope` flag on
        `system.service`: a caller able to set a flag can also clear it, so only
        a distinct method name makes the restriction non-bypassable by
        connector-supplied data. The connector adapter picks the method; the
        manifest only picks the service id.
        """
        return self._service_action(params, CONNECTOR_SAFE_UNITS, connector=True)

    def _service_action(
        self, params: Dict[str, Any], units: Dict[str, str], connector: bool = False
    ) -> Dict[str, Any]:
        """Shared start/stop/restart core for the admin and connector paths."""
        service = str(params.get("service") or "")
        action = str(params.get("action") or "")
        unit = units.get(service)

        if unit is None:
            reason = ("refusing service not permitted for connectors: " if connector
                      else "refusing unknown service: ")
            return {"success": False, "status": "error", "message": reason + service}
        if action not in SERVICE_ACTIONS:
            return {"success": False, "status": "error",
                    "message": f"unsupported action: {action}"}
        if not UNIT_RE.match(unit):
            return {"success": False, "status": "error",
                    "message": "resolved unit name failed validation"}

        steps: list = []
        warnings: list = []
        if service == "nftables" and action == "stop":
            warnings.append("stopping nftables removes the packet filter until it is started again")

        res = self._run(["systemctl", action, unit])
        if res.returncode != 0 and service == "gateway-monitor":
            res_probe = self._run(["systemctl", action, "micron-sdwan-probe"])
            if res_probe.returncode == 0:
                res = res_probe
                unit = "micron-sdwan-probe"
        steps.append(f"systemctl {action} {unit} rc={res.returncode}")
        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            warnings.append(detail[:200] if detail else "systemctl returned a non-zero exit code")

        return {
            "success": res.returncode == 0,
            "status": "applied" if res.returncode == 0 else "error",
            "service": service,
            "unit": unit,
            "action": action,
            "state": self._service_state(unit),
            "steps": steps,
            "message": detail[:300] if res.returncode != 0 and detail else "",
            "warnings": warnings,
        }

    # ------------- Plugin & Package Manager (plugin.*) -------------
    # The management plane only ever sends a plugin *id*; every package,
    # unit and capability is resolved here against the fixed PLUGIN_CATALOG
    # (Plugin Spec §5, PL1). No caller-supplied package name ever reaches apt.
    async def rpc_plugin_catalog(self, params: Dict[str, Any]) -> Dict[str, Any]:
        plugins = [
            {"id": pid,
             "packages": spec["packages"],
             "units": list(spec["units"].values()),
             "capabilities": spec["capabilities"]}
            for pid, spec in sorted(PLUGIN_CATALOG.items())
        ]
        return {"success": True, "status": "ok", "plugins": plugins}

    def _plugin_entry(self, plugin_id: str) -> Optional[Dict[str, Any]]:
        return PLUGIN_CATALOG.get(plugin_id)

    def _plugin_refusal(self, entry: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        """Refuse a plugin whose packages or units cross the protected core."""
        blocked = [p for p in entry["packages"] if p in PROTECTED_PACKAGES]
        protected_units = [u for u in entry["units"].values() if u in PROTECTED_UNITS]
        if not blocked and not protected_units:
            return None
        reasons = [f"refusing protected package: {p}" for p in blocked]
        reasons += [f"refusing protected unit: {u}" for u in protected_units]
        return {"success": False, "status": "blocked", "message": "; ".join(reasons),
                "warnings": reasons}

    async def rpc_plugin_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        plugin_id = str(params.get("id") or "")
        entry = self._plugin_entry(plugin_id)
        if entry is None:
            return {"success": False, "status": "error", "message": f"unknown plugin: {plugin_id}"}
        units = list(entry["units"].values())
        state = self._service_state(units[0]) if units else "no-service"
        return {"success": True, "status": "ok", "id": plugin_id, "state": state,
                "packages": entry["packages"], "units": units}

    async def rpc_plugin_install(self, params: Dict[str, Any]) -> Dict[str, Any]:
        plugin_id = str(params.get("id") or "")
        entry = self._plugin_entry(plugin_id)
        if entry is None:
            return {"success": False, "status": "error", "message": f"unknown plugin: {plugin_id}"}

        refusal = self._plugin_refusal(entry)
        if refusal is not None:
            return refusal

        # Class C: a plugin whose package comes from a third-party repository is
        # refused unless that repository is actually APPLIED on this appliance.
        # The management plane derives the same answer from `plugin_repos`, but
        # that is database state: this checks the sources file the daemon itself
        # generated, so an enabled-then-never-applied repository cannot be
        # bypassed into installing vendor packages.
        unapplied = [
            repo_id for repo_id in entry.get("repos", [])
            if not os.path.exists(os.path.join(
                PLUGIN_REPO_SOURCES_DIR, f"{PLUGIN_REPO_PREFIX}{repo_id}.sources"))
        ]
        if unapplied:
            return {"success": False, "status": "blocked",
                    "message": "refusing install: required repository is not applied on this appliance: "
                               + ", ".join(unapplied),
                    "warnings": [f"repository not applied: {r}" for r in unapplied]}

        grants = params.get("grants") or []
        if not isinstance(grants, list):
            return {"success": False, "status": "error", "message": "grants must be a list"}
        declared = set(entry["capabilities"])
        undeclared = [g for g in grants if g not in declared]
        if undeclared:
            return {"success": False, "status": "error",
                    "message": "refusing undeclared capability: " + ", ".join(undeclared)}

        packages = entry["packages"]
        steps: list = []
        warnings: list = []

        if not packages:
            # A configuration-only plugin (e.g. the 6in4 tunnel) installs no
            # packages; running apt with an empty set would only be noise.
            steps.append("no packages to install (configuration-only plugin)")
        else:
            # 1. Dry-run resolution first (Plugin Spec §5.3).
            dry = self._run(["apt-get", "-s", "-y", "install"] + packages)
            steps.append(f"apt-get -s install {' '.join(packages)} rc={dry.returncode}")
            if dry.returncode != 0:
                detail = (dry.stderr or dry.stdout or "").strip()
                return {"success": False, "status": "error",
                        "message": "dependency resolution failed", "steps": steps,
                        "warnings": [detail[:200]] if detail else []}

            # 2. Install.
            res = self._run(["apt-get", "-y", "install"] + packages)
            steps.append(f"apt-get -y install {' '.join(packages)} rc={res.returncode}")
            if res.returncode != 0:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error",
                        "message": "package installation failed", "steps": steps,
                        "warnings": [detail[:200]] if detail else []}

        # 3. Enable and start the plugin's own units.
        for unit in entry["units"].values():
            self._run(["systemctl", "enable", unit])
            self._run(["systemctl", "start", unit])
            steps.append(f"systemctl enable {unit}")

        return {"success": True, "status": "applied", "id": plugin_id,
                "packages": packages, "units": list(entry["units"].values()),
                "steps": steps, "warnings": warnings}

    async def rpc_plugin_remove(self, params: Dict[str, Any]) -> Dict[str, Any]:
        plugin_id = str(params.get("id") or "")
        entry = self._plugin_entry(plugin_id)
        if entry is None:
            return {"success": False, "status": "error", "message": f"unknown plugin: {plugin_id}"}

        refusal = self._plugin_refusal(entry)
        if refusal is not None:
            return refusal

        purge = bool(params.get("purge", False))
        steps: list = []
        warnings: list = []

        for unit in entry["units"].values():
            self._run(["systemctl", "stop", unit])
            self._run(["systemctl", "disable", unit])
            steps.append(f"systemctl disable {unit}")

        packages = entry["packages"]
        if not packages:
            steps.append("no packages to remove (configuration-only plugin)")
            return {"success": True, "status": "applied", "id": plugin_id,
                    "purge": purge, "steps": steps, "warnings": warnings}

        res = self._run(["apt-get", "-y", "remove"] + packages)
        steps.append(f"apt-get -y remove {' '.join(packages)} rc={res.returncode}")
        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            warnings.append(detail[:200] if detail else "apt-get remove returned a non-zero exit code")

        return {"success": res.returncode == 0,
                "status": "applied" if res.returncode == 0 else "error",
                "id": plugin_id, "purge": purge, "steps": steps, "warnings": warnings}

    async def rpc_plugin_upgrade(self, params: Dict[str, Any]) -> Dict[str, Any]:
        plugin_id = str(params.get("id") or "")
        entry = self._plugin_entry(plugin_id)
        if entry is None:
            return {"success": False, "status": "error", "message": f"unknown plugin: {plugin_id}"}

        refusal = self._plugin_refusal(entry)
        if refusal is not None:
            return refusal

        packages = entry["packages"]
        if not packages:
            return {"success": True, "status": "applied", "id": plugin_id,
                    "steps": ["no packages to upgrade (configuration-only plugin)"],
                    "warnings": []}

        steps: list = []
        res = self._run(["apt-get", "-y", "install", "--only-upgrade"] + packages)
        steps.append(f"apt-get install --only-upgrade {' '.join(packages)} rc={res.returncode}")
        return {"success": res.returncode == 0,
                "status": "applied" if res.returncode == 0 else "error",
                "id": plugin_id, "steps": steps,
                "warnings": [((res.stderr or res.stdout or "").strip()[:200])] if res.returncode != 0 else []}

    async def rpc_plugin_service(self, params: Dict[str, Any]) -> Dict[str, Any]:
        plugin_id = str(params.get("id") or "")
        action = str(params.get("action") or "")
        entry = self._plugin_entry(plugin_id)
        if entry is None:
            return {"success": False, "status": "error", "message": f"unknown plugin: {plugin_id}"}

        units = list(entry["units"].values())
        if not units:
            return {"success": False, "status": "error",
                    "message": f"plugin {plugin_id} has no service to control"}

        unit = units[0]
        if unit in PROTECTED_UNITS:
            return {"success": False, "status": "error",
                    "message": f"refusing protected unit: {unit}"}
        if action not in SERVICE_ACTIONS:
            return {"success": False, "status": "error", "message": f"unsupported action: {action}"}

        res = self._run(["systemctl", action, unit])
        return {"success": res.returncode == 0,
                "status": "applied" if res.returncode == 0 else "error",
                "id": plugin_id, "unit": unit, "action": action,
                "state": self._service_state(unit),
                "steps": [f"systemctl {action} {unit} rc={res.returncode}"],
                "warnings": [] if res.returncode == 0 else ["systemctl returned a non-zero exit code"]}

    async def rpc_plugin_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Recent journal lines for a plugin's own unit (the daemon control
        widget's LED click-through). The id resolves to the plugin's unit inside
        the fixed catalogue, and protected units are refused, so this can never
        read an arbitrary journal.
        """
        plugin_id = str(params.get("id") or "")
        entry = self._plugin_entry(plugin_id)
        if entry is None:
            return {"success": False, "status": "error", "message": f"unknown plugin: {plugin_id}"}

        units = list(entry["units"].values())
        if not units:
            return {"success": False, "status": "error",
                    "message": f"plugin {plugin_id} has no service to read"}

        unit = units[0]
        if unit in PROTECTED_UNITS:
            return {"success": False, "status": "error",
                    "message": f"refusing protected unit: {unit}"}

        try:
            lines = max(1, min(int(params.get("lines") or 30), 500))
        except (TypeError, ValueError):
            lines = 30

        res = self._run(["journalctl", "-u", unit, "-n", str(lines), "--no-pager"])
        return {
            "success": True,
            "status": "ok",
            "id": plugin_id,
            "unit": unit,
            "logs": (res.stdout or "").strip(),
        }

    async def rpc_plugin_repo_refresh(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = self._run(["apt-get", "update"])
        return {"success": res.returncode == 0,
                "status": "applied" if res.returncode == 0 else "error",
                "steps": [f"apt-get update rc={res.returncode}"],
                "warnings": [((res.stderr or res.stdout or "").strip()[:200])] if res.returncode != 0 else []}

    # -- Curated repositories (`plugin.repo_apply` / `plugin.repo_status`) -----
    @staticmethod
    def _plugin_repo_host(url: str) -> str:
        return (urllib.parse.urlsplit(url).hostname or "").lower()

    @staticmethod
    def _plugin_repo_url_ok(url: str) -> bool:
        return bool(url) and len(url) <= 255 and ".." not in url and PLUGIN_REPO_URL_RE.match(url) is not None

    @staticmethod
    def _plugin_repo_list(value: Any) -> list:
        if isinstance(value, str):
            tokens = re.split(r"[\s,]+", value.lower())
        elif isinstance(value, (list, tuple)):
            tokens = [str(v).lower() for v in value]
        else:
            return []
        out = []
        for token in tokens:
            token = token.strip()
            if token and token not in out:
                out.append(token)
        return out

    @staticmethod
    def _plugin_repo_distro() -> tuple:
        """The appliance's own release identity, from /etc/os-release."""
        origin = ""
        codename = ""
        try:
            with open("/etc/os-release", "r", encoding="utf-8") as handle:
                for line in handle:
                    if "=" not in line:
                        continue
                    key, _, value = line.partition("=")
                    value = value.strip().strip('"')
                    if key.strip() == "ID":
                        origin = PLUGIN_REPO_DISTRO_ORIGINS.get(value.lower(), "")
                    elif key.strip() == "VERSION_CODENAME" and value:
                        codename = value
        except OSError:
            return "", ""
        return origin, codename

    def _plugin_repo_core_pin(self) -> tuple:
        """
        Pin the protected core packages to the appliance's own release.

        This is the load-bearing protection and it depends on nothing the
        repository declares: the selector names the *distribution*, not the
        third party. If the distribution identity cannot be read the file is not
        written and the caller is warned, rather than silently applying a
        weaker pin.
        """
        origin, codename = self._plugin_repo_distro()
        selector = []
        if origin:
            selector.append(f"o={origin}")
        if codename:
            selector.append(f"n={codename}")
        if not selector:
            return None, [], "the distribution identity could not be read from /etc/os-release"

        body = ("# MicronOS: the protected core packages are served by the appliance's own\n"
                "# distribution only. A curated plugin repository may supply packages the\n"
                "# distribution lacks, but never replace a core dependency.\n")
        for package in sorted(PROTECTED_PACKAGES):
            body += (f"\nPackage: {package}\n"
                     f"Pin: release {', '.join(selector)}\n"
                     "Pin-Priority: 990\n")
        return body, selector, None

    def _plugin_repo_validate(self, repos: Any) -> tuple:
        """
        Re-validate every repository field.

        Nothing the caller sends reaches a file: the returned structures hold
        only values that matched a closed grammar, and the stanzas are rendered
        from them.
        """
        if not isinstance(repos, list):
            return [], ["repos must be a list"]
        if len(repos) > PLUGIN_REPO_MAX:
            return [], [f"at most {PLUGIN_REPO_MAX} curated repositories are allowed"]

        clean: list = []
        errors: list = []
        seen: set = set()

        for index, entry in enumerate(repos):
            if not isinstance(entry, dict):
                errors.append(f"repository #{index} is not an object")
                continue

            slug = str(entry.get("id") or "").strip().lower()
            if not PLUGIN_REPO_SLUG_RE.match(slug):
                errors.append(f"repository #{index} has an invalid id")
                continue
            if slug in seen:
                errors.append(f"repository {slug} is declared twice")
                continue
            seen.add(slug)

            url = str(entry.get("url") or "").strip()
            if not self._plugin_repo_url_ok(url):
                errors.append(f"repository {slug} has an invalid url")
                continue
            host = self._plugin_repo_host(url)
            if not host:
                errors.append(f"repository {slug} has no host")
                continue
            if host in PLUGIN_REPO_HOST_DENY:
                errors.append(f"repository {slug} points at the appliance's own distribution host {host}")
                continue

            suite = str(entry.get("suite") or "").strip().lower()
            components = self._plugin_repo_list(entry.get("components"))
            if suite == "./":
                if components:
                    errors.append(f"repository {slug} is flat and must not declare components")
                    continue
            else:
                if not PLUGIN_REPO_SUITE_RE.match(suite):
                    errors.append(f"repository {slug} has an invalid suite")
                    continue
                if not components:
                    components = ["main"]
                bad_components = [c for c in components if not PLUGIN_REPO_COMPONENT_RE.match(c)]
                if bad_components:
                    errors.append(f"repository {slug} has an invalid component: {bad_components[0]}")
                    continue

            architectures = self._plugin_repo_list(entry.get("architectures")) or ["amd64"]
            bad_architectures = [a for a in architectures if a not in PLUGIN_REPO_ARCHES]
            if bad_architectures:
                errors.append(f"repository {slug} requests an unsupported architecture: {bad_architectures[0]}")
                continue

            fingerprint = re.sub(r"[\s:]+", "", str(entry.get("fingerprint") or "").strip()).upper()
            if not PLUGIN_REPO_FINGERPRINT_RE.match(fingerprint):
                errors.append(f"repository {slug} has no usable signing-key fingerprint")
                continue

            key_url = str(entry.get("key_url") or "").strip()
            armor = str(entry.get("key_armor") or "").strip()
            if key_url and not self._plugin_repo_url_ok(key_url):
                errors.append(f"repository {slug} has an invalid key url")
                continue
            if armor and (len(armor) > PLUGIN_REPO_ARMOR_MAX
                          or "-----BEGIN PGP PUBLIC KEY BLOCK-----" not in armor
                          or "-----END PGP PUBLIC KEY BLOCK-----" not in armor):
                errors.append(f"repository {slug} has malformed key material")
                continue
            if not armor:
                # The management plane resolves key URLs through the egress
                # guard, so the daemon only ever receives key material. Refuse
                # a bare URL rather than fetching it here, where there is no
                # guard and no byte cap.
                errors.append(f"repository {slug} has no inline signing key")
                continue

            clean.append({
                "id": slug,
                "name": str(entry.get("name") or slug)[:128],
                "url": url,
                "host": host,
                "suite": suite,
                "components": components,
                "architectures": architectures,
                "fingerprint": fingerprint,
                "armor": armor,
            })

        return clean, errors

    @staticmethod
    def _plugin_repo_source(repo: Dict[str, Any]) -> str:
        """The generated `deb822` stanza. `Types: deb` only — never `deb-src`."""
        lines = ["Types: deb", f"URIs: {repo['url']}", f"Suites: {repo['suite']}"]
        if repo["components"]:
            lines.append("Components: " + " ".join(repo["components"]))
        lines.append("Architectures: " + " ".join(repo["architectures"]))
        lines.append("Signed-By: " + os.path.join(
            PLUGIN_REPO_KEYRING_DIR, f"{PLUGIN_REPO_PREFIX}{repo['id']}.gpg"))
        lines.append("Enabled: yes")
        return "\n".join(lines) + "\n"

    @staticmethod
    def _plugin_repo_pin(repo: Dict[str, Any]) -> str:
        return (f"# MicronOS curated plugin repository: {repo['id']}\n"
                "Package: *\n"
                f'Pin: origin "{repo["host"]}"\n'
                f"Pin-Priority: {PLUGIN_REPO_PIN_PRIORITY}\n")

    @staticmethod
    def _plugin_repo_export_key(staging: str, fingerprint: str, target: str) -> Optional[str]:
        """
        Exports exactly one verified key into a world-readable apt keyring.

        Binary-safe by design: gpg writes the keyring straight to a file handle
        rather than through a captured (text) pipe, and only the key whose
        fingerprint was checked is exported, so a bundle that carries extra keys
        cannot widen what this repository trusts.
        """
        tmp = target + ".micron-tmp"
        try:
            with open(tmp, "wb") as handle:
                proc = subprocess.run(
                    ["gpg", "--batch", "--no-tty", "--no-default-keyring",
                     "--keyring", staging, "--export", fingerprint],
                    stdout=handle, stderr=subprocess.PIPE)
        except OSError as exc:
            return str(exc)

        if proc.returncode != 0 or not os.path.getsize(tmp):
            try:
                os.remove(tmp)
            except OSError:
                pass
            return "the verified signing key could not be exported"

        os.chmod(tmp, 0o644)
        os.replace(tmp, target)
        return None

    @staticmethod
    def _plugin_repo_key_validity(colons: str) -> Dict[str, Dict[str, str]]:
        """
        Per-primary-key creation and expiry dates from `gpg --with-colons` output.

        Parsed because a repository's *metadata* going stale is a different
        signal from its signing key *expiring*: the first says the vendor stopped
        publishing, the second says the trust anchor itself is about to stop
        working. A monitoring task has to tell them apart, and only gpg knows the
        key's own expiry.

        Only `pub:` records are returned — a subkey's dates are not the signing
        anchor's dates, and reporting an encryption subkey's expiry as the
        repository key's would be exactly backwards.

        Returns `{FINGERPRINT: {"created": iso|'', "expires": iso|''}}`.
        """
        validity: Dict[str, Dict[str, str]] = {}
        owner = ""
        created = ""
        expires = ""

        for line in colons.splitlines():
            fields = line.split(":")
            record = fields[0] if fields else ""

            if record in ("pub", "sub"):
                # Dates live on the record; the fpr record that follows it belongs
                # to it, so a subkey's fpr inherits the subkey's dates.
                owner = record
                created = RpcHandler._epoch_to_iso(fields[5] if len(fields) > 5 else "")
                expires = RpcHandler._epoch_to_iso(fields[6] if len(fields) > 6 else "")
            elif record == "fpr" and owner == "pub" and len(fields) > 9:
                validity[fields[9].strip().upper()] = {"created": created, "expires": expires}

        return validity

    @staticmethod
    def _epoch_to_iso(value: str) -> str:
        """A gpg colon-record epoch as a UTC date, or '' when unset.

        UTC, not the appliance's local zone: gpg and apt state key and release
        dates in UTC, and a local-zone conversion made the same key report a
        different creation day depending on where the appliance was installed.
        """
        value = (value or "").strip()
        if not value.isdigit() or int(value) <= 0:
            return ""
        try:
            return datetime.datetime.fromtimestamp(int(value), tz=datetime.timezone.utc).strftime("%Y-%m-%d")
        except (OverflowError, OSError, ValueError):
            return ""

    def _plugin_repo_stage_key(self, armor: str, fingerprint: str) -> tuple:
        """
        Import key material into a PRIVATE keyring and check the fingerprint.

        Returns `(staging_dir, keyring_path, fingerprints, validity, error)`; the
        caller owns the staging directory. Nothing is written outside it, which is
        what lets `plugin.repo_test` verify a key without touching the keyring apt
        actually reads.

        `validity` carries each primary key's own creation and expiry dates, so a
        caller can tell "the vendor stopped publishing" from "the key is about to
        expire" without a second gpg invocation.
        """
        staging_dir = tempfile.mkdtemp(prefix="micron-repo-key-")
        armor_path = os.path.join(staging_dir, "key.asc")
        try:
            with open(armor_path, "w", encoding="utf-8") as handle:
                handle.write(armor)
        except OSError as exc:
            shutil.rmtree(staging_dir, ignore_errors=True)
            return None, None, [], {}, str(exc)

        keyring = os.path.join(staging_dir, "pubring.gpg")
        imported = self._run(["gpg", "--batch", "--no-tty", "--no-default-keyring",
                              "--keyring", keyring, "--import", armor_path])
        if imported.returncode != 0:
            shutil.rmtree(staging_dir, ignore_errors=True)
            return None, None, [], {}, "the signing key could not be imported"

        listed = self._run(["gpg", "--batch", "--no-tty", "--no-default-keyring",
                            "--keyring", keyring, "--with-colons", "--fingerprint"])
        colons = listed.stdout or ""
        validity = self._plugin_repo_key_validity(colons)

        # PRIMARY keys only. A subkey's fingerprint must not satisfy the trust
        # anchor: an encryption subkey does not sign release metadata, so
        # accepting it would be accepting a declaration that can never verify a
        # signature — and vendors do publish subkey fingerprints next to the
        # primary one (NetBird's own key file is the example).
        found = list(validity.keys())
        if fingerprint not in found:
            shutil.rmtree(staging_dir, ignore_errors=True)
            return None, None, found, {}, (
                f"the supplied key's fingerprint does not match the declared {fingerprint}"
            )

        return staging_dir, keyring, found, validity, None

    def _plugin_repo_install_key(self, slug: str, armor: str, fingerprint: str) -> tuple:
        """Import, fingerprint-check and install one repository signing key."""
        staging_dir, keyring, _found, _validity, error = self._plugin_repo_stage_key(armor, fingerprint)
        if error is not None:
            return None, error

        try:
            try:
                os.makedirs(PLUGIN_REPO_KEYRING_DIR, exist_ok=True)
            except OSError as exc:
                return None, str(exc)

            target = os.path.join(PLUGIN_REPO_KEYRING_DIR, f"{PLUGIN_REPO_PREFIX}{slug}.gpg")
            error = self._plugin_repo_export_key(keyring, fingerprint, target)
            return (target, None) if error is None else (None, error)
        finally:
            shutil.rmtree(staging_dir, ignore_errors=True)

    @staticmethod
    def _plugin_repo_release_fields(text: str) -> Dict[str, Any]:
        """
        The fields of a Release file, or of the payload inside a clearsigned
        InRelease (whose armour lines simply do not match a `Key: value` line).
        """
        fields: Dict[str, Any] = {}
        for line in text.splitlines():
            if not line or line.startswith(" ") or "|" in line[:1] or ":" not in line:
                continue
            key, _, value = line.partition(":")
            key = key.strip().lower().replace("-", "_")
            value = value.strip()
            if key in ("origin", "label", "suite", "codename", "date", "valid_until", "not_automatic"):
                fields[key] = value
            elif key in ("architectures", "components"):
                fields[key] = [token for token in value.split() if token]
        return fields

    def _plugin_repo_verify_release(self, keyring: str, release: str, signature: str,
                                    staging_dir: str) -> tuple:
        """
        Verify release metadata against a private keyring.

        Returns `(signer_fingerprint|None, mode|None, error|None)`. `--status-fd`
        is used so the signer comes from gpg's machine-readable VALIDSIG record
        rather than being scraped out of human-readable output — and because the
        keyring holds only the key we verified, a successful verify *is* proof
        that the declared key signed this metadata.
        """
        release_path = os.path.join(staging_dir, "Release")
        try:
            with open(release_path, "w", encoding="utf-8") as handle:
                handle.write(release)
        except OSError as exc:
            return None, None, str(exc)

        argv = ["gpg", "--batch", "--no-tty", "--no-default-keyring",
                "--keyring", keyring, "--status-fd", "1", "--verify", release_path]
        mode = "inrelease"

        if signature:
            signature_path = os.path.join(staging_dir, "Release.gpg")
            try:
                with open(signature_path, "w", encoding="utf-8") as handle:
                    handle.write(signature)
            except OSError as exc:
                return None, None, str(exc)
            argv.append(signature_path)
            mode = "release"

        result = self._run(argv)
        if result.returncode != 0:
            detail = " ".join(
                line for line in (result.stdout or "").splitlines() if line.startswith("[GNUPG:] ")
            )
            message = "the release metadata is not validly signed by the declared key"
            return None, mode, message + (f" ({detail[:160]})" if detail else "")

        for line in (result.stdout or "").splitlines():
            if line.startswith("[GNUPG:] VALIDSIG "):
                return line.split()[2].upper(), mode, None

        return None, mode, "the signature verified but gpg reported no VALIDSIG record"

    @staticmethod
    def _plugin_repo_release_warnings(repo: Dict[str, Any], fields: Dict[str, Any]) -> list:
        """
        What the release says versus what was declared.

        These are the misconfigurations a bare "is it up?" probe would miss: a
        suite that resolves but is not the one configured, a component that does
        not exist, an architecture the vendor does not publish, metadata that has
        passed Valid-Until.
        """
        warnings: list = []

        declared_suite = str(repo["suite"])
        advertised = [str(fields.get("suite", "")), str(fields.get("codename", ""))]
        advertised = [name for name in advertised if name]
        if declared_suite != "./" and advertised and declared_suite not in advertised:
            warnings.append(
                f'the release declares suite "{advertised[0]}"'
                + (f' / codename "{advertised[1]}"' if len(advertised) > 1 else "")
                + f', but the repository is configured for "{declared_suite}"'
            )

        architectures = fields.get("architectures") or []
        unpublished = [a for a in repo["architectures"] if a not in architectures]
        if architectures and unpublished:
            warnings.append("the release publishes no " + ", ".join(unpublished)
                            + "; apt would find no packages for that architecture")

        components = fields.get("components") or []
        missing = [c for c in repo["components"] if c not in components]
        if components and missing:
            warnings.append("the release publishes no component " + ", ".join(missing))

        valid_until = str(fields.get("valid_until", ""))
        if valid_until:
            try:
                expires = datetime.datetime.strptime(valid_until, "%a, %d %b %Y %H:%M:%S %Z")
                if expires.timestamp() < time.time():
                    warnings.append(f"the release metadata expired on {valid_until}")
            except ValueError:
                pass

        return warnings

    @staticmethod
    def _plugin_repo_existing() -> Dict[str, Dict[str, str]]:
        """
        Repository slug -> the MicronOS-generated files it owns today.

        The protected-core pin lives in the same directory and is *not* a
        repository: it is skipped here so the retirement pass can never delete
        it. Anything not carrying the `micron-` prefix is invisible, so the
        appliance's own sources are untouchable from this handler.
        """
        out: Dict[str, Dict[str, str]] = {}
        core_pin = os.path.basename(PLUGIN_REPO_CORE_PIN)
        for directory, suffix in ((PLUGIN_REPO_SOURCES_DIR, ".sources"),
                                  (PLUGIN_REPO_PREFERENCES_DIR, ".pref"),
                                  (PLUGIN_REPO_KEYRING_DIR, ".gpg")):
            try:
                names = os.listdir(directory)
            except OSError:
                continue
            for name in names:
                if name == core_pin:
                    continue
                if not name.startswith(PLUGIN_REPO_PREFIX) or not name.endswith(suffix):
                    continue
                slug = name[len(PLUGIN_REPO_PREFIX):-len(suffix)]
                if PLUGIN_REPO_SLUG_RE.match(slug):
                    out.setdefault(slug, {})[suffix] = os.path.join(directory, name)
        return out

    async def rpc_plugin_repo_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Reconcile the curated repositories and refresh the apt metadata.

        Declarative, like `dhcp.relay_apply`: the caller sends the enabled set,
        and anything MicronOS wrote previously that is no longer in it is
        retired. Only files carrying the `micron-` prefix are ever touched, so
        the appliance's own sources are untouchable from here.
        """
        clean, errors = self._plugin_repo_validate(params.get("repos", []))
        if errors:
            return {"success": False, "status": "error", "message": "; ".join(errors)}

        steps: list = []
        warnings: list = []
        desired: set = set()

        # 1. The protected-core pin, anchored on the distribution's own identity.
        core_pin, selector, pin_error = self._plugin_repo_core_pin()
        if core_pin is None:
            warnings.append(str(pin_error))
        else:
            error = self._write_config(PLUGIN_REPO_CORE_PIN, core_pin)
            if error:
                warnings.append(error)
            else:
                steps.append(f"wrote {PLUGIN_REPO_CORE_PIN} ({', '.join(selector)})")

        # 2. Per repository: verify the key, then generate the source and pin.
        for repo in clean:
            slug = repo["id"]
            desired.add(slug)

            keyring, error = self._plugin_repo_install_key(slug, repo["armor"], repo["fingerprint"])
            if error is not None:
                warnings.append(f"{slug}: {error}")
                continue
            steps.append(f"verified signing key {repo['fingerprint'][-16:]} for {slug}")

            source_path = os.path.join(PLUGIN_REPO_SOURCES_DIR, f"{PLUGIN_REPO_PREFIX}{slug}.sources")
            error = self._write_config(source_path, self._plugin_repo_source(repo))
            if error:
                warnings.append(error)
                continue

            pref_path = os.path.join(PLUGIN_REPO_PREFERENCES_DIR, f"{PLUGIN_REPO_PREFIX}{slug}.pref")
            error = self._write_config(pref_path, self._plugin_repo_pin(repo))
            if error:
                warnings.append(error)
                continue

            steps.append(f"wrote {PLUGIN_REPO_PREFIX}{slug}.sources (pin priority {PLUGIN_REPO_PIN_PRIORITY})")

        # 3. Retire what is no longer declared.
        for slug, files in self._plugin_repo_existing().items():
            if slug in desired:
                continue
            for path in files.values():
                try:
                    os.remove(path)
                except OSError:
                    pass
            steps.append(f"removed {PLUGIN_REPO_PREFIX}{slug}")

        # 4. Refresh metadata against the reconciled sources.
        if clean:
            res = self._run(["apt-get", "update"])
            steps.append(f"apt-get update rc={res.returncode}")
            if res.returncode != 0:
                warnings.append("apt-get update returned a non-zero exit code")
        else:
            steps.append("no curated repositories are enabled")

        return {
            "success": not warnings,
            "status": "applied" if not warnings else "error",
            "repos": [
                {"id": r["id"], "url": r["url"], "fingerprint": r["fingerprint"],
                 "sources_path": os.path.join(PLUGIN_REPO_SOURCES_DIR, f"{PLUGIN_REPO_PREFIX}{r['id']}.sources")}
                for r in clean
            ],
            "steps": steps,
            "warnings": warnings,
        }

    async def rpc_plugin_repo_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        What the daemon actually has applied.

        Presence means applied — the same presence-based contract the DHCP relay
        widget uses — so the page can distinguish "registered" from "applied"
        without trusting the database.
        """
        entries = []
        for slug, files in sorted(self._plugin_repo_existing().items()):
            text = ""
            if files.get(".sources"):
                try:
                    with open(files[".sources"], "r", encoding="utf-8") as handle:
                        text = handle.read()
                except OSError:
                    text = ""

            uri = ""
            suite = ""
            for line in text.splitlines():
                if line.startswith("URIs:"):
                    uri = line.split(":", 1)[1].strip()
                elif line.startswith("Suites:"):
                    suite = line.split(":", 1)[1].strip()

            entries.append({
                "id": slug,
                "url": uri,
                "suite": suite,
                "sources_path": files.get(".sources"),
                "preferences_path": files.get(".pref"),
                "keyring": files.get(".gpg"),
                "pinned": ".pref" in files,
                "keyed": ".gpg" in files,
            })

        core_pinned = os.path.isfile(PLUGIN_REPO_CORE_PIN)
        return {
            "success": True,
            "status": "ok",
            "repos": entries,
            "count": len(entries),
            "core_pin": PLUGIN_REPO_CORE_PIN if core_pinned else "",
            "core_pinned": core_pinned,
        }

    async def rpc_plugin_repo_test(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Verify one repository WITHOUT touching the appliance.

        Read-only by construction, not by promise: the key material is imported
        into a private keyring, the release metadata the management plane fetched
        is verified against it, and every temporary file lives in a directory
        removed before returning. No source, preference, keyring or apt list is
        written, and no package operation runs — so an operator can check a
        vendor's repository before deciding whether to apply it.

        Because the private keyring holds only the key whose fingerprint was
        checked against the declared one, a successful verification proves three
        things at once: the metadata is reachable, it is signed, and it is signed
        by *that* key.
        """
        clean, errors = self._plugin_repo_validate([params.get("repo") or {}])
        if errors:
            return {"success": False, "status": "error", "reason": "invalid_repository",
                    "message": "; ".join(errors)}
        repo = clean[0]

        release = str(params.get("release") or "")
        signature = str(params.get("signature") or "")
        if not release.strip():
            return {"success": False, "status": "error", "reason": "release_missing",
                    "message": "no release metadata was supplied to verify"}
        if len(release.encode("utf-8")) > PLUGIN_REPO_RELEASE_MAX:
            return {"success": False, "status": "error", "reason": "release_too_large",
                    "message": f"the release metadata exceeds the {PLUGIN_REPO_RELEASE_MAX // 1024} KiB limit"}
        if len(signature.encode("utf-8")) > PLUGIN_REPO_RELEASE_MAX:
            return {"success": False, "status": "error", "reason": "release_too_large",
                    "message": "the detached release signature is implausibly large"}

        # Every refusal carries a stable `reason` code alongside its prose.
        # A monitoring task has to tell "the vendor rotated its key" from "the
        # vendor's CDN was unreachable" without pattern-matching English.
        steps: list = []

        staging_dir, keyring, found, validity, error = self._plugin_repo_stage_key(repo["armor"], repo["fingerprint"])
        if error is not None:
            return {"success": False, "status": "error", "reason": "fingerprint_mismatch",
                    "message": error}

        try:
            steps.append(f"imported the signing key into a private keyring ({len(found)} key(s))")

            signer, mode, error = self._plugin_repo_verify_release(keyring, release, signature, staging_dir)
            if error is not None:
                return {"success": False, "status": "error", "reason": "signature_invalid",
                        "message": error, "steps": steps}
            steps.append(f"{mode} signature verified against {signer}")

            if signer != repo["fingerprint"]:
                return {"success": False, "status": "error", "reason": "signer_mismatch", "steps": steps,
                        "message": (f"the release is signed by {signer}, not the declared "
                                    f"{repo['fingerprint']}")}

            fields = self._plugin_repo_release_fields(release)

            return {
                "success": True,
                "status": "ok",
                "id": repo["id"],
                "url": repo["url"],
                "suite": repo["suite"],
                # The declared key's own dates, so a caller can alert on the trust
                # anchor expiring rather than on the metadata merely being old.
                "key": {"declared": repo["fingerprint"], "found": found, "matched": True,
                        "created_at": validity.get(repo["fingerprint"], {}).get("created", ""),
                        "expires_at": validity.get(repo["fingerprint"], {}).get("expires", "")},
                "signature": {"verified": True, "signer": signer, "mode": mode},
                "release": fields,
                "warnings": self._plugin_repo_release_warnings(repo, fields),
                "steps": steps,
            }
        finally:
            shutil.rmtree(staging_dir, ignore_errors=True)

    async def rpc_plugin_iperf3_run(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Run an iperf3 throughput test — the plugin's own UI surface.

        The plugin id is fixed to `iperf3`; every parameter the operator picks is
        validated here, so nothing but a well-formed iperf3 argv reaches the
        binary. Client mode measures against a target host; server mode binds a
        listening TCP port for a single client and returns (time-bounded by
        `-1`), matching the manifest's "on-demand, time-bounded" contract.
        """
        if self._plugin_entry("iperf3") is None:
            return {"success": False, "status": "error", "message": "iperf3 is not in the plugin catalogue"}

        binary = "/usr/bin/iperf3"

        mode = str(params.get("mode") or "client").strip().lower()
        if mode not in ("client", "server"):
            return {"success": False, "status": "error", "message": f"unsupported mode: {mode}"}

        try:
            port = int(params.get("port") or 5201)
        except (TypeError, ValueError):
            return {"success": False, "status": "error", "message": "port must be an integer"}
        if not (1 <= port <= 65535):
            return {"success": False, "status": "error", "message": "port must be between 1 and 65535"}

        try:
            duration = int(params.get("duration") or 10)
        except (TypeError, ValueError):
            return {"success": False, "status": "error", "message": "duration must be an integer"}
        if not (1 <= duration <= 120):
            return {"success": False, "status": "error", "message": "duration must be between 1 and 120 seconds"}

        if mode == "server":
            # `-1` handles exactly one client then exits: a listening server that
            # cannot be left running unbounded from the UI.
            argv = [binary, "-s", "-p", str(port), "-1"]
            res = self._run(argv)
            return {
                "success": res.returncode == 0,
                "status": "applied" if res.returncode == 0 else "error",
                "mode": "server",
                "port": port,
                "duration": duration,
                "steps": [f"iperf3 -s -p {port} -1 rc={res.returncode}"],
                "warnings": [] if res.returncode == 0 else [((res.stderr or res.stdout or "").strip()[:200])],
            }

        target = str(params.get("target") or "").strip()
        if not self._valid_iperf_target(target):
            return {"success": False, "status": "error", "message": f"invalid target host: {target}"}

        protocol = str(params.get("protocol") or "tcp").strip().lower()
        if protocol not in ("tcp", "udp"):
            return {"success": False, "status": "error", "message": f"unsupported protocol: {protocol}"}

        direction = str(params.get("direction") or "upload").strip().lower()
        if direction not in ("upload", "download", "bidirectional"):
            return {"success": False, "status": "error", "message": f"unsupported direction: {direction}"}

        try:
            streams = int(params.get("streams") or 1)
        except (TypeError, ValueError):
            return {"success": False, "status": "error", "message": "streams must be an integer"}
        if not (1 <= streams <= 32):
            return {"success": False, "status": "error", "message": "streams must be between 1 and 32"}

        argv = [binary, "-c", target, "-p", str(port), "-t", str(duration), "-P", str(streams), "--json"]
        if protocol == "udp":
            argv.append("-u")
        if direction == "download":
            argv.append("-R")
        elif direction == "bidirectional":
            argv.append("--bidir")

        res = self._run(argv)
        result: Dict[str, Any] = {
            "success": res.returncode == 0,
            "status": "ok" if res.returncode == 0 else "error",
            "mode": "client",
            "target": target,
            "port": port,
            "protocol": protocol,
            "direction": direction,
            "duration": duration,
            "streams": streams,
            "steps": [f"iperf3 -c {target} -p {port} -t {duration} -P {streams} rc={res.returncode}"],
            "warnings": [] if res.returncode == 0 else [((res.stderr or res.stdout or "").strip()[:200])],
        }

        metrics = self._parse_iperf_json(res.stdout or "")
        if metrics:
            result.update(metrics)
        elif res.returncode != 0:
            result["raw"] = (res.stdout or res.stderr or "").strip()[:2000]
        return result

    @staticmethod
    def _valid_iperf_target(target: str) -> bool:
        """A hostname, IPv4 or IPv6 literal — never anything that reads as a flag."""
        if not target or target.startswith("-") or len(target) > 253:
            return False
        if not re.fullmatch(r"[A-Za-z0-9._:\-]+", target):
            return False
        return True

    @staticmethod
    def _parse_iperf_json(output: str) -> Dict[str, Any]:
        """Flatten the fields of an iperf3 `--json` run the UI renders."""
        try:
            data = strict_json_loads(output)
        except (ValueError, TypeError):
            return {}
        if not isinstance(data, dict):
            return {}
        end = data.get("end") or {}
        sent = end.get("sum_sent") or {}
        received = end.get("sum_received") or {}
        aggregate = end.get("sum") or {}
        bits = received.get("bits_per_second") or sent.get("bits_per_second") or aggregate.get("bits_per_second") or 0
        try:
            mbps = round(float(bits) / 1_000_000.0, 2)
        except (TypeError, ValueError):
            mbps = 0
        return {
            "bits_per_second": bits,
            "mbps": mbps,
            "jitter_ms": aggregate.get("jitter_ms", sent.get("jitter_ms")),
            "lost_percent": aggregate.get("lost_percent", sent.get("lost_percent")),
            "retransmits": sent.get("retransmits"),
        }

    # ------------------------------- NetBird (class C overlay) ----------------
    # The plugin id is fixed here, exactly like iperf3's runner: the operator
    # page can only ever talk about `netbird`, and every parameter is validated
    # before it becomes an argument.

    def _netbird_ready(self) -> Optional[Dict[str, Any]]:
        """Refuse the overlay RPCs when the agent is not installed/enabled.

        Returned as the refusal payload, or None when the agent is usable.
        """
        if self._plugin_entry("netbird") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: netbird"}
        if not os.path.exists(NETBIRD_BIN):
            return {"success": False, "status": "error",
                    "message": "netbird is not installed on this appliance"}
        if self._service_state(NETBIRD_UNIT) != "running":
            return {"success": False, "status": "error",
                    "message": f"the {NETBIRD_UNIT} service is not running"}
        return None

    @staticmethod
    def _parse_netbird_status(output: str) -> Dict[str, Any]:
        """Flatten `netbird status --json` into the fields the page renders.

        Parsed defensively: the vendor's JSON is not a frozen contract, and a
        field that moves must degrade to "unknown" rather than break the page or
        — worse — be reported as connected when it is not.
        """
        try:
            data = strict_json_loads(output)
        except (ValueError, TypeError):
            return {}
        if not isinstance(data, dict):
            return {}

        def _connected(*candidates) -> Optional[bool]:
            for candidate in candidates:
                if isinstance(candidate, dict) and isinstance(candidate.get("connected"), bool):
                    return candidate["connected"]
            return None

        peers = data.get("peers") if isinstance(data.get("peers"), dict) else {}
        return {
            "fqdn": str(data.get("fqdn") or ""),
            "ip": str(data.get("netbirdIp") or data.get("interfaceIP") or ""),
            "interface": str(data.get("interfaceName") or ""),
            "public_key": str(data.get("publicKey") or ""),
            "daemon_version": str(data.get("daemonVersion") or data.get("cliVersion") or ""),
            "management_url": str((data.get("managementState") or {}).get("URL") or "")
                if isinstance(data.get("managementState"), dict) else "",
            "management_connected": _connected(data.get("managementState"), data.get("management")),
            "signal_connected": _connected(data.get("signalState"), data.get("signal")),
            "peers_total": peers.get("total"),
            "peers_connected": peers.get("connected"),
        }

    def _netbird_status_payload(self, steps: list) -> Dict[str, Any]:
        """Run `netbird status --json` and flatten it, tolerating an empty answer."""
        res = self._run([NETBIRD_BIN, "status", "--json"])
        steps.append(f"{NETBIRD_BIN} status --json rc={res.returncode}")
        parsed = self._parse_netbird_status(res.stdout or "")
        warnings = []
        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            warnings.append("netbird status failed" + (f": {detail[:200]}" if detail else ""))
        elif not parsed:
            # Exit 0 with nothing parsable: the agent ran but its JSON is not a
            # shape we know. Say so rather than render an empty peer as healthy.
            warnings.append("netbird status returned no parsable JSON")
        return {"success": res.returncode == 0, "status": "ok" if res.returncode == 0 else "error",
                "id": "netbird", "state": self._service_state(NETBIRD_UNIT),
                "data": parsed, "steps": steps, "warnings": warnings}

    async def rpc_plugin_netbird_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        refusal = self._netbird_ready()
        if refusal is not None:
            return refusal
        return self._netbird_status_payload([])

    async def rpc_plugin_netbird_up(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Connect the peer using its existing configuration.

        No secret is involved: this is `netbird up` with an optional
        management URL, for a peer that is already enrolled.
        """
        # Validate before probing: a malformed request must not cause the
        # daemon to run anything at all, not even a read-only systemd check.
        management = str(params.get("management_url") or "").strip()
        if management and NETBIRD_MANAGEMENT_RE.fullmatch(management) is None:
            return {"success": False, "status": "error",
                    "message": "management_url must be an https:// URL"}

        refusal = self._netbird_ready()
        if refusal is not None:
            return refusal

        argv = [NETBIRD_BIN, "up"]
        if management:
            argv += ["--management-url", management]

        steps = ["netbird up" + (" --management-url " + management if management else "")]
        res = self._run(argv)
        steps.append(f"{NETBIRD_BIN} up rc={res.returncode}")
        payload = self._netbird_status_payload(steps) if res.returncode == 0 else None
        if payload is None:
            detail = (res.stderr or res.stdout or "").strip()
            return {"success": False, "status": "error", "id": "netbird",
                    "message": "netbird up failed", "steps": steps,
                    "warnings": [detail[:200]] if detail else []}
        return payload

    async def rpc_plugin_netbird_down(self, params: Dict[str, Any]) -> Dict[str, Any]:
        refusal = self._netbird_ready()
        if refusal is not None:
            return refusal

        steps = ["netbird down"]
        res = self._run([NETBIRD_BIN, "down"])
        steps.append(f"{NETBIRD_BIN} down rc={res.returncode}")
        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            return {"success": False, "status": "error", "id": "netbird",
                    "message": "netbird down failed", "steps": steps,
                    "warnings": [detail[:200]] if detail else []}
        return self._netbird_status_payload(steps)

    async def rpc_plugin_netbird_enroll(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Enrol a peer with a setup key, without ever handling it in the clear.

        The key is a secret (PL7). It is written to a 0600 file inside a
        daemon-private directory and handed over as `--setup-key-file`, so it
        never appears in the process table (`ps`), never reaches a log line, and
        never comes back in the response. The file is removed on every path.
        """
        # Both parameters are checked before anything runs: a malformed setup key
        # must not even reach the readiness probe, let alone the agent.
        setup_key = str(params.get("setup_key") or "").strip()
        if NETBIRD_SETUP_KEY_RE.fullmatch(setup_key) is None:
            return {"success": False, "status": "error",
                    "message": "a setup key is 8-128 characters of letters, digits, dot, dash or underscore"}

        management = str(params.get("management_url") or "").strip()
        if management and NETBIRD_MANAGEMENT_RE.fullmatch(management) is None:
            return {"success": False, "status": "error",
                    "message": "management_url must be an https:// URL"}

        refusal = self._netbird_ready()
        if refusal is not None:
            return refusal

        staging = None
        try:
            try:
                staging = tempfile.mkdtemp(prefix="micron-netbird-")
                key_path = os.path.join(staging, "setup-key")
                fd = os.open(key_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(fd, "w", encoding="utf-8") as handle:
                    handle.write(setup_key + "\n")
            except OSError as exc:
                return {"success": False, "status": "error",
                        "message": f"the setup key could not be staged: {exc}"}

            argv = [NETBIRD_BIN, "up", "--setup-key-file", key_path]
            steps = ["netbird up --setup-key-file <staged>"]
            if management:
                argv += ["--management-url", management]
                steps.append("netbird up --management-url " + management)

            res = self._run(argv)
            steps.append(f"{NETBIRD_BIN} up (setup key) rc={res.returncode}")
        finally:
            if staging is not None:
                shutil.rmtree(staging, ignore_errors=True)

        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            return {"success": False, "status": "error", "id": "netbird",
                    "message": "enrolment failed", "steps": steps,
                    "warnings": [detail[:200]] if detail else []}

        payload = self._netbird_status_payload(steps)
        payload["message"] = "setup key submitted; the peer is enrolling"
        return payload

    # ------------------------ ZeroTier (class C overlay) -----------------------
    # The plugin id is fixed here, exactly like netbird's: the page can only ever
    # talk about `zerotier`, and the only value it may name is a network id.

    def _zerotier_ready(self) -> Optional[Dict[str, Any]]:
        """Refuse the overlay RPCs when the agent is not installed/enabled.

        Returned as the refusal payload, or None when the agent is usable.
        """
        if self._plugin_entry("zerotier") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: zerotier"}
        if not os.path.exists(ZEROTIER_CLI_BIN):
            return {"success": False, "status": "error",
                    "message": "zerotier is not installed on this appliance"}
        if self._service_state(ZEROTIER_UNIT) != "running":
            return {"success": False, "status": "error",
                    "message": f"the {ZEROTIER_UNIT} service is not running"}
        return None

    @staticmethod
    def _zerotier_status_code(output: str) -> Optional[int]:
        """The CLI's own status code, when the response carries one.

        `zerotier-cli` reports *its* failures as a `NNN command ...` line on
        stdout, very often with a zero exit status — so the exit code alone is
        not the verdict, and treating it as one would report a refused join as a
        success. `None` means the response named no code at all.
        """
        match = ZEROTIER_STATUS_CODE_RE.match(output or "")
        return int(match.group(1)) if match is not None else None

    @staticmethod
    def _parse_zerotier_info(output: str) -> Dict[str, Any]:
        """The node's own identity, from `zerotier-cli info`.

        `200 info <address> <version> <status>` is the one CLI response with a
        fixed, space-free shape. An unrecognised answer yields nothing, which the
        caller reports as "unknown" rather than as a healthy node.
        """
        match = ZEROTIER_INFO_RE.match(output or "")
        if match is None:
            return {}
        status = match.group(3).upper()
        return {
            "address": match.group(1).lower(),
            "version": match.group(2),
            # `TUNNELED` (and any future word) is reported as-is rather than
            # guessed into online/offline.
            "online": True if status == "ONLINE" else (False if status == "OFFLINE" else None),
            "status_text": status,
        }

    @staticmethod
    def _parse_zerotier_networks(output: str) -> Optional[list]:
        """The joined networks, from `zerotier-cli -j listnetworks`.

        Returns None when the output is not the JSON array the daemon asks for —
        which is *not* the same answer as `[]`, "joined nothing". The
        human-readable table is deliberately not scraped as a fallback: a network
        *name* in that table may contain spaces, so a positional parse could
        invent a membership or drop one, and a wrong membership list is worse
        than an honest "unreadable".
        """
        try:
            parsed = strict_json_loads(output)
        except (ValueError, TypeError):
            return None
        if not isinstance(parsed, list):
            return None

        rows = []
        for entry in parsed:
            if not isinstance(entry, dict):
                continue
            assigned = entry.get("assignedAddresses")
            rows.append({
                # `nwid` is the long-standing field; `id` is what the newer
                # agent reports.
                "nwid": str(entry.get("nwid") or entry.get("id") or "").lower(),
                "name": str(entry.get("name") or ""),
                "status": str(entry.get("status") or ""),
                "type": str(entry.get("type") or "").upper(),
                "device": str(entry.get("portDeviceName") or ""),
                "assigned": " ".join(str(a) for a in assigned) if isinstance(assigned, list) else "",
            })
        return rows

    def _zerotier_status_payload(self, steps: list) -> Dict[str, Any]:
        """Read the node's identity and its memberships, tolerating silence."""
        warnings: list = []

        info_res = self._run([ZEROTIER_CLI_BIN, "info"])
        steps.append(f"{ZEROTIER_CLI_BIN} info rc={info_res.returncode}")
        node = self._parse_zerotier_info(info_res.stdout or "")
        code = self._zerotier_status_code(info_res.stdout or "")
        if info_res.returncode != 0 or (code is not None and code != 200):
            detail = (info_res.stderr or info_res.stdout or "").strip()
            warnings.append("zerotier-cli info failed" + (f": {detail[:200]}" if detail else ""))
        elif not node:
            warnings.append("zerotier-cli info returned no parsable node identity")

        net_res = self._run([ZEROTIER_CLI_BIN, "-j", "listnetworks"])
        steps.append(f"{ZEROTIER_CLI_BIN} -j listnetworks rc={net_res.returncode}")
        networks = self._parse_zerotier_networks(net_res.stdout or "")
        if networks is None:
            networks = []
            detail = (net_res.stderr or net_res.stdout or "").strip()
            warnings.append("the joined-network list could not be read as JSON"
                            + (f": {detail[:200]}" if detail else ""))
        elif net_res.returncode != 0:
            detail = (net_res.stderr or net_res.stdout or "").strip()
            warnings.append("zerotier-cli listnetworks failed"
                            + (f": {detail[:200]}" if detail else ""))

        data = dict(node)
        data["networks"] = networks
        data["networks_joined"] = len(networks)

        ok = info_res.returncode == 0 and not (code is not None and code != 200)
        return {"success": ok, "status": "ok" if ok else "error", "id": "zerotier",
                "state": self._service_state(ZEROTIER_UNIT), "data": data,
                "steps": steps, "warnings": warnings}

    async def rpc_plugin_zerotier_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        refusal = self._zerotier_ready()
        if refusal is not None:
            return refusal
        return self._zerotier_status_payload([])

    @staticmethod
    def _zerotier_network_id(params: Dict[str, Any]) -> tuple:
        """Validate the single value a join/leave may carry."""
        nwid = str(params.get("nwid") or params.get("network_id") or "").strip().lower()
        if ZEROTIER_NETWORK_RE.fullmatch(nwid) is None:
            return "", {"success": False, "status": "error", "id": "zerotier",
                         "message": "a network id is exactly 16 hexadecimal characters"}
        return nwid, None

    async def _zerotier_network_action(self, params: Dict[str, Any], verb: str) -> Dict[str, Any]:
        """Join or leave one network, then report the resulting memberships."""
        nwid, refusal = self._zerotier_network_id(params)
        if refusal is not None:
            # Validated before the readiness probe: a malformed request must not
            # run anything at all, not even a read-only systemd check.
            return refusal

        refusal = self._zerotier_ready()
        if refusal is not None:
            return refusal

        steps = [f"zerotier-cli {verb} {nwid}"]
        res = self._run([ZEROTIER_CLI_BIN, verb, nwid])
        steps.append(f"{ZEROTIER_CLI_BIN} {verb} rc={res.returncode}")

        code = self._zerotier_status_code(res.stdout or "")
        if res.returncode != 0 or (code is not None and code != 200):
            detail = (res.stderr or res.stdout or "").strip()
            return {"success": False, "status": "error", "id": "zerotier",
                    "message": f"{verb} failed", "steps": steps,
                    "warnings": [detail[:200]] if detail else []}

        payload = self._zerotier_status_payload(steps)
        payload["message"] = f"{verb} submitted; the agent is applying it"
        return payload

    async def rpc_plugin_zerotier_join(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._zerotier_network_action(params, "join")

    async def rpc_plugin_zerotier_leave(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self._zerotier_network_action(params, "leave")

    # ------------------ NAT64 / DNS64 (nat64.*) ------------------
    @staticmethod
    def _nat64_private_networks() -> list:
        """IPv4 ranges RFC 6052 forbids under the Well-Known Prefix."""
        return [
            ipaddress.ip_network(r)
            for r in ("10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8",
                      "169.254.0.0/16", "172.16.0.0/12", "192.168.0.0/16")
        ]

    def _nat64_validate(self, params: Dict[str, Any]):
        """Validate every nat64.apply parameter before a single command runs.

        Returns `(config, None)` on success or `(None, refusal)` on rejection —
        mirroring the netbird rule that a malformed request must not cause the
        daemon to run anything at all, not even a read-only probe.
        """
        if self._plugin_entry("nat64") is None:
            return None, {"success": False, "status": "error", "message": "unknown plugin: nat64"}

        enabled = bool(params.get("enabled", True))
        dns64 = bool(params.get("dns64", True))
        cfg: Dict[str, Any] = {"enabled": enabled, "dns64": dns64}
        if not enabled:
            return cfg, None

        prefix_raw = str(params.get("prefix") or "64:ff9b::/96").strip()
        pool_raw = str(params.get("pool") or "").strip()
        try:
            prefix = ipaddress.ip_network(prefix_raw, strict=True)
        except ValueError:
            return None, {"success": False, "status": "error",
                          "message": "prefix must be an IPv6 network in canonical form (e.g. 64:ff9b::/96)"}
        if prefix.version != 6 or prefix.prefixlen not in NAT64_PREFIX_LENGTHS:
            return None, {"success": False, "status": "error",
                          "message": "prefix length must be one of /32 /40 /48 /56 /64 /96 (RFC 6052 §2.2)"}

        if not pool_raw:
            return None, {"success": False, "status": "error",
                          "message": "pool is required: the IPv4 dynamic pool tayga assigns translated hosts from"}
        try:
            pool = ipaddress.ip_network(pool_raw, strict=True)
        except ValueError:
            return None, {"success": False, "status": "error",
                          "message": "pool must be an IPv4 network in canonical form (e.g. 192.0.2.0/24)"}
        if pool.version != 4:
            return None, {"success": False, "status": "error", "message": "pool must be an IPv4 network"}
        # tayga reserves the lowest address of the pool and the translator's own
        # address is taken from it too, so a /31 would leave nothing assignable:
        # that failure surfaces at runtime as ICMP unreachable, not at config time.
        if pool.prefixlen > 30:
            return None, {"success": False, "status": "error",
                          "message": "pool must be /30 or shorter (tayga reserves the lowest address and "
                                     "the translator's own address is taken from the pool)"}

        if prefix == ipaddress.ip_network("64:ff9b::/96"):
            # RFC 6052 §5: the Well-Known Prefix must never embed a non-global
            # IPv4 address — tayga refuses such translations at runtime, so the
            # config is rejected here instead. Overlap is checked in both
            # directions because two CIDRs either nest or are disjoint.
            blocked = [str(r) for r in self._nat64_private_networks() if pool.subnet_of(r) or pool.supernet_of(r)]
            if blocked or not pool.is_global:
                return None, {"success": False, "status": "error",
                              "message": "the Well-Known Prefix 64:ff9b::/96 refuses non-global IPv4 pools "
                                         "(RFC 6052 §5): use a site-specific prefix instead of the WKP, "
                                         "or a globally routable pool"}

        cfg["prefix"] = str(prefix)
        cfg["pool"] = str(pool)
        cfg["tayga_ipv4"] = str(ipaddress.ip_address(int(pool.network_address) + 1))
        return cfg, None

    @staticmethod
    def _nat64_dns64_fragment(prefix: str) -> str:
        """The Unbound include file: DNS64 synthesis beside the resolver."""
        return (
            "# MicronOS NAT64/DNS64 (nat64 plugin) - generated by micrond. Do not edit.\n"
            "server:\n"
            '    module-config: "dns64 validator iterator"\n'
            f"    dns64-prefix: {prefix}\n"
        )

    @staticmethod
    def _nat64_net_unit(prefix: str, pool: str, tayga_ipv4: str) -> str:
        """Host-side network for tayga's tun: link up, prefix and pool routed.

        tayga.conf(5) is explicit that the daemon does not configure the
        host-side parameters of its tun device, so they live in a oneshot unit
        that runs after tayga.service (which creates the device on start and
        destroys it on exit — routes on a vanished device go with it).
        """
        return (
            "[Unit]\n"
            "Description=MicronOS NAT64 host-side network (tayga tun)\n"
            "After=tayga.service\n"
            "Requires=tayga.service\n"
            "PartOf=tayga.service\n"
            "\n"
            "[Service]\n"
            "Type=oneshot\n"
            "RemainAfterExit=yes\n"
            f"ExecStart={IPROUTE_BIN} link set {TAYGA_TUN} up\n"
            f"ExecStart={IPROUTE_BIN} -6 route replace {prefix} dev {TAYGA_TUN}\n"
            f"ExecStart={IPROUTE_BIN} route replace {pool} dev {TAYGA_TUN}\n"
            "\n"
            "[Install]\n"
            "WantedBy=multi-user.target\n"
        )

    async def rpc_plugin_nat64_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Validate and apply NAT64 (tayga) + DNS64 (Unbound fragment).

        Fixed argv only: the config files are rendered from validated CIDRs,
        and the DNS64 half is gated through `unbound-checkconf` with the
        previous fragment restored on rejection — the dnsbl rollback pattern.
        """
        # Validate before probing: a malformed request runs nothing at all.
        cfg, refusal = self._nat64_validate(params)
        if refusal is not None:
            return refusal

        steps: list = []
        warnings: list = []

        def _read_previous() -> Optional[str]:
            try:
                with open(NAT64_DNS64_FRAGMENT, "r", encoding="utf-8") as handle:
                    return handle.read()
            except OSError:
                return None

        # ---------------- DNS64 half: fragment, checkconf, reload ----------------
        previous = _read_previous()
        want_fragment = bool(cfg.get("enabled") and cfg.get("dns64"))
        new_fragment = self._nat64_dns64_fragment(cfg["prefix"]) if want_fragment else None
        fragment_changed = (previous or None) != (new_fragment or None)

        if fragment_changed:
            if new_fragment is None:
                if previous is not None:
                    try:
                        os.remove(NAT64_DNS64_FRAGMENT)
                        steps.append("removed " + NAT64_DNS64_FRAGMENT)
                    except OSError as exc:
                        return {"success": False, "status": "error", "id": "nat64",
                                "message": f"could not remove the DNS64 fragment: {exc}",
                                "steps": steps, "warnings": warnings}
            else:
                if not self._write_atomic(NAT64_DNS64_FRAGMENT, new_fragment):
                    return {"success": False, "status": "error", "id": "nat64",
                            "message": "could not write " + NAT64_DNS64_FRAGMENT,
                            "steps": steps, "warnings": warnings}
                steps.append("wrote " + NAT64_DNS64_FRAGMENT)

            check = self._run(["unbound-checkconf"])
            steps.append(f"unbound-checkconf rc={check.returncode}")
            if check.returncode != 0:
                # Restore the last good resolver state so a rejected fragment
                # can never leave DNS half-configured.
                if previous is None:
                    try:
                        os.remove(NAT64_DNS64_FRAGMENT)
                    except OSError:
                        pass
                    steps.append("restored previous (absent) DNS64 fragment")
                else:
                    self._write_atomic(NAT64_DNS64_FRAGMENT, previous)
                    steps.append("restored previous DNS64 fragment")
                detail = (check.stderr or check.stdout or "").strip()
                return {"success": False, "status": "error", "id": "nat64",
                        "message": "unbound-checkconf rejected the DNS64 configuration",
                        "steps": steps,
                        "warnings": warnings + ([detail[:300]] if detail else [])}

            reloaded = False
            for argv in (["unbound-control", "reload"], ["systemctl", "reload", "unbound"]):
                res = self._run(argv)
                steps.append(f"{' '.join(argv)} rc={res.returncode}")
                if res.returncode == 0:
                    reloaded = True
                    break
            if not reloaded:
                warnings.append("DNS64 fragment written but Unbound reload failed")

            # Honest surfacing: the fragment only loads if the resolver's main
            # config includes the drop-in directory.
            try:
                with open("/etc/unbound/unbound.conf", "r", encoding="utf-8", errors="replace") as handle:
                    main_conf = handle.read()
                if "unbound.conf.d" not in main_conf:
                    warnings.append("/etc/unbound/unbound.conf does not include /etc/unbound/unbound.conf.d, "
                                    "so the DNS64 fragment will not load until that include is present")
            except OSError:
                pass

        # ---------------- NAT64 half: tayga.conf, unit, services ----------------
        if not cfg.get("enabled"):
            # Feature off: tear down host network first (routes die with the
            # device), then the translator, then any generated unit file.
            if os.path.exists(NAT64_UNIT_FILE):
                res = self._run(["systemctl", "stop", NAT64_UNIT])
                steps.append(f"systemctl stop {NAT64_UNIT} rc={res.returncode}")
                res = self._run(["systemctl", "disable", NAT64_UNIT])
                steps.append(f"systemctl disable {NAT64_UNIT} rc={res.returncode}")
                try:
                    os.remove(NAT64_UNIT_FILE)
                    steps.append("removed " + NAT64_UNIT_FILE)
                except OSError as exc:
                    warnings.append(f"could not remove {NAT64_UNIT_FILE}: {exc}")
                res = self._run(["systemctl", "daemon-reload"])
                steps.append(f"systemctl daemon-reload rc={res.returncode}")
            for argv in (["systemctl", "stop", "tayga"], ["systemctl", "disable", "tayga"]):
                res = self._run(argv)
                steps.append(f"{' '.join(argv)} rc={res.returncode}")
            payload = {"success": True, "status": "applied", "id": "nat64",
                       "message": "NAT64 and DNS64 are disabled", "steps": steps, "warnings": warnings}
            self._nat64_write_state(cfg, payload)
            return payload

        conf = (
            "# MicronOS NAT64 (nat64 plugin) - generated by micrond. Do not edit.\n"
            f"tun-device {TAYGA_TUN}\n"
            f"ipv4-addr {cfg['tayga_ipv4']}\n"
            f"prefix {cfg['prefix']}\n"
            f"dynamic-pool {cfg['pool']}\n"
            f"data-dir {NAT64_STATE_DIR}\n"
        )
        if not self._write_atomic(TAYGA_CONF, conf):
            return {"success": False, "status": "error", "id": "nat64",
                    "message": "could not write " + TAYGA_CONF, "steps": steps, "warnings": warnings}
        steps.append("wrote " + TAYGA_CONF)

        unit = self._nat64_net_unit(cfg["prefix"], cfg["pool"], cfg["tayga_ipv4"])
        if not self._write_atomic(NAT64_UNIT_FILE, unit):
            return {"success": False, "status": "error", "id": "nat64",
                    "message": "could not write " + NAT64_UNIT_FILE, "steps": steps, "warnings": warnings}
        steps.append("wrote " + NAT64_UNIT_FILE)

        # The translator must be up before the host-side unit runs: tayga.service
        # creates the tun device when it starts.
        for argv in (["systemctl", "enable", "tayga"], ["systemctl", "restart", "tayga"]):
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode != 0 and argv[2] == "restart":
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "nat64",
                        "message": "tayga failed to start", "steps": steps,
                        "warnings": warnings + ([detail[:300]] if detail else [])}

        for argv in (["systemctl", "daemon-reload"],
                     ["systemctl", "enable", NAT64_UNIT],
                     ["systemctl", "restart", NAT64_UNIT]):
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode != 0:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "nat64",
                        "message": f"{argv[2]} {argv[-1]} failed", "steps": steps,
                        "warnings": warnings + ([detail[:300]] if detail else [])}

        payload = {"success": True, "status": "applied", "id": "nat64",
                   "message": "NAT64 is translating" + ("; DNS64 synthesis is on" if cfg["dns64"] else ""),
                   "steps": steps, "warnings": warnings}
        self._nat64_write_state(cfg, payload)
        return payload

    def _nat64_write_state(self, cfg: Dict[str, Any], payload: Dict[str, Any]) -> None:
        """Persist the non-secret applied configuration for the status page."""
        state = dict(cfg)
        state["applied_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        state["last_success"] = bool(payload.get("success"))
        self._write_atomic(os.path.join(NAT64_STATE_DIR, "state.json"),
                           json.dumps(state, indent=2, sort_keys=True) + "\n")

    def _nat64_read_state(self) -> Dict[str, Any]:
        try:
            with open(os.path.join(NAT64_STATE_DIR, "state.json"), "r", encoding="utf-8") as handle:
                data = strict_json_loads(handle.read())
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError, TypeError):
            return {}

    async def rpc_plugin_nat64_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: applied config plus live translator/host-network state."""
        if self._plugin_entry("nat64") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: nat64"}

        steps: list = []
        tayga_state = self._service_state("tayga")
        steps.append(f"systemctl is-active tayga -> {tayga_state}")
        net_state = self._service_state(NAT64_UNIT)
        steps.append(f"systemctl is-active {NAT64_UNIT} -> {net_state}")

        fragment_present = os.path.exists(NAT64_DNS64_FRAGMENT)
        conf_present = os.path.exists(TAYGA_CONF)

        warnings: list = []
        if not conf_present:
            warnings.append("tayga.conf is absent: NAT64 has not been applied on this appliance")

        return {
            "success": True,
            "status": "ok",
            "id": "nat64",
            "state": tayga_state,
            "data": {
                **self._nat64_read_state(),
                "tayga_state": tayga_state,
                "host_network_state": net_state,
                "tayga_conf_present": conf_present,
                "dns64_fragment_present": fragment_present,
            },
            "steps": steps,
            "warnings": warnings,
        }

    # ------------------------- darkstat (darkstat.*) -------------------------
    @staticmethod
    def _darkstat_networks(raw: Any) -> tuple:
        """Parse the local-network list into darkstat's `network/netmask` form.

        The form takes CIDR — what an operator reads and what the pfSense-style
        field accepts — and renders the dotted netmask darkstat's `-l` argument
        is documented with.
        """
        if raw is None:
            return [], None
        parts = [str(x) for x in raw] if isinstance(raw, (list, tuple)) else re.split(r"[,\s]+", str(raw))
        nets = []
        for part in parts:
            part = part.strip()
            if not part:
                continue
            try:
                net = ipaddress.ip_network(part, strict=False)
            except ValueError:
                return [], f"local network must be a CIDR (e.g. 192.168.0.0/16): {part}"
            if net.version != 4:
                return [], f"local networks must be IPv4 — darkstat's -l takes an IPv4 network: {part}"
            nets.append(f"{net.network_address}/{net.netmask}")
        return nets, None

    def _darkstat_validate(self, params: Dict[str, Any]):
        """Validate every darkstat.apply parameter before a byte is written.

        Returns `(config, None)` on success or `(None, refusal)` on rejection —
        a malformed request must not cause the daemon to write a file or run a
        command at all.
        """
        if self._plugin_entry("darkstat") is None:
            return None, {"success": False, "status": "error", "message": "unknown plugin: darkstat"}

        enabled = bool(params.get("enabled", True))
        cfg: Dict[str, Any] = {"enabled": enabled}
        if not enabled:
            return cfg, None

        # Interface list: darkstat needs at least one, and `-i` repeats per interface.
        raw_ifaces = params.get("interface", "")
        candidates = [str(x) for x in raw_ifaces] if isinstance(raw_ifaces, (list, tuple)) \
            else re.split(r"[,\s]+", str(raw_ifaces))
        ifaces: list = []
        for candidate in candidates:
            candidate = candidate.strip()
            if not candidate:
                continue
            if DARKSTAT_IFACE_RE.fullmatch(candidate) is None:
                return None, {"success": False, "status": "error",
                              "message": f"interface must be a kernel interface name (1-15 chars): {candidate}"}
            if candidate not in ifaces:
                ifaces.append(candidate)
        if not ifaces:
            return None, {"success": False, "status": "error",
                          "message": "interface is required: darkstat must be told which interface to capture on"}

        try:
            port = int(params.get("port", 666))
        except (TypeError, ValueError):
            return None, {"success": False, "status": "error", "message": "port must be a whole number"}
        if not 1 <= port <= 65535:
            return None, {"success": False, "status": "error", "message": "port must be between 1 and 65535"}

        nets, net_error = self._darkstat_networks(params.get("local_nets", ""))
        if net_error:
            return None, {"success": False, "status": "error", "message": net_error}

        filt = str(params.get("filter", "")).strip()
        if len(filt) > 500 or "\n" in filt or "\r" in filt or "\x00" in filt:
            return None, {"success": False, "status": "error",
                          "message": "filter must be a single-line BPF expression of at most 500 characters"}

        chroot_dir = str(params.get("chroot_dir", "") or DARKSTAT_CHROOT_DEFAULT).strip()
        if DARKSTAT_CHROOT_RE.fullmatch(chroot_dir) is None or ".." in chroot_dir:
            return None, {"success": False, "status": "error",
                          "message": "chroot directory must be an absolute path with no '..'"}

        database = str(params.get("database_file", "") or "darkstat.db").strip()
        if database and DARKSTAT_FILE_RE.fullmatch(database) is None:
            return None, {"success": False, "status": "error",
                          "message": "database file must be a bare filename (it is relative to the chroot directory)"}

        daylog = str(params.get("daylog", "") or "").strip()
        if daylog and DARKSTAT_FILE_RE.fullmatch(daylog) is None:
            return None, {"success": False, "status": "error",
                          "message": "daylog must be a bare filename (it is relative to the chroot directory)"}

        try:
            hosts_max = int(params.get("hosts_max", 1000))
            hosts_keep = int(params.get("hosts_keep", 30))
        except (TypeError, ValueError):
            return None, {"success": False, "status": "error", "message": "hosts values must be whole numbers"}
        if not 2 <= hosts_max <= 100000:
            return None, {"success": False, "status": "error", "message": "hosts-max must be between 2 and 100000"}
        if not 1 <= hosts_keep < hosts_max:
            return None, {"success": False, "status": "error",
                          "message": "hosts-keep must be at least 1 and less than hosts-max (darkstat empties the "
                                     "table down to this many hosts when it fills)"}

        cfg.update({
            "interfaces": ifaces,
            "port": port,
            "local_nets": nets,
            "filter": filt,
            "resolve_dns": bool(params.get("resolve_dns", True)),
            "local_only": bool(params.get("local_only", False)),
            "chroot_dir": chroot_dir,
            "database_file": database,
            "daylog": daylog,
            "hosts_max": hosts_max,
            "hosts_keep": hosts_keep,
        })
        return cfg, None

    @staticmethod
    def _darkstat_argv(cfg: Dict[str, Any]) -> list:
        """The fixed argv the drop-in's ExecStart runs.

        `--no-daemon` because systemd (Type=simple) supervises the process
        itself; `--syslog` so its messages land in the journal rather than an
        unread stderr. Everything else comes from the validated config.
        """
        argv = [DARKSTAT_BIN]
        for iface in cfg["interfaces"]:
            argv += ["-i", iface]
        argv += ["-p", str(cfg["port"])]
        for net in cfg["local_nets"]:
            argv += ["-l", net]
        if cfg["filter"]:
            argv += ["-f", cfg["filter"]]
        if not cfg["resolve_dns"]:
            argv.append("--no-dns")
        if cfg["local_only"]:
            argv.append("--local-only")
        # darkstat drops root after opening the capture and the web socket; it
        # writes its export database inside the chroot as that user, so the
        # unit makes the directory and gives it to the same account.
        argv += ["--chroot", cfg["chroot_dir"], "--user", DARKSTAT_USER]
        if cfg["database_file"]:
            argv += ["--import", cfg["database_file"], "--export", cfg["database_file"]]
        if cfg["daylog"]:
            argv += ["--daylog", cfg["daylog"]]
        argv += ["--hosts-max", str(cfg["hosts_max"]), "--hosts-keep", str(cfg["hosts_keep"])]
        argv += ["--syslog", "--no-daemon"]
        return argv

    @staticmethod
    def _systemd_quote(arg: str) -> str:
        """Quote one ExecStart argument for systemd's own command parser.

        `%` is systemd's specifier prefix and is doubled wherever it appears; an
        argument with anything outside systemd's unquoted set is wrapped in
        double quotes with backslash escapes, so a BPF filter containing spaces
        (or quotes) still arrives as a single argument.
        """
        text = str(arg).replace("%", "%%")
        if re.fullmatch(r"[A-Za-z0-9._/:@,+=*-]+", text):
            return text
        return '"' + text.replace("\\", "\\\\").replace('"', '\\"') + '"'

    def _darkstat_dropin(self, cfg: Dict[str, Any]) -> str:
        command = " ".join(self._systemd_quote(a) for a in self._darkstat_argv(cfg))
        chroot = self._systemd_quote(cfg["chroot_dir"])
        # Debian ships darkstat as a SysV script (gated off by START_DARKSTAT=no
        # in /etc/darkstat/init.cfg), which systemd wraps as a Type=forking,
        # RemainAfterExit unit. Replacing only ExecStart left that wrapper in
        # place: systemd waited for a fork that `--no-daemon` never does, killed
        # darkstat at the start timeout and still reported the unit "active",
        # so nothing listened on the web port. Every setting that wrapper sets
        # is reset here, so the drop-in is correct over a native unit too.
        return (
            "# MicronOS darkstat settings (darkstat plugin) - generated by micrond. Do not edit.\n"
            "[Unit]\n"
            "Description=darkstat network traffic analyser (MicronOS)\n"
            "After=network-online.target\n"
            "Wants=network-online.target\n"
            "\n"
            "[Service]\n"
            "Type=simple\n"
            "RemainAfterExit=no\n"
            "GuessMainPID=yes\n"
            "KillMode=control-group\n"
            "TimeoutStartSec=30\n"
            "Restart=on-failure\n"
            "RestartSec=5\n"
            "ExecStartPre=\n"
            f"ExecStartPre=/usr/bin/install -d -m 0750 -o {DARKSTAT_USER} -g {DARKSTAT_GROUP} {chroot}\n"
            "ExecStart=\n"
            f"ExecStart={command}\n"
            "ExecStop=\n"
            "ExecReload=\n"
            "\n"
            "[Install]\n"
            "WantedBy=multi-user.target\n"
        )

    async def rpc_plugin_darkstat_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply darkstat's settings through a systemd drop-in over its own unit.

        darkstat is configured entirely on the command line, so applying a
        setting means rewriting the drop-in's ExecStart and restarting the
        package's unit. Nothing else is touched, and the unit name stays the
        package's — which is what the generic plugin status/control resolves.
        """
        cfg, refusal = self._darkstat_validate(params)
        if refusal is not None:
            return refusal

        steps: list = []
        warnings: list = []

        if not cfg.get("enabled"):
            if os.path.exists(DARKSTAT_DROPIN):
                try:
                    os.remove(DARKSTAT_DROPIN)
                    steps.append("removed " + DARKSTAT_DROPIN)
                except OSError as exc:
                    return {"success": False, "status": "error", "id": "darkstat",
                            "message": f"could not remove {DARKSTAT_DROPIN}: {exc}",
                            "steps": steps, "warnings": warnings}
            for argv in (["systemctl", "daemon-reload"],
                         ["systemctl", "disable", "darkstat"],
                         ["systemctl", "stop", "darkstat"]):
                res = self._run(argv)
                steps.append(f"{' '.join(argv)} rc={res.returncode}")
            payload = {"success": True, "status": "applied", "id": "darkstat",
                       "message": "darkstat is disabled", "steps": steps, "warnings": warnings}
            self._darkstat_write_state(cfg, payload)
            return payload

        if not self._write_atomic(DARKSTAT_DROPIN, self._darkstat_dropin(cfg)):
            return {"success": False, "status": "error", "id": "darkstat",
                    "message": "could not write " + DARKSTAT_DROPIN,
                    "steps": steps, "warnings": warnings}
        steps.append("wrote " + DARKSTAT_DROPIN)

        for argv in (["systemctl", "daemon-reload"],
                     ["systemctl", "enable", "darkstat"],
                     ["systemctl", "restart", "darkstat"]):
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode != 0:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "darkstat",
                        "message": f"{argv[1]} darkstat failed", "steps": steps,
                        "warnings": warnings + ([detail[:300]] if detail else [])}

        missing = [i for i in cfg["interfaces"] if not os.path.exists(os.path.join("/sys/class/net", i))]
        if missing:
            warnings.append("capture interface not present on this appliance: " + ", ".join(missing)
                            + " (darkstat will fail to start until it exists)")
        payload = {"success": True, "status": "applied", "id": "darkstat",
                   "message": f"darkstat is capturing on {', '.join(cfg['interfaces'])}; "
                              f"web UI on port {cfg['port']}",
                   "steps": steps, "warnings": warnings}
        self._darkstat_write_state(cfg, payload)
        return payload

    def _darkstat_write_state(self, cfg: Dict[str, Any], payload: Dict[str, Any]) -> None:
        state = dict(cfg)
        state["applied_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        state["last_success"] = bool(payload.get("success"))
        self._write_atomic(os.path.join(DARKSTAT_STATE_DIR, "state.json"),
                           json.dumps(state, indent=2, sort_keys=True) + "\n")

    def _darkstat_read_state(self) -> Dict[str, Any]:
        try:
            with open(os.path.join(DARKSTAT_STATE_DIR, "state.json"), "r", encoding="utf-8") as handle:
                data = strict_json_loads(handle.read())
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError, TypeError):
            return {}

    @staticmethod
    def _darkstat_listening(port: int) -> bool:
        """Whether something accepts TCP connections on the darkstat web port."""
        for host in ("127.0.0.1", "::1"):
            try:
                with socket.create_connection((host, port), timeout=0.5):
                    return True
            except OSError:
                continue
        return False

    async def rpc_plugin_darkstat_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: the applied settings plus the unit's live state."""
        if self._plugin_entry("darkstat") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: darkstat"}

        state = self._service_state(DARKSTAT_UNIT)
        steps = [f"systemctl is-active {DARKSTAT_UNIT} -> {state}"]
        dropin_present = os.path.exists(DARKSTAT_DROPIN)
        applied = self._darkstat_read_state()

        warnings: list = []
        if not dropin_present:
            warnings.append("no darkstat settings have been applied on this appliance yet")

        # "active" from systemd is not proof the web UI answers: probe the port.
        listening = None
        port = applied.get("port") if applied.get("enabled", True) else None
        if dropin_present and isinstance(port, int) and 1 <= port <= 65535:
            listening = self._darkstat_listening(port)
            steps.append(f"connect 127.0.0.1:{port} -> {'open' if listening else 'refused'}")
            if state == "running" and not listening:
                warnings.append(f"darkstat is running but nothing answers on port {port} yet")
            elif state != "running":
                warnings.append(f"darkstat is {state}; check: journalctl -u {DARKSTAT_UNIT}")
        missing = [i for i in applied.get("interfaces", []) or []
                   if isinstance(i, str) and not os.path.exists(os.path.join("/sys/class/net", i))]
        if missing:
            warnings.append("capture interface not present on this appliance: " + ", ".join(missing))

        return {
            "success": True,
            "status": "ok",
            "id": "darkstat",
            "state": state,
            "data": {
                **self._darkstat_read_state(),
                "service_state": state,
                "dropin_present": dropin_present,
                "listening": listening,
                "missing_interfaces": missing,
            },
            "steps": steps,
            "warnings": warnings,
        }

    # ---------------------- lightsquid (lightsquid.*) ------------------------
    def _lightsquid_validate(self, params: Dict[str, Any]):
        """Validate every lightsquid.apply parameter before a byte is written.

        Returns `(config, None)` on success or `(None, refusal)` on rejection —
        a malformed request must not cause the daemon to write a file at all.
        """
        if self._plugin_entry("lightsquid") is None:
            return None, {"success": False, "status": "error", "message": "unknown plugin: lightsquid"}

        enabled = bool(params.get("enabled", True))
        cfg: Dict[str, Any] = {"enabled": enabled}
        if not enabled:
            return cfg, None

        lang = str(params.get("lang", "") or "eng").strip().lower()
        if lang not in LIGHTSQUID_LANGS:
            return None, {"success": False, "status": "error",
                          "message": "language must be one of: " + ", ".join(sorted(LIGHTSQUID_LANGS))}

        template = str(params.get("template", "") or "base").strip().lower()
        if template not in LIGHTSQUID_TEMPLATES:
            return None, {"success": False, "status": "error",
                          "message": "report template must be one of: " + ", ".join(sorted(LIGHTSQUID_TEMPLATES))}

        barcolor = str(params.get("barcolor", "") or "orange").strip().lower()
        if barcolor not in LIGHTSQUID_BARCOLORS:
            return None, {"success": False, "status": "error",
                          "message": "bar colour must be one of: " + ", ".join(sorted(LIGHTSQUID_BARCOLORS))}

        ip2name = str(params.get("ip2name", "") or "dns").strip().lower()
        if ip2name not in LIGHTSQUID_IP2NAMES:
            return None, {"success": False, "status": "error",
                          "message": "IP resolve method must be one of: " + ", ".join(sorted(LIGHTSQUID_IP2NAMES))}

        schedule = str(params.get("schedule", "") or LIGHTSQUID_SCHEDULE_DEFAULT).strip().lower()
        if schedule not in LIGHTSQUID_SCHEDULES:
            return None, {"success": False, "status": "error",
                          "message": "refresh schedule must be one of: " + ", ".join(sorted(LIGHTSQUID_SCHEDULES))}

        log_dir = str(params.get("log_dir", "") or LIGHTSQUID_LOG_DIR_DEFAULT).strip()
        if LIGHTSQUID_PATH_RE.fullmatch(log_dir) is None or ".." in log_dir:
            return None, {"success": False, "status": "error",
                          "message": "Squid log directory must be an absolute path with no '..'"}

        report_dir = str(params.get("report_dir", "") or LIGHTSQUID_REPORT_DIR_DEFAULT).strip()
        if LIGHTSQUID_PATH_RE.fullmatch(report_dir) is None or ".." in report_dir:
            return None, {"success": False, "status": "error",
                          "message": "report directory must be an absolute path with no '..'"}

        # Skip list: each entry is an IP, hostname, domain or subnet. It is
        # rendered as the reporter's own pipe-joined pattern with every dot
        # escaped — and an empty list becomes the shipped 'zzz\\.zzz' sentinel,
        # because an empty pattern would match (and skip) everything.
        raw_skip = str(params.get("skipurl", "") or "")
        if len(raw_skip) > 1000 or any(ch in raw_skip for ch in "\r\n\x00"):
            return None, {"success": False, "status": "error",
                          "message": "skip URLs must be a single line of at most 1000 characters"}
        entries: list = []
        for part in raw_skip.split("|"):
            part = part.strip()
            if not part:
                continue
            if LIGHTSQUID_SKIP_RE.fullmatch(part) is None:
                return None, {"success": False, "status": "error",
                              "message": f"skip URL must be an IP address, hostname, domain or subnet: {part}"}
            if part not in entries:
                entries.append(part)
        pattern = "|".join(entries) if entries else "zzz.zzz"

        cfg.update({
            "lang": lang,
            "template": template,
            "barcolor": barcolor,
            "ip2name": ip2name,
            "schedule": schedule,
            "log_dir": log_dir,
            "report_dir": report_dir,
            "skipurl": "|".join(entries),
            "skipurl_pattern": pattern.replace(".", "\\."),
            "cron": LIGHTSQUID_SCHEDULES[schedule],
        })
        return cfg, None

    @staticmethod
    def _lightsquid_config(cfg: Dict[str, Any]) -> str:
        """The generated lightsquid config file.

        Every value is bounded by validation (fixed sets and absolute paths), so
        the values are rendered in double quotes and the skip pattern in single
        quotes — the shape the reporter's config parser expects.
        """
        return (
            "# MicronOS lightsquid settings (lightsquid plugin) - generated by micrond. Do not edit.\n"
            f'$cfgpath = "{LIGHTSQUID_CONFIG_DIR}";\n'
            f'$logpath = "{cfg["log_dir"]}";\n'
            f'$reportpath = "{cfg["report_dir"]}";\n'
            f'$lang = "{cfg["lang"]}";\n'
            f'$templatename = "{cfg["template"]}";\n'
            f'$barcolor = "{cfg["barcolor"]}";\n'
            f'$ip2name = "{cfg["ip2name"]}";\n'
            f"$skipurl = '{cfg['skipurl_pattern']}';\n"
            "$squidlogtype = 0;\n"
        )

    @staticmethod
    def _lightsquid_cron(cfg: Dict[str, Any]) -> str:
        """The generated cron drop-in that regenerates the reports.

        The refresh period is the interval job; the fixed 00:15 'yesterday' job
        is pfSense's own guard against a run starting at midnight losing the day
        that just rolled over.
        """
        minute, hour = cfg["cron"]
        parser = f"{LIGHTSQUID_PERL} {LIGHTSQUID_PARSER}"
        return (
            "# /etc/cron.d/lightsquid - generated by micron for the lightsquid plugin. Do not edit.\n"
            "SHELL=/bin/sh\n"
            "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n"
            "\n"
            f"{minute} {hour} * * *  root  {parser} today\n"
            f"15 0 * * *  root  {parser} yesterday\n"
        )

    async def rpc_plugin_lightsquid_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply lightsquid's settings: write the config file and the cron drop-in.

        Lightsquid has no daemon, so there is no unit to restart: the generated
        config file is what the reporter reads and the cron drop-in is what makes
        it run. An empty schedule removes the drop-in and leaves only the config,
        so an operator can keep the settings without a background job.
        """
        cfg, refusal = self._lightsquid_validate(params)
        if refusal is not None:
            return refusal

        steps: list = []
        warnings: list = []

        if not cfg.get("enabled"):
            for path in (LIGHTSQUID_CRON, LIGHTSQUID_CONFIG):
                if os.path.exists(path):
                    try:
                        os.remove(path)
                        steps.append("removed " + path)
                    except OSError as exc:
                        return {"success": False, "status": "error", "id": "lightsquid",
                                "message": f"could not remove {path}: {exc}",
                                "steps": steps, "warnings": warnings}
            payload = {"success": True, "status": "applied", "id": "lightsquid",
                       "message": "lightsquid reporting is disabled", "steps": steps, "warnings": warnings}
            self._lightsquid_write_state(cfg, payload)
            return payload

        if not self._write_atomic(LIGHTSQUID_CONFIG, self._lightsquid_config(cfg)):
            return {"success": False, "status": "error", "id": "lightsquid",
                    "message": "could not write " + LIGHTSQUID_CONFIG,
                    "steps": steps, "warnings": warnings}
        steps.append("wrote " + LIGHTSQUID_CONFIG)

        if cfg["cron"] is None:
            if os.path.exists(LIGHTSQUID_CRON):
                try:
                    os.remove(LIGHTSQUID_CRON)
                    steps.append("removed " + LIGHTSQUID_CRON)
                except OSError as exc:
                    warnings.append(f"could not remove {LIGHTSQUID_CRON}: {exc}")
            else:
                steps.append("no schedule configured")
        else:
            if not self._write_atomic(LIGHTSQUID_CRON, self._lightsquid_cron(cfg)):
                return {"success": False, "status": "error", "id": "lightsquid",
                        "message": "could not write " + LIGHTSQUID_CRON,
                        "steps": steps, "warnings": warnings}
            steps.append("wrote " + LIGHTSQUID_CRON)

        message = (f"lightsquid reports will be regenerated every {cfg['schedule']}"
                   if cfg["cron"] is not None
                   else "lightsquid configured; scheduled generation is off")
        payload = {"success": True, "status": "applied", "id": "lightsquid",
                   "message": message, "steps": steps, "warnings": warnings}
        self._lightsquid_write_state(cfg, payload)
        return payload

    def _lightsquid_write_state(self, cfg: Dict[str, Any], payload: Dict[str, Any]) -> None:
        state = dict(cfg)
        state["applied_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        state["last_success"] = bool(payload.get("success"))
        self._write_atomic(os.path.join(LIGHTSQUID_STATE_DIR, "state.json"),
                           json.dumps(state, indent=2, sort_keys=True) + "\n")

    def _lightsquid_read_state(self) -> Dict[str, Any]:
        try:
            with open(os.path.join(LIGHTSQUID_STATE_DIR, "state.json"), "r", encoding="utf-8") as handle:
                data = strict_json_loads(handle.read())
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError, TypeError):
            return {}

    async def rpc_plugin_lightsquid_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: the applied settings, the files on disk, and the report set."""
        if self._plugin_entry("lightsquid") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: lightsquid"}

        state = self._lightsquid_read_state()
        config_present = os.path.exists(LIGHTSQUID_CONFIG)
        cron_present = os.path.exists(LIGHTSQUID_CRON)
        report_dir = str(state.get("report_dir") or LIGHTSQUID_REPORT_DIR_DEFAULT)

        report_count = 0
        report_newest = ""
        try:
            names = [n for n in os.listdir(report_dir) if not n.startswith(".")]
            report_count = len(names)
            if names:
                newest = max(os.path.getmtime(os.path.join(report_dir, n)) for n in names)
                report_newest = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(newest))
        except OSError:
            pass

        warnings: list = []
        if not config_present:
            warnings.append("lightsquid has not been configured on this appliance yet")
        elif not cron_present:
            warnings.append("no report schedule is configured; reports are generated on demand only")

        return {
            "success": True,
            "status": "ok",
            "id": "lightsquid",
            "state": "configured" if config_present else "unconfigured",
            "data": {
                **state,
                "config_present": config_present,
                "cron_present": cron_present,
                "report_dir": report_dir,
                "report_count": report_count,
                "report_newest": report_newest,
            },
            "steps": [
                f"{LIGHTSQUID_CONFIG} -> {'present' if config_present else 'absent'}",
                f"{LIGHTSQUID_CRON} -> {'present' if cron_present else 'absent'}",
            ],
            "warnings": warnings,
        }

    async def rpc_plugin_lightsquid_refresh(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Run the parser now — pfSense's 'Refresh now' / 'Refresh full'.

        `today` re-parses only the current access log; `full` re-parses it and
        every rotated `access.log.N` present, at most the first hundred, which is
        pfSense's own bound on an operator with an unreasonable rotation count.
        """
        if self._plugin_entry("lightsquid") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: lightsquid"}

        mode = str(params.get("mode", "") or "today").strip().lower()
        if mode not in ("today", "full"):
            return {"success": False, "status": "error",
                    "message": "mode must be 'today' or 'full'"}

        state = self._lightsquid_read_state()
        log_dir = str(state.get("log_dir") or LIGHTSQUID_LOG_DIR_DEFAULT)

        targets: list = []
        if os.path.exists(os.path.join(log_dir, LIGHTSQUID_LOG_FILE)):
            targets.append(LIGHTSQUID_LOG_FILE)
        if mode == "full":
            for index in range(100):
                name = f"{LIGHTSQUID_LOG_FILE}.{index}"
                if os.path.exists(os.path.join(log_dir, name)):
                    targets.append(name)
                else:
                    break

        steps: list = []
        if not targets:
            return {"success": True, "status": "ok", "id": "lightsquid",
                    "message": f"no Squid access log to parse in {log_dir}", "steps": steps,
                    "warnings": [f"{os.path.join(log_dir, LIGHTSQUID_LOG_FILE)} does not exist"]}

        for target in targets:
            argv = [LIGHTSQUID_PERL, LIGHTSQUID_PARSER]
            argv += ["today"] if mode == "today" else [target]
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode != 0:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "lightsquid",
                        "message": "the lightsquid parser failed", "steps": steps,
                        "warnings": [detail[:300]] if detail else []}

        return {"success": True, "status": "ok", "id": "lightsquid",
                "message": f"parsed {len(targets)} Squid log file(s)", "steps": steps, "warnings": []}

    # --------------------------- lldpd (lldpd.*) -----------------------------
    def _lldpd_validate(self, params: Dict[str, Any]):
        """Validate every lldpd.apply parameter before a byte is written.

        Returns `(config, None)` on success or `(None, refusal)` on rejection —
        a malformed request must not cause the daemon to write a file or run a
        command at all.
        """
        if self._plugin_entry("lldpd") is None:
            return None, {"success": False, "status": "error", "message": "unknown plugin: lldpd"}

        enabled = bool(params.get("enabled", True))
        cfg: Dict[str, Any] = {"enabled": enabled}
        if not enabled:
            return cfg, None

        # An empty pattern means every physical interface, which is lldpd's own
        # default when `-I` is absent.
        pattern = str(params.get("interfaces", "") or "").strip()
        if pattern and (LLDPD_PATTERN_RE.fullmatch(pattern) is None or ".." in pattern):
            return None, {"success": False, "status": "error",
                          "message": "interfaces must be kernel interface names, globs and !-inversions, "
                                     "comma-separated (e.g. eth*,!eth1 or eth0,eth1)"}

        mgmt = str(params.get("management_addresses", "") or "").strip()
        if mgmt and LLDPD_MGMT_RE.fullmatch(mgmt) is None:
            return None, {"success": False, "status": "error",
                          "message": "management addresses must be a comma-separated pattern of addresses "
                                     "or interfaces (e.g. !*:* or eth0)"}

        description = str(params.get("system_description", "") or "").strip()
        if len(description) > 255 or any(ch in description for ch in "\r\n\x00"):
            return None, {"success": False, "status": "error",
                          "message": "system description must be a single line of at most 255 characters"}

        platform = str(params.get("platform", "") or "").strip()
        if len(platform) > 255 or any(ch in platform for ch in "\r\n\x00"):
            return None, {"success": False, "status": "error",
                          "message": "platform must be a single line of at most 255 characters"}

        hostname = str(params.get("system_name", "") or "").strip()
        if hostname and LLDPD_HOSTNAME_RE.fullmatch(hostname) is None:
            return None, {"success": False, "status": "error",
                          "message": "system name must be a hostname (letters, digits, dots and hyphens)"}

        try:
            tx_interval = int(params.get("tx_interval", LLDPD_TX_INTERVAL_DEFAULT))
            tx_hold = int(params.get("tx_hold", LLDPD_TX_HOLD_DEFAULT))
        except (TypeError, ValueError):
            return None, {"success": False, "status": "error", "message": "transmit values must be whole numbers"}
        if not 1 <= tx_interval <= 3600:
            return None, {"success": False, "status": "error", "message": "transmit interval must be 1-3600 seconds"}
        if not 1 <= tx_hold <= 100:
            return None, {"success": False, "status": "error", "message": "transmit hold must be 1-100"}

        cfg.update({
            "interfaces": pattern,
            "management_addresses": mgmt,
            "system_description": description,
            "platform": platform,
            "system_name": hostname,
            "receive_only": bool(params.get("receive_only", False)),
            "cdp": bool(params.get("cdp", False)),
            "edp": bool(params.get("edp", False)),
            "fdp": bool(params.get("fdp", False)),
            "sonmp": bool(params.get("sonmp", False)),
            "snmp_subagent": bool(params.get("snmp_subagent", False)),
            "suppress_kernel_version": bool(params.get("suppress_kernel_version", False)),
            "tx_interval": tx_interval,
            "tx_hold": tx_hold,
        })
        return cfg, None

    @staticmethod
    def _lldpd_argv(cfg: Dict[str, Any]) -> list:
        """The fixed argv the drop-in's ExecStart runs.

        `-d` runs the daemon in the foreground, which is what the package's own
        Type=notify unit expects. Everything else comes from the validated
        config; an absent flag is simply not emitted, so lldpd keeps its own
        default for it.
        """
        argv = [LLDPD_BIN, "-d"]
        if cfg["interfaces"]:
            argv += ["-I", cfg["interfaces"]]
        if cfg["receive_only"]:
            argv.append("-r")
        if cfg["cdp"]:
            argv.append("-c")
        if cfg["fdp"]:
            argv.append("-f")
        if cfg["sonmp"]:
            argv.append("-s")
        if cfg["edp"]:
            argv.append("-e")
        if cfg["snmp_subagent"]:
            argv.append("-x")
        if cfg["suppress_kernel_version"]:
            argv.append("-k")
        if cfg["system_description"]:
            argv += ["-S", cfg["system_description"]]
        if cfg["platform"]:
            argv += ["-P", cfg["platform"]]
        if cfg["management_addresses"]:
            argv += ["-m", cfg["management_addresses"]]
        return argv

    def _lldpd_dropin(self, cfg: Dict[str, Any]) -> str:
        command = " ".join(self._systemd_quote(a) for a in self._lldpd_argv(cfg))
        return (
            "# MicronOS lldpd settings (lldpd plugin) - generated by micrond. Do not edit.\n"
            "[Service]\n"
            "ExecStart=\n"
            f"ExecStart={command}\n"
        )

    @staticmethod
    def _lldpd_config(cfg: Dict[str, Any]) -> str:
        """The generated `/etc/lldpd.conf`.

        These are the settings only `lldpcli` can apply at start — the transmit
        interval and hold, plus an optional hostname override. The values are
        bounded by validation, so the hostname is the only free-form one and it
        is quoted.
        """
        lines = [
            "# MicronOS lldpd settings (lldpd plugin) - generated by micrond. Do not edit.",
            f"configure lldp tx-interval {cfg['tx_interval']}",
            f"configure lldp tx-hold {cfg['tx_hold']}",
        ]
        if cfg["system_name"]:
            lines.append(f'configure system hostname "{cfg["system_name"]}"')
        return "\n".join(lines) + "\n"

    async def rpc_plugin_lldpd_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply lldpd's settings through a systemd drop-in over its own unit.

        Most of lldpd's options are command line, so applying a setting means
        rewriting the drop-in's ExecStart (and the few `lldpcli` settings in
        `/etc/lldpd.conf`) and restarting the package's unit. Nothing else is
        touched, and the unit name stays the package's.
        """
        cfg, refusal = self._lldpd_validate(params)
        if refusal is not None:
            return refusal

        steps: list = []
        warnings: list = []

        if not cfg.get("enabled"):
            for path in (LLDPD_DROPIN, LLDPD_CONFIG):
                if os.path.exists(path):
                    try:
                        os.remove(path)
                        steps.append("removed " + path)
                    except OSError as exc:
                        return {"success": False, "status": "error", "id": "lldpd",
                                "message": f"could not remove {path}: {exc}",
                                "steps": steps, "warnings": warnings}
            for argv in (["systemctl", "daemon-reload"],
                         ["systemctl", "disable", LLDPD_UNIT],
                         ["systemctl", "stop", LLDPD_UNIT]):
                res = self._run(argv)
                steps.append(f"{' '.join(argv)} rc={res.returncode}")
            payload = {"success": True, "status": "applied", "id": "lldpd",
                       "message": "lldpd is disabled", "steps": steps, "warnings": warnings}
            self._lldpd_write_state(cfg, payload)
            return payload

        if not self._write_atomic(LLDPD_DROPIN, self._lldpd_dropin(cfg)):
            return {"success": False, "status": "error", "id": "lldpd",
                    "message": "could not write " + LLDPD_DROPIN,
                    "steps": steps, "warnings": warnings}
        steps.append("wrote " + LLDPD_DROPIN)

        if not self._write_atomic(LLDPD_CONFIG, self._lldpd_config(cfg)):
            return {"success": False, "status": "error", "id": "lldpd",
                    "message": "could not write " + LLDPD_CONFIG,
                    "steps": steps, "warnings": warnings}
        steps.append("wrote " + LLDPD_CONFIG)

        for argv in (["systemctl", "daemon-reload"],
                     ["systemctl", "enable", LLDPD_UNIT],
                     ["systemctl", "restart", LLDPD_UNIT]):
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode != 0:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "lldpd",
                        "message": f"{argv[1]} {LLDPD_UNIT} failed", "steps": steps,
                        "warnings": warnings + ([detail[:300]] if detail else [])}

        payload = {"success": True, "status": "applied", "id": "lldpd",
                   "message": "lldpd is advertising on "
                              + (cfg["interfaces"] or "all physical interfaces"),
                   "steps": steps, "warnings": warnings}
        self._lldpd_write_state(cfg, payload)
        return payload

    def _lldpd_write_state(self, cfg: Dict[str, Any], payload: Dict[str, Any]) -> None:
        state = dict(cfg)
        state["applied_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        state["last_success"] = bool(payload.get("success"))
        self._write_atomic(os.path.join(LLDPD_STATE_DIR, "state.json"),
                           json.dumps(state, indent=2, sort_keys=True) + "\n")

    def _lldpd_read_state(self) -> Dict[str, Any]:
        try:
            with open(os.path.join(LLDPD_STATE_DIR, "state.json"), "r", encoding="utf-8") as handle:
                data = strict_json_loads(handle.read())
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError, TypeError):
            return {}

    async def rpc_plugin_lldpd_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: the applied settings plus the unit's live state."""
        if self._plugin_entry("lldpd") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: lldpd"}

        state = self._service_state(LLDPD_UNIT)
        dropin_present = os.path.exists(LLDPD_DROPIN)
        config_present = os.path.exists(LLDPD_CONFIG)

        warnings: list = []
        if not dropin_present:
            warnings.append("no lldpd settings have been applied on this appliance yet")

        return {
            "success": True,
            "status": "ok",
            "id": "lldpd",
            "state": state,
            "data": {
                **self._lldpd_read_state(),
                "service_state": state,
                "dropin_present": dropin_present,
                "config_present": config_present,
            },
            "steps": [
                f"systemctl is-active {LLDPD_UNIT} -> {state}",
                f"{LLDPD_DROPIN} -> {'present' if dropin_present else 'absent'}",
            ],
            "warnings": warnings,
        }

    @staticmethod
    def _lldp_parse_neighbours(raw: str) -> tuple:
        """Normalise `lldpcli -f json0 show neighbors` into one row per neighbour.

        `json0` is the variant asked for because it keeps the shape stable: every
        collection stays an array of objects even when it holds a single member,
        which the plain `json` variant collapses (and re-keys by `name`). The
        coercions below still accept that looser shape, so a caller that ran the
        command by hand and pasted the output cannot break the page.
        """
        try:
            payload = strict_json_loads(raw)
        except (ValueError, TypeError) as exc:
            return [], f"the neighbour table was not valid JSON: {exc}"
        if not isinstance(payload, dict):
            return [], "the neighbour table was not a JSON object"

        lldp = payload.get("lldp")
        if not isinstance(lldp, dict):
            return [], None
        entries = lldp.get("interface")
        if entries is None:
            return [], None          # well-formed, simply no neighbours
        if isinstance(entries, dict):
            entries = [entries]
        if not isinstance(entries, list):
            return [], None

        def unwrap(value):
            """Resolve one collection to `(key, object)`.

            json0 stores every object in a one-element array — `[ {...} ]` — so
            the array is peeled off. Plain `json` drops that array and, when the
            object has a `name`, re-wraps it under that name — `{"eth1": {...}}`
            — so the wrapper key is returned too (the object itself no longer
            carries `name`). A json0 `id` is a dict, so a lone `id` key is never
            mistaken for a wrapper.
            """
            if isinstance(value, list):
                return None, (value[0] if value else None)
            if not isinstance(value, dict):
                return None, None
            if len(value) == 1:
                key, member = next(iter(value.items()))
                if isinstance(member, dict) and key != "id":
                    return key, member
            return None, value

        def ident(container):
            if not isinstance(container, dict):
                return None
            value = container.get("id")
            if isinstance(value, dict):
                value = value.get("value")
            return value if isinstance(value, str) and value != "" else None

        def field(container, key):
            if not isinstance(container, dict):
                return None
            value = container.get(key)
            if isinstance(value, list):
                value = value[0] if value else None
            return value if isinstance(value, str) and value != "" else None

        rows = []
        for raw_entry in entries:
            key, entry = unwrap(raw_entry)
            if not isinstance(entry, dict):
                continue
            local = entry.get("name") or key
            if not isinstance(local, str) or local == "":
                continue
            chassis_key, chassis = unwrap(entry.get("chassis"))
            port_key, port = unwrap(entry.get("port"))
            rows.append({
                "interface": local,
                "via": field(entry, "via"),
                "age": field(entry, "age"),
                "chassis": field(chassis, "name") or chassis_key,
                "chassis_id": ident(chassis),
                "chassis_descr": field(chassis, "descr"),
                "mgmt_ip": field(chassis, "mgmt-ip"),
                "port": ident(port) or port_key,
                "port_descr": field(port, "descr"),
                "ttl": field(port, "ttl"),
            })
        return rows, None

    async def rpc_plugin_lldpd_neighbours(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: the neighbour table.

        `format=text` (the default) returns `lldpcli show neighbors` verbatim for
        the plugin page. `format=json` runs the same command with `-f json0` and
        returns one structured row per neighbour, so a caller that needs the peer
        by its *local* interface — the interface inventory — never has to parse
        the human-readable table.
        """
        if self._plugin_entry("lldpd") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: lldpd"}

        fmt = str(params.get("format", "") or "text").strip().lower()
        if fmt not in ("text", "json"):
            return {"success": False, "status": "error",
                    "message": "format must be 'text' or 'json'"}

        argv = [LLDPD_CLI, "-f", "json0", "show", "neighbors"] if fmt == "json" \
            else [LLDPD_CLI, "show", "neighbors"]

        res = self._run(argv)
        steps = [f"{' '.join(argv)} rc={res.returncode}"]
        if res.returncode != 0:
            detail = (res.stderr or res.stdout or "").strip()
            return {"success": False, "status": "error", "id": "lldpd",
                    "message": "could not read the neighbour table", "steps": steps,
                    "warnings": [detail[:300]] if detail else []}

        raw = res.stdout or ""
        truncated = len(raw) > LLDPD_NEIGHBOURS_MAX
        if truncated:
            raw = raw[:LLDPD_NEIGHBOURS_MAX]

        warnings: list = []
        if truncated:
            warnings.append("the neighbour listing was truncated")

        if fmt == "json":
            rows, error = self._lldp_parse_neighbours(raw)
            if error is not None:
                return {"success": False, "status": "error", "id": "lldpd",
                        "message": "could not parse the neighbour table", "steps": steps,
                        "warnings": [error]}
            if not rows:
                warnings.append("no LLDP neighbours are currently visible")
            return {"success": True, "status": "ok", "id": "lldpd",
                    "data": {"ports": rows, "truncated": truncated},
                    "steps": steps, "warnings": warnings}

        if raw.strip() == "":
            warnings.append("no LLDP neighbours are currently visible")
        return {"success": True, "status": "ok", "id": "lldpd",
                "data": {"neighbours": raw, "truncated": truncated},
                "steps": steps, "warnings": warnings}

    # ------------- IPv6 6in4 tunnel broker (ipv6-tunnel plugin) -------------
    @staticmethod
    def _tunnel_state_path() -> str:
        return os.path.join(TUNNEL_STATE_DIR, "state.json")

    @staticmethod
    def _tunnel_key_path() -> str:
        return os.path.join(TUNNEL_STATE_DIR, "update.key")

    def _tunnel_validate(self, params: Dict[str, Any]):
        """Validate every tunnel.apply parameter before anything is written.

        Returns `(cfg, None)` or `(None, refusal)`. The broker's update key is
        accepted here but never stored in the returned config — it is staged
        separately at 0600 and never echoed (Plugin Spec PL7).
        """
        if self._plugin_entry("ipv6-tunnel") is None:
            return None, {"success": False, "status": "error", "message": "unknown plugin: ipv6-tunnel"}

        enabled = bool(params.get("enabled", True))
        cfg: Dict[str, Any] = {"enabled": enabled}

        # The update key is handled first and separately, so a key in an
        # otherwise-rejected request is never staged.
        key_raw = params.get("update_key")
        update_key: Optional[str] = None
        clear_key = False
        if key_raw is not None and str(key_raw).strip() != "":
            update_key = str(key_raw).strip()
            if HE_UPDATE_KEY_RE.fullmatch(update_key) is None:
                return None, {"success": False, "status": "error",
                              "message": "update_key must be 8-64 letters or digits (the broker's Update Key)"}
        elif key_raw is not None:
            clear_key = True

        if not enabled:
            cfg["update_key"] = update_key
            cfg["clear_key"] = clear_key
            return cfg, None

        endpoint_raw = str(params.get("endpoint_v4") or "").strip()
        local_raw = str(params.get("local_v4") or "").strip()
        client_raw = str(params.get("client_v6") or "").strip()
        server_raw = str(params.get("server_v6") or "").strip()
        routed_raw = str(params.get("routed_prefix") or "").strip()
        broker = str(params.get("broker") or "other").strip()[:40]

        try:
            endpoint = ipaddress.ip_address(endpoint_raw)
        except ValueError:
            return None, {"success": False, "status": "error",
                          "message": "endpoint_v4 must be the broker's IPv4 endpoint address"}
        if endpoint.version != 4:
            return None, {"success": False, "status": "error", "message": "endpoint_v4 must be an IPv4 address"}

        if local_raw.lower() not in ("auto", ""):
            try:
                local = ipaddress.ip_address(local_raw)
            except ValueError:
                return None, {"success": False, "status": "error",
                              "message": "local_v4 must be this appliance's IPv4 address, or 'auto'"}
            if local.version != 4:
                return None, {"success": False, "status": "error", "message": "local_v4 must be an IPv4 address"}
            cfg["local_v4"] = str(local)
        else:
            cfg["local_v4"] = "auto"

        # ip_interface() silently defaults a bare address to /128, which would
        # hide a mistyped broker form; the prefix length is asked for outright.
        if "/" not in client_raw:
            return None, {"success": False, "status": "error",
                          "message": "client_v6 must include its prefix length (e.g. 2001:470:1f08:a2a::2/64)"}
        try:
            client = ipaddress.ip_interface(client_raw)
        except ValueError:
            return None, {"success": False, "status": "error",
                          "message": "client_v6 must be this appliance's tunnel address with prefix (e.g. 2001:470:1f08:a2a::2/64)"}
        if client.version != 6 or client.is_loopback or client.is_link_local:
            return None, {"success": False, "status": "error",
                          "message": "client_v6 must be a global IPv6 address with its prefix length"}

        try:
            server = ipaddress.ip_address(server_raw)
        except ValueError:
            return None, {"success": False, "status": "error",
                          "message": "server_v6 must be the broker's IPv6 peer address"}
        if server.version != 6 or server.is_loopback or server.is_link_local:
            return None, {"success": False, "status": "error",
                          "message": "server_v6 must be a global IPv6 address"}

        routed = None
        if routed_raw:
            try:
                routed = ipaddress.ip_network(routed_raw, strict=True)
            except ValueError:
                return None, {"success": False, "status": "error",
                              "message": "routed_prefix must be the broker's routed IPv6 prefix (e.g. 2001:470:1f0b:a2a::/64)"}
            if routed.version != 6 or routed.prefixlen > 128:
                return None, {"success": False, "status": "error", "message": "routed_prefix must be an IPv6 network"}
            cfg["routed_prefix"] = str(routed)

        try:
            mtu = int(params.get("mtu", 1480))
        except (TypeError, ValueError):
            return None, {"success": False, "status": "error", "message": "mtu must be a number"}
        if mtu < 1280 or mtu > 9000:
            return None, {"success": False, "status": "error", "message": "mtu must be between 1280 and 9000"}

        # The three update fields travel together: an endpoint update with a
        # key but no identity would only produce badauth at the broker.
        user = str(params.get("update_user") or "").strip()
        tunnel_id = str(params.get("update_tunnel") or "").strip()
        if (update_key is not None) != (bool(user) and bool(tunnel_id)):
            return None, {"success": False, "status": "error",
                          "message": "update_key, update_user and update_tunnel must be provided together"}
        if user and HE_UPDATE_USER_RE.fullmatch(user) is None:
            return None, {"success": False, "status": "error",
                          "message": "update_user must be 1-64 letters, digits, dot, dash or underscore"}
        if tunnel_id and HE_UPDATE_TUNNEL_RE.fullmatch(tunnel_id) is None:
            return None, {"success": False, "status": "error",
                          "message": "update_tunnel must be 1-64 letters, digits, dot, dash or underscore"}

        cfg.update({
            "broker": broker,
            "endpoint_v4": str(endpoint),
            "client_v6": str(client),
            "server_v6": str(server),
            "mtu": mtu,
            "update_user": user,
            "update_tunnel": tunnel_id,
            "update_key": update_key,
            "clear_key": clear_key,
        })
        return cfg, None

    @staticmethod
    def _tunnel_unit(cfg: Dict[str, Any], local_v4: str) -> str:
        """The generated oneshot unit: fixed `/usr/sbin/ip` argv, no shell.

        It carries only the tunnel, its addresses and the broker's routed
        prefix. The appliance's *default* route is deliberately untouched —
        that stays the operator's call on the Routing page.
        """
        routes = []
        if cfg.get("routed_prefix"):
            routes.append(
                f"ExecStart={IPROUTE_BIN} -6 route replace {cfg['routed_prefix']} dev {TUNNEL_IFACE}\n"
            )
        return (
            "[Unit]\n"
            "Description=MicronOS IPv6 6in4 tunnel (broker)\n"
            "After=network-online.target\n"
            "Wants=network-online.target\n"
            "\n"
            "[Service]\n"
            "Type=oneshot\n"
            "RemainAfterExit=yes\n"
            f"ExecStartPre=-{IPROUTE_BIN} tunnel del {TUNNEL_IFACE}\n"
            f"ExecStart={IPROUTE_BIN} tunnel add {TUNNEL_IFACE} mode sit remote {cfg['endpoint_v4']} local {local_v4} ttl 64\n"
            f"ExecStart={IPROUTE_BIN} link set {TUNNEL_IFACE} up mtu {cfg['mtu']}\n"
            f"ExecStart={IPROUTE_BIN} addr replace {cfg['client_v6']} dev {TUNNEL_IFACE}\n"
            f"ExecStart={IPROUTE_BIN} -6 route replace {cfg['server_v6']}/128 dev {TUNNEL_IFACE}\n"
            + "".join(routes)
            + f"ExecStop=-{IPROUTE_BIN} tunnel del {TUNNEL_IFACE}\n"
            "\n"
            "[Install]\n"
            "WantedBy=multi-user.target\n"
        )

    async def rpc_plugin_tunnel_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Configure (or tear down) the broker's 6in4 tunnel.

        No package, no repository: a generated unit with fixed argv. The update
        key, when present, is staged as a 0600 file beside the non-secret state
        and never appears in argv, a log line or the response.
        """
        cfg, refusal = self._tunnel_validate(params)
        if refusal is not None:
            return refusal

        steps: list = []
        warnings: list = []

        # Stage or clear the secret before touching the unit, only after the
        # whole request has validated.
        if cfg.get("clear_key"):
            try:
                os.remove(self._tunnel_key_path())
                steps.append("cleared the stored update key")
            except FileNotFoundError:
                pass
            except OSError as exc:
                return {"success": False, "status": "error", "id": "ipv6-tunnel",
                        "message": f"could not clear the update key: {exc}",
                        "steps": steps, "warnings": warnings}
        if cfg.get("update_key"):
            try:
                os.makedirs(TUNNEL_STATE_DIR, mode=0o700, exist_ok=True)
                fd = os.open(self._tunnel_key_path(), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                with os.fdopen(fd, "w", encoding="utf-8") as handle:
                    handle.write(cfg["update_key"] + "\n")
                steps.append("stored the update key (0600, daemon-side only)")
            except OSError as exc:
                return {"success": False, "status": "error", "id": "ipv6-tunnel",
                        "message": f"the update key could not be staged: {exc}",
                        "steps": steps, "warnings": warnings}

        if not cfg.get("enabled"):
            if os.path.exists(TUNNEL_UNIT_FILE):
                res = self._run(["systemctl", "stop", TUNNEL_UNIT])
                steps.append(f"systemctl stop {TUNNEL_UNIT} rc={res.returncode}")
                res = self._run(["systemctl", "disable", TUNNEL_UNIT])
                steps.append(f"systemctl disable {TUNNEL_UNIT} rc={res.returncode}")
                try:
                    os.remove(TUNNEL_UNIT_FILE)
                    steps.append("removed " + TUNNEL_UNIT_FILE)
                except OSError as exc:
                    warnings.append(f"could not remove {TUNNEL_UNIT_FILE}: {exc}")
                res = self._run(["systemctl", "daemon-reload"])
                steps.append(f"systemctl daemon-reload rc={res.returncode}")
            payload = {"success": True, "status": "applied", "id": "ipv6-tunnel",
                       "message": "the 6in4 tunnel is down", "steps": steps, "warnings": warnings}
            self._tunnel_write_state(cfg, payload, local_v4=None)
            return payload

        # Resolve the local endpoint before rendering: 'auto' follows the
        # appliance's own default route, which is exactly what a broker checks.
        local_v4 = cfg["local_v4"]
        if local_v4 == "auto":
            argv = [IPROUTE_BIN, "-4", "route", "get", "1.1.1.1"]
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            match = re.search(r"\bsrc\s+(\d{1,3}(?:\.\d{1,3}){3})\b", res.stdout or "")
            if res.returncode != 0 or match is None:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "ipv6-tunnel",
                        "message": "could not auto-detect this appliance's IPv4 endpoint",
                        "steps": steps,
                        "warnings": warnings + ([detail[:200]] if detail else [])}
            local_v4 = match.group(1)
            try:
                local_v4 = str(ipaddress.ip_address(local_v4))
            except ValueError:
                return {"success": False, "status": "error", "id": "ipv6-tunnel",
                        "message": "auto-detected IPv4 endpoint is not an address", "steps": steps, "warnings": warnings}

        unit = self._tunnel_unit(cfg, local_v4)
        if not self._write_atomic(TUNNEL_UNIT_FILE, unit):
            return {"success": False, "status": "error", "id": "ipv6-tunnel",
                    "message": "could not write " + TUNNEL_UNIT_FILE, "steps": steps, "warnings": warnings}
        steps.append("wrote " + TUNNEL_UNIT_FILE)

        for argv in (["systemctl", "daemon-reload"],
                     ["systemctl", "enable", TUNNEL_UNIT],
                     ["systemctl", "restart", TUNNEL_UNIT]):
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode != 0:
                detail = (res.stderr or res.stdout or "").strip()
                return {"success": False, "status": "error", "id": "ipv6-tunnel",
                        "message": f"{argv[-1]} failed for {TUNNEL_UNIT}", "steps": steps,
                        "warnings": warnings + ([detail[:300]] if detail else [])}

        payload = {"success": True, "status": "applied", "id": "ipv6-tunnel",
                   "message": f"6in4 tunnel is up via {local_v4} -> {cfg['endpoint_v4']}",
                   "steps": steps, "warnings": warnings}
        self._tunnel_write_state(cfg, payload, local_v4=local_v4)
        return payload

    def _tunnel_write_state(self, cfg: Dict[str, Any], payload: Dict[str, Any],
                            local_v4: Optional[str]) -> None:
        """Persist the applied configuration — everything except the key."""
        state = {k: v for k, v in cfg.items() if k not in ("update_key", "clear_key")}
        state["applied_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
        state["last_success"] = bool(payload.get("success"))
        if local_v4 is not None:
            state["resolved_local_v4"] = local_v4
        state["update_key_stored"] = os.path.exists(self._tunnel_key_path())
        self._write_atomic(self._tunnel_state_path(),
                           json.dumps(state, indent=2, sort_keys=True) + "\n")

    def _tunnel_read_state(self) -> Dict[str, Any]:
        try:
            with open(self._tunnel_state_path(), "r", encoding="utf-8") as handle:
                data = strict_json_loads(handle.read())
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError, TypeError):
            return {}

    async def rpc_plugin_tunnel_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: applied endpoints plus live unit state. Never the key."""
        if self._plugin_entry("ipv6-tunnel") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: ipv6-tunnel"}

        steps: list = []
        unit_state = self._service_state(TUNNEL_UNIT)
        steps.append(f"systemctl is-active {TUNNEL_UNIT} -> {unit_state}")

        state = self._tunnel_read_state()
        state["update_key_stored"] = os.path.exists(self._tunnel_key_path())
        state["unit_file_present"] = os.path.exists(TUNNEL_UNIT_FILE)

        warnings: list = []
        if not state.get("unit_file_present"):
            warnings.append("the tunnel unit has not been generated: the tunnel has not been applied")

        return {"success": True, "status": "ok", "id": "ipv6-tunnel",
                "state": unit_state, "data": state, "steps": steps, "warnings": warnings}

    async def rpc_plugin_tunnel_update(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Tell the broker this appliance's current public IPv4 endpoint.

        The documented dyndns2-style API: a key-authenticated GET against a
        *constant* host (a caller never supplies a URL), so the update key is
        the only credential in flight. `myip` is omitted deliberately — the
        broker records the source address of this request, which is the
        endpoint the tunnel actually arrives from.
        """
        if self._plugin_entry("ipv6-tunnel") is None:
            return {"success": False, "status": "error", "message": "unknown plugin: ipv6-tunnel"}

        state = self._tunnel_read_state()
        user = str(state.get("update_user") or "")
        tunnel_id = str(state.get("update_tunnel") or "")
        if not user or not tunnel_id:
            return {"success": False, "status": "error", "id": "ipv6-tunnel",
                    "message": "no broker update identity is configured — set the update key, user and tunnel id on the plugin page first"}
        try:
            with open(self._tunnel_key_path(), "r", encoding="utf-8") as handle:
                key = handle.read().strip()
        except OSError:
            return {"success": False, "status": "error", "id": "ipv6-tunnel",
                    "message": "no update key is stored — set it on the plugin page first"}
        if not key:
            return {"success": False, "status": "error", "id": "ipv6-tunnel",
                    "message": "the stored update key is empty"}

        query = urllib.parse.urlencode({"username": user, "password": key, "hostname": tunnel_id})
        url = HE_UPDATE_URL + "?" + query
        # Steps name the request without the credential: the key never reaches
        # a log line or the response (PL7).
        steps = [f"GET {HE_UPDATE_URL}?username={user}&password=<redacted>&hostname={tunnel_id}"]

        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(url, timeout=10) as response:
                body = response.read(4096).decode("utf-8", "replace").strip()
        except Exception as exc:  # noqa: BLE001 - the broker's errors are data here
            detail = str(exc).replace(key, "<redacted>")[:300]
            return {"success": False, "status": "error", "id": "ipv6-tunnel",
                    "message": "the broker update request failed", "steps": steps,
                    "warnings": [detail]}

        first_line = body.splitlines()[0].strip() if body else ""
        ok = first_line.startswith("good") or first_line.startswith("nochg")
        if ok:
            state["last_update"] = {"at": time.strftime("%Y-%m-%d %H:%M:%S"), "result": first_line}
            self._write_atomic(self._tunnel_state_path(),
                               json.dumps(state, indent=2, sort_keys=True) + "\n")
        else:
            state["last_update"] = {"at": time.strftime("%Y-%m-%d %H:%M:%S"),
                                    "result": first_line or "empty response"}
            self._write_atomic(self._tunnel_state_path(),
                               json.dumps(state, indent=2, sort_keys=True) + "\n")

        return {
            "success": ok,
            "status": "applied" if ok else "error",
            "id": "ipv6-tunnel",
            "message": f"broker answered: {first_line}" if first_line else "broker returned an empty response",
            "steps": steps,
            "warnings": [] if ok else [f"broker response: {first_line[:200]}"],
        }

    # ------------------ DNSBL / Unbound Blocklist (dnsbl.*) ------------------
    async def rpc_dnsbl_update(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Back-compat entry point used by the DNS page's feed/rules actions."""
        return await self._dnsbl_apply(params)

    async def rpc_dnsbl_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Compile the Unbound blocklist from validated feed data and reload."""
        return await self._dnsbl_apply(params)

    async def _dnsbl_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        steps: list = []
        warnings: list = []
        entries: set = set()

        # 1. Explicit inline data (a connector, or the DNS page itself).
        explicit = "domains" in params
        inline = params.get("domains") or []
        if isinstance(inline, str):
            inline = inline.splitlines()
        for raw in inline:
            domain = self._normalise_domain(str(raw))
            if domain:
                entries.add(domain)
            elif str(raw).strip():
                warnings.append("dropped invalid domain: " + str(raw).strip()[:80])

        # 2. Staged artifacts written by the control plane.
        paths, path_error = self._dnsbl_artifacts(params.get("artifact_path"))
        if path_error:
            return {"success": False, "status": "error", "message": path_error,
                    "steps": steps, "warnings": warnings}

        staged = 0
        for path in paths:
            for line in self._read_domain_lines(path):
                domain = self._normalise_domain(line)
                if domain:
                    entries.add(domain)
                    staged += 1

        # 3. Overrides from the DNS rules table (whitelist always wins).
        overrides = params.get("overrides") or {}
        whitelist = {self._normalise_domain(str(d)) for d in (overrides.get("whitelist") or [])}
        whitelist.discard(None)
        for raw in (overrides.get("blacklist") or []):
            domain = self._normalise_domain(str(raw))
            if domain:
                entries.add(domain)

        if whitelist:
            entries -= whitelist

        if len(entries) > DNSBL_MAX_ENTRIES:
            warnings.append(f"entry cap reached; truncated to {DNSBL_MAX_ENTRIES}")
            entries = set(sorted(entries)[:DNSBL_MAX_ENTRIES])

        # Never replace live data with nothing: an empty result that was not
        # explicitly requested is a no-op, so a missing/empty staging directory
        # cannot silently wipe an existing blocklist.
        if not entries and not explicit:
            return {"success": True, "status": "noop", "entries": 0, "reloaded": False,
                    "file": DNSBL_BLOCKLIST_FILE, "steps": steps,
                    "warnings": warnings + ["no feed data available; blocklist left unchanged"]}

        content = self._compile_dnsbl_blocklist(sorted(entries))

        previous = None
        if os.path.isfile(DNSBL_BLOCKLIST_FILE):
            try:
                with open(DNSBL_BLOCKLIST_FILE, "r", encoding="utf-8", errors="replace") as handle:
                    previous = handle.read()
            except OSError:
                previous = None

        if not self._write_atomic(DNSBL_BLOCKLIST_FILE, content):
            return {"success": False, "status": "error",
                    "message": "could not write " + DNSBL_BLOCKLIST_FILE,
                    "steps": steps, "warnings": warnings}
        steps.append("wrote " + DNSBL_BLOCKLIST_FILE)

        check = self._run(["unbound-checkconf"])
        steps.append(f"unbound-checkconf rc={check.returncode}")
        if check.returncode != 0:
            # Restore the last good blocklist so a bad feed cannot break DNS.
            if previous is not None:
                self._write_atomic(DNSBL_BLOCKLIST_FILE, previous)
                steps.append("restored previous blocklist after validation failure")
            detail = (check.stderr or check.stdout or "").strip()
            return {"success": False, "status": "error",
                    "message": "unbound-checkconf rejected the blocklist",
                    "entries": len(entries), "reloaded": False,
                    "file": DNSBL_BLOCKLIST_FILE, "steps": steps,
                    "warnings": warnings + ([detail[:300]] if detail else [])}

        reloaded = False
        for argv in (["unbound-control", "reload"], ["systemctl", "reload", "unbound"]):
            res = self._run(argv)
            steps.append(f"{' '.join(argv)} rc={res.returncode}")
            if res.returncode == 0:
                reloaded = True
                break

        if not reloaded:
            warnings.append("blocklist written but Unbound reload failed")

        return {
            "success": reloaded,
            "status": "applied" if reloaded else "error",
            "entries": len(entries),
            "staged": staged,
            "reloaded": reloaded,
            "file": DNSBL_BLOCKLIST_FILE,
            "steps": steps,
            "warnings": warnings,
        }

    # -- DNSBL helpers ---------------------------------------------------------
    @staticmethod
    def _normalise_domain(line: str) -> Optional[str]:
        """
        Extracts a valid hostname from a feed line.

        Handles plain domain lists, `hosts`-format lines (`0.0.0.0 domain`),
        inline comments and the leading-dot variants that public feeds use.
        """
        text = (line or "").strip()
        if not text or text.startswith("#"):
            return None
        text = text.split("#", 1)[0].strip()
        if not text:
            return None
        token = text.split()[-1].lower().lstrip(".").rstrip(".")
        if not token or len(token) > 253:
            return None
        return token if DOMAIN_RE.match(token) else None

    def _dnsbl_artifacts(self, artifact_path: Any) -> tuple:
        """
        Resolves the artifacts to compile.

        A caller-supplied path is accepted only when it resolves inside
        DNSBL_STAGE_DIR, so the blocklist can never be built from an arbitrary
        file on the host. Without a path, every valid file in the staging
        directory is used.
        """
        base = os.path.realpath(DNSBL_STAGE_DIR)

        if artifact_path:
            candidate = os.path.realpath(str(artifact_path))
            if candidate != base and not candidate.startswith(base + os.sep):
                return [], "refusing artifact outside " + DNSBL_STAGE_DIR
            if not os.path.isfile(candidate):
                return [], "artifact not found"
            return [candidate], None

        if not os.path.isdir(base):
            return [], None

        found = []
        for name in sorted(os.listdir(base)):
            if not DNSBL_ARTIFACT_RE.match(name):
                continue
            full = os.path.join(base, name)
            if os.path.isfile(full):
                found.append(full)
        return found, None

    def _read_domain_lines(self, path: str) -> list:
        """Reads a staged artifact (size-capped) and returns its lines."""
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                payload = handle.read(DNSBL_MAX_ARTIFACT_BYTES)
        except OSError as exc:
            logger.warning("dnsbl: could not read %s: %s", path, exc)
            return []
        return payload.splitlines()

    @staticmethod
    def _compile_dnsbl_blocklist(domains: list) -> str:
        """Renders the Unbound include file for a domain set (NXDOMAIN sinkhole)."""
        lines = [
            "# MicronOS DNSBL blocklist - generated by micrond. Do not edit.",
            f"# entries: {len(domains)}",
        ]
        for domain in domains:
            lines.append('local-zone: "{}" always_nxdomain'.format(domain))
        return "\n".join(lines) + "\n"

    @staticmethod
    def _write_atomic(path: str, content: str, mode: int = 0o644) -> bool:
        """Writes a file via temp file + fsync + atomic rename, so readers never see a partial/corrupted write."""
        try:
            safe_atomic_write(path, content, mode=mode)
            return True
        except OSError as exc:
            logger.warning("atomic write failed for %s: %s", path, exc)
            return False

    # --------------- Appliance Stub Resolver (dns.resolver.*) ---------------
    async def rpc_dns_resolver_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Applies how the firewall itself resolves DNS, as /etc/resolv.conf.

        The control plane compiles the file (DNS Server Override + DNS
        Resolution Behavior + the configured remote servers) and hands the
        content over; the daemon re-validates it rather than trusting the
        caller. Only blank lines, comments, and `nameserver <address>` are
        accepted, the result must name at least one server, and a local-first
        policy must actually name 127.0.0.1 so the file cannot contradict the
        policy the DNS page reports.

        No service restart is required: resolv.conf is read per lookup. The
        resolver cache is flushed so an answer obtained through the previous
        upstreams is not reused after the change.
        """
        steps: list = []
        warnings: list = []

        config = params.get("config")
        if not isinstance(config, str) or not config.strip():
            return {"success": False, "status": "error",
                    "message": "config must be a non-empty string",
                    "steps": steps, "warnings": warnings}

        if len(config.encode("utf-8")) > RESOLV_MAX_BYTES:
            return {"success": False, "status": "error",
                    "message": f"config exceeds {RESOLV_MAX_BYTES} bytes",
                    "steps": steps, "warnings": warnings}

        behavior = str(params.get("behavior") or "").strip().lower()
        if behavior and behavior not in RESOLV_BEHAVIORS:
            return {"success": False, "status": "error",
                    "message": f"unknown DNS resolution behavior: {behavior[:40]}",
                    "steps": steps, "warnings": warnings}

        override = bool(params.get("override"))

        nameservers: list = []
        for raw in config.splitlines():
            line = raw.strip()
            if not line or line.startswith("#"):
                continue

            fields = line.split()
            if len(fields) != 2 or fields[0] != "nameserver":
                return {"success": False, "status": "error",
                        "message": "refusing unrecognised resolv.conf directive: " + line[:60],
                        "steps": steps, "warnings": warnings}

            try:
                address = str(ipaddress.ip_address(fields[1]))
            except ValueError:
                return {"success": False, "status": "error",
                        "message": "refusing invalid nameserver address: " + fields[1][:60],
                        "steps": steps, "warnings": warnings}

            if address not in nameservers:
                nameservers.append(address)

        # An empty resolv.conf breaks every name lookup on the appliance, so it
        # is refused outright rather than applied and reported.
        if not nameservers:
            return {"success": False, "status": "error",
                    "message": "refusing to apply a resolver config with no nameserver",
                    "steps": steps, "warnings": warnings}

        if len(nameservers) > RESOLV_MAX_NAMESERVERS:
            return {"success": False, "status": "error",
                    "message": f"too many nameservers ({len(nameservers)} > {RESOLV_MAX_NAMESERVERS})",
                    "steps": steps, "warnings": warnings}

        if behavior.startswith("local-") and "127.0.0.1" not in nameservers:
            return {"success": False, "status": "error",
                    "message": f"{behavior} requires nameserver 127.0.0.1",
                    "steps": steps, "warnings": warnings}

        if not self._write_atomic(RESOLV_CONF, config if config.endswith("\n") else config + "\n"):
            return {"success": False, "status": "error",
                    "message": "could not write " + RESOLV_CONF,
                    "steps": steps, "warnings": warnings}
        steps.append("wrote " + RESOLV_CONF)

        res = self._run(["resolvectl", "flush-caches"])
        steps.append(f"resolvectl flush-caches rc={res.returncode}")
        if res.returncode != 0:
            warnings.append("resolver caches were not flushed; stale answers may persist until their TTL expires")

        return {
            "success": True,
            "status": "applied",
            "file": RESOLV_CONF,
            "behavior": behavior or None,
            "override": override,
            "nameservers": nameservers,
            "steps": steps,
            "warnings": warnings,
        }

    # ------------------ IDS/IPS (Suricata) Methods ------------------
    async def rpc_ids_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = subprocess.run(["suricatasc", "-c", "reload-rules"], capture_output=True, text=True)
        return {"status": "reloaded" if res.returncode == 0 else "error", "output": res.stdout + res.stderr}

    # ------------------ Routing Methods ------------------
    async def rpc_route_add(self, params: Dict[str, Any]) -> Dict[str, Any]:
        dest = params.get("destination", "")
        gw = params.get("gateway", "")
        iface = params.get("iface", "")
        cmd = ["ip", "route", "replace", dest]
        if gw:
            cmd.extend(["via", gw])
        if iface:
            cmd.extend(["dev", iface])
        res = subprocess.run(cmd, capture_output=True, text=True)
        return {"status": "added" if res.returncode == 0 else "error", "error": res.stderr}

    async def rpc_network_route_add(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_route_add(params)

    async def rpc_route_del(self, params: Dict[str, Any]) -> Dict[str, Any]:
        dest = params.get("destination", "")
        res = subprocess.run(["ip", "route", "del", dest], capture_output=True, text=True)
        return {"status": "deleted" if res.returncode == 0 else "error", "error": res.stderr}

    async def rpc_network_route_del(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_route_del(params)


    async def rpc_firewall_apply_rules(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_firewall_apply(params)

    async def rpc_nat_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """NAT lives in the same nftables ruleset, so this is a ruleset load."""
        return await self.rpc_firewall_apply(params)

    # ------------------ Policy-Based Routing Methods ------------------
    async def rpc_routing_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Applies the Policy-Based Routing configuration.

        Two halves, applied in order:
          1. the nftables ruleset, which sets the packet marks
          2. the routing policy (ip rule / ip route) that acts on those marks

        The routing policy is rebuilt from scratch each time so a removed rule
        does not leave a stale `ip rule` behind. Every argument is passed as an
        argv element — never through a shell — and validated first.
        """
        ruleset = params.get("ruleset", "")
        if ruleset:
            nft_result = await self.rpc_firewall_apply({**params, "ruleset": ruleset})
            if nft_result.get("status") in ("syntax_error", "apply_failed"):
                return nft_result

        rules = params.get("rules") or []
        routes = params.get("routes") or []

        if not isinstance(rules, list) or not isinstance(routes, list):
            return {"status": "error", "message": "rules and routes must be lists"}

        # 1. Remove stale MicronOS policy rules so a deleted PBR rule does not
        #    keep steering traffic. Only rules whose fwmark falls in the derived
        #    band (10000 + table, tables 1..252) are touched, so system rules at
        #    priorities 0 / 32766 / 32767 are never disturbed.
        cleared = 0
        for line in self._show_ip_rules():
            match = re.search(r"fwmark\s+(0x[0-9a-fA-F]+)\s+lookup\s+(\d+)", line)
            if not match:
                continue
            mark = int(match.group(1), 16)
            table = int(match.group(2))
            if 10001 <= mark <= 10252 and 1 <= table <= 252:
                subprocess.run(["ip", "rule", "del", line.strip().split(":", 1)[0]], capture_output=True, text=True)
                cleared += 1

        # 2. Install the current policy rules. Deleting the exact selector first
        #    makes the operation idempotent without touching unrelated rules.
        installed = 0
        for rule in rules:
            mark = int(rule.get("mark", 0))
            table = int(rule.get("table", 0))
            priority = int(rule.get("priority", 100))

            if mark <= 0 or table <= 0 or priority < 1 or priority > 32766:
                logger.warning(f"Skipping invalid PBR rule: {rule}")
                continue

            selector = ["fwmark", str(mark), "lookup", str(table), "priority", str(priority)]
            subprocess.run(["ip", "rule", "del"] + selector, capture_output=True, text=True)

            res = subprocess.run(["ip", "rule", "add"] + selector, capture_output=True, text=True)
            if res.returncode != 0:
                logger.error(f"ip rule add failed for {rule}: {res.stderr}")
                continue
            installed += 1

        # 3. Install one default route per routing table.
        route_count = 0
        for route in routes:
            table = int(route.get("table", 0))
            nexthop = str(route.get("nexthop", ""))
            if table <= 0 or not self._is_ip(nexthop):
                logger.warning(f"Skipping invalid PBR route: {route}")
                continue
            res = subprocess.run(
                ["ip", "route", "replace", "default", "via", nexthop, "table", str(table)],
                capture_output=True, text=True,
            )
            if res.returncode != 0:
                logger.error(f"ip route replace failed for {route}: {res.stderr}")
                continue
            route_count += 1

        # Flush the route cache so the new policy takes effect immediately.
        subprocess.run(["ip", "route", "flush", "cache"], capture_output=True, text=True)

        return {
            "status": "applied",
            "rules_installed": installed,
            "rules_cleared": cleared,
            "routes": route_count,
            "message": f"Policy routing applied: {installed} rule(s), {route_count} route(s).",
        }

    async def rpc_network_pbr_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Re-applies the current policy routing configuration."""
        return await self.rpc_routing_apply(params)

    # ------------------ PPPoE Client & Server Methods ------------------
    @staticmethod
    def _run(argv: list) -> "subprocess.CompletedProcess":
        """
        Runs a command as an argv list, never through a shell.

        A missing binary is reported like any other failure rather than raised:
        an appliance may not have every PPP helper installed, and that must not
        abort an apply that has already written valid configuration.
        """
        try:
            return subprocess.run(argv, capture_output=True, text=True)
        except OSError as exc:
            logger.warning(f"Command not runnable {argv!r}: {exc}")
            return subprocess.CompletedProcess(argv, 127, "", str(exc))

    @staticmethod
    def _write_config(path: str, content: str) -> Optional[str]:
        """
        Writes a generated config file atomically with 0600 mode and fsync barriers.

        Returns an error string on failure, or None on success. pppd refuses to
        read a secrets file that is world-readable, so the mode is set explicitly
        rather than left to the umask.
        """
        try:
            safe_atomic_write(path, content, mode=0o600)
            return None
        except OSError as exc:
            return str(exc)

    async def rpc_pppoe_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Applies the PPPoE configuration for both directions.

        Client: one peer file per interface under /etc/ppp/peers, plus the
        credentials section of /etc/ppp/chap-secrets. Server: the pppoe-server
        options file, the subscriber section of chap-secrets, and the argv for
        each access concentrator.

        The two halves share chap-secrets, so the file is written once from the
        concatenation the management plane supplies. Nothing here goes through a
        shell: every command is an argv list.

        Each link is brought down before its peer file is replaced and brought
        back up afterwards, so pppd re-reads the new configuration instead of
        keeping the old one in memory.
        """
        client_peers = params.get("client_peers") or []
        server_options = params.get("server_options") or []
        server_argv = params.get("server_argv") or []
        server_secrets = params.get("server_secrets") or []
        secrets_file = params.get("secrets_file") or "/etc/ppp/chap-secrets"

        if not isinstance(client_peers, list) or not isinstance(server_options, list):
            return {"status": "error", "message": "client_peers and server_options must be lists"}

        errors = []
        written = 0

        # 1. Peer files. Take the link down first so pppd does not hold the old
        #    file while it is replaced.
        for peer in client_peers:
            if not isinstance(peer, dict):
                continue
            path = str(peer.get("file", ""))
            if not path.startswith("/etc/ppp/peers/"):
                errors.append(f"Refusing to write peer file outside /etc/ppp/peers: {path}")
                continue

            iface = str(peer.get("interface", ""))
            if iface:
                self._run(["poff", iface])

            error = self._write_config(path, str(peer.get("content", "")))
            if error:
                errors.append(f"{path}: {error}")
                continue
            written += 1

            if iface:
                self._run(["pon", iface])

        # 2. chap-secrets — client lines then subscriber lines, one file.
        secrets_parts = [str(params.get("client_secrets", "") or "")]
        secrets_parts.extend(str(part) for part in server_secrets if part)
        secrets_body = "\n".join(part for part in secrets_parts if part.strip())
        if secrets_body:
            error = self._write_config(secrets_file, secrets_body + "\n")
            if error:
                errors.append(f"{secrets_file}: {error}")
            else:
                written += 1

        # 3. Server options + one access concentrator per server.
        for options in server_options:
            if not isinstance(options, dict):
                continue
            path = str(options.get("file", ""))
            if not path.startswith("/etc/ppp/"):
                errors.append(f"Refusing to write server options outside /etc/ppp: {path}")
                continue

            error = self._write_config(path, str(options.get("content", "")))
            if error:
                errors.append(f"{path}: {error}")
            else:
                written += 1

        # 4. RADIUS client configuration, when any server authenticates that way.
        #    The plugin reads a fixed directory, so the daemon writes to whichever
        #    of the known spellings exists on this appliance.
        radius_dirs = params.get("radius_dirs") or []
        radius_servers = params.get("radius_servers") or []
        radius_conf = str(params.get("radius_conf", "") or "")

        if radius_servers:
            radius_dir = next((d for d in radius_dirs if os.path.isdir(d)), None)
            if radius_dir is None:
                radius_dir = radius_dirs[0] if radius_dirs else "/etc/radiusclient"

            if radius_conf:
                error = self._write_config(os.path.join(radius_dir, "radiusclient.conf"), radius_conf)
                if error:
                    errors.append(f"{radius_dir}/radiusclient.conf: {error}")
                else:
                    written += 1

            for entry in radius_servers:
                if not isinstance(entry, dict):
                    continue
                path = os.path.join(radius_dir, "servers")
                error = self._write_config(path, str(entry.get("content", "")))
                if error:
                    errors.append(f"{path}: {error}")
                else:
                    written += 1

        started = 0
        for argv in server_argv:
            if not isinstance(argv, list) or not argv or argv[0] != "pppoe-server":
                errors.append(f"Refusing to start unexpected command: {argv}")
                continue
            res = self._run(argv)
            if res.returncode != 0:
                errors.append(f"pppoe-server failed: {res.stderr.strip()}")
                continue
            started += 1

        return {
            "status": "applied" if not errors else "apply_failed",
            "files_written": written,
            "servers_started": started,
            "client_links": len(client_peers),
            "errors": errors,
            "message": (
                f"PPPoE applied: {written} file(s), {started} server(s) started."
                if not errors
                else "; ".join(errors)
            ),
        }

    async def rpc_pppoe_redial(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Repairs PPPoE links that are down, leaving healthy ones alone.

        A stale session needs a full teardown: `poff` terminates pppd and its
        session, `pon` starts a fresh one from the peer file. Nudging a
        half-open link does not work, because pppd believes it is still up.

        Only a link that is not up is torn down. Cycling a healthy link costs a
        fresh handshake and a few seconds of outage, so a recurring task that
        did it every twenty minutes would create the very interruption it exists
        to prevent. The access concentrator is restarted only when a link was
        actually repaired, and the settle time is only spent on a link that is
        being rebuilt.

        A short settle time between the two, plus a bounded wait for pppd to
        appear, keeps the result meaningful — a link that has not come back is
        reported as a failure rather than a success.

        `force` bypasses the health check, for an operator who wants the old
        unconditional redial.
        """
        interfaces = params.get("interfaces") or []
        down_only = bool(params.get("down_only"))
        force = bool(params.get("force"))

        if not isinstance(interfaces, list):
            return {"status": "error", "message": "interfaces must be a list"}

        requested = [str(iface) for iface in interfaces if str(iface)]

        # Inspect before touching anything, so a scheduled run over healthy
        # links is a genuine no-op rather than a periodic outage.
        inspected = {iface: self._pppoe_link_state(iface) for iface in requested}

        if down_only or force:
            targets = list(requested)
        else:
            targets = [iface for iface in requested if inspected[iface]["state"] != "up"]

        skipped = [iface for iface in requested if iface not in targets]

        # Restart the access concentrator only when something is actually being
        # rebuilt; a run that repaired nothing should not interrupt the server.
        concentrator = None
        if targets and not down_only:
            res = self._run(["systemctl", "restart", "pppoe-server"])
            concentrator = "restarted" if res.returncode == 0 else "not running"

        results = []
        up = 0
        failed = 0
        repaired = 0

        for iface in requested:
            state = inspected[iface]["state"]

            if iface in skipped:
                up += 1
                results.append({
                    "interface": iface,
                    "status": "up",
                    "state": "up",
                    "action": "skipped",
                    "message": "link is healthy; left alone",
                })
                continue

            down = self._run(["poff", iface])
            if down.returncode != 0 and down.stderr.strip():
                results.append({"interface": iface, "status": "down", "state": state, "action": "down", "message": down.stderr.strip()})

            if down_only:
                results.append({"interface": iface, "status": "down", "state": state, "action": "down"})
                continue

            # Give the session a moment to release before redialling.
            await asyncio.sleep(2)

            start = self._run(["pon", iface])
            if start.returncode != 0:
                failed += 1
                results.append({"interface": iface, "status": "error", "state": state, "action": "redialed", "message": start.stderr.strip() or "pon failed"})
                continue

            # Wait briefly for the interface to come back; a link that never
            # reappears is a failure, not a success.
            link_up = False
            for _ in range(10):
                if self._ppp_link_is_up(iface):
                    link_up = True
                    break
                await asyncio.sleep(1)

            if link_up:
                up += 1
                repaired += 1
                results.append({"interface": iface, "status": "up", "state": state, "action": "redialed"})
            else:
                failed += 1
                results.append({"interface": iface, "status": "error", "state": state, "action": "redialed", "message": "link did not come back up"})

        skipped_count = len(skipped)
        message = f"Redial complete: {repaired} repaired, {skipped_count} healthy, {failed} failed."
        if not targets:
            message = f"All {skipped_count} link(s) healthy; nothing to redial."

        return {
            "status": "error" if failed and not down_only else "applied",
            "links": results,
            "up": up,
            "failed": failed,
            "repaired": repaired,
            "skipped": skipped_count,
            "concentrator": concentrator,
            "message": message,
        }

    @staticmethod
    def _read_text(path: str) -> str:
        """File contents, or an empty string when it cannot be read."""
        try:
            with open(path, encoding="utf-8", errors="replace") as handle:
                return handle.read()
        except OSError:
            return ""

    @staticmethod
    def _ppp_interfaces() -> list:
        """Every pppN device the kernel currently knows about."""
        names = []
        for entry in os.listdir("/sys/class/net") if os.path.isdir("/sys/class/net") else []:
            if entry.startswith("ppp") and entry[3:].isdigit():
                names.append(entry)
        return sorted(names, key=lambda n: int(n[3:]))

    @staticmethod
    def _unit_from_argv(argv: list) -> Optional[int]:
        """The ppp unit a pppd was told to use, from `unit N` or a pppN token."""
        for i, token in enumerate(argv):
            if token == "unit" and i + 1 < len(argv) and argv[i + 1].isdigit():
                return int(argv[i + 1])
        for token in argv:
            if token.startswith("ppp") and token[3:].isdigit():
                return int(token[3:])
        return None

    @classmethod
    def _interface_for_pid(cls, pid: int, argv: list, available: list) -> str:
        """
        The pppN device a pppd owns.

        Resolved in order of reliability: the unit pppd was told to use, then a
        pid file, then the only interface left when exactly one is unaccounted
        for. A guess is better than a blank row in a monitor, but it is not
        fabricated: an interface is only attributed when a source supports it.
        """
        unit = cls._unit_from_argv(argv)
        if unit is not None and f"ppp{unit}" in available:
            return f"ppp{unit}"

        for name in available:
            pidfile = f"/var/run/{name}.pid"
            if cls._read_text(pidfile).strip() == str(pid):
                return name

        return ""

    @staticmethod
    def _pppoe_device_of(argv: list) -> str:
        """
        The NIC a client pppd dials, taken from its `nic-<dev>` argument.

        The concentrator's own sessions carry no `nic-`, so an empty result is
        how a server-side session is told apart from a client one.
        """
        device = ""
        for i, token in enumerate(argv):
            if token == "nic-" and i + 1 < len(argv):
                device = argv[i + 1]
            elif token.startswith("nic-") and len(token) > 4:
                device = token[4:]
        return device

    @classmethod
    def _ppp_link_is_up(cls, iface: str) -> bool:
        """
        Whether the kernel reports a pppN device as operationally UP.

        The operational state is read from the flag list (`<...,UP,...>`), not
        from the `state` word: a point-to-point interface reports
        `state UNKNOWN` even while it is carrying traffic, so keying on
        `state UP` would call every healthy PPP link down and redial it on each
        run.
        """
        if not iface:
            return False
        link = cls._run(["ip", "-o", "link", "show", "dev", iface])
        if link.returncode != 0:
            return False
        return bool(re.search(r"<[^>]*\bUP\b[^>]*>", link.stdout))

    # Where pppd's radattr plugin records the reply attributes for a session.
    RADATTR_DIR = "/var/run"

    @classmethod
    def _radius_attributes(cls, iface: str) -> Dict[str, str]:
        """
        The RADIUS reply attributes the server returned for a session.

        pppd applies attributes like Session-Timeout, Framed-IP-Address and
        Filter-Id to a session, but never reports them: once the login is done
        the only record is what the `radattr` plugin wrote to
        /var/run/radattr.<iface>, one `Attribute-Name Value` per line. Reading
        that file is what makes an account's per-session limits visible on the
        appliance instead of only inside the RADIUS server.

        The file only exists for a session authenticated against RADIUS, so its
        absence is normal (a local secret or a client link) and yields no
        attributes rather than an error.
        """
        if not iface:
            return {}

        text = cls._read_text(f"{cls.RADATTR_DIR}/radattr.{iface}")
        if not text.strip():
            return {}

        attributes: Dict[str, str] = {}
        for line in text.splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            # "Attribute-Name Attribute-Value"; the value may itself contain
            # spaces, so only the first run of whitespace separates the two.
            parts = line.split(None, 1)
            attributes[parts[0]] = parts[1].strip() if len(parts) > 1 else ""

        return attributes

    @classmethod
    def _pppoe_link_state(cls, device: str) -> Dict[str, str]:
        """
        Whether a PPPoE client link on `device` is actually usable.

        This exists so an automatic redial can leave healthy links alone: a
        teardown costs a fresh handshake and a few seconds of outage, so it is
        only justified when the link is not already carrying traffic.

        A link counts as `up` only when a pppd dialling this device owns a pppN
        that the kernel reports as UP. Anything less — no process, a process
        with no resolvable unit, a unit that is down — is `down`, and nothing at
        all is `absent`. An undeterminable state is `error`, which is treated as
        needing repair because a link that cannot be shown to be up should not be
        trusted.
        """
        device = (device or "").strip()
        if not device:
            return {"state": "error", "message": "no interface given"}

        available = cls._ppp_interfaces()
        pids = []
        for entry in os.listdir("/proc") if os.path.isdir("/proc") else []:
            if not entry.isdigit():
                continue
            if cls._read_text(f"/proc/{entry}/comm").strip() != "pppd":
                continue
            argv = [
                token for token in
                cls._read_text(f"/proc/{entry}/cmdline").split("\x00")
                if token
            ]
            if cls._pppoe_device_of(argv) == device:
                pids.append(int(entry))

        if not pids:
            return {"state": "absent", "message": "no pppd is dialling this link"}

        for pid in pids:
            argv = [
                token for token in
                cls._read_text(f"/proc/{pid}/cmdline").split("\x00")
                if token
            ]
            iface = cls._interface_for_pid(pid, argv, available)
            if cls._ppp_link_is_up(iface):
                return {"state": "up", "interface": iface, "pid": str(pid)}

        return {"state": "down", "message": "pppd is running but the link is not up"}

    async def rpc_pppoe_radius_test(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Sends a single RADIUS Access-Request and reports the verdict.

        A shared secret or an unreachable server is otherwise only discovered
        when a real subscriber is refused, so this exists to prove the setup.

        The secret goes to radclient on stdin, never in argv: argv is visible to
        any user through /proc, and a secret that leaks there is worse than no
        test at all. PAP is used because radclient can generate it from a
        plaintext password; the subscriber path itself stays CHAP.
        """
        host = str(params.get("server", "")).strip()
        secret = str(params.get("secret", ""))
        username = str(params.get("username", ""))
        password = str(params.get("password", ""))

        if not host or not secret:
            return {"status": "error", "message": "A RADIUS server and shared secret are required."}
        if not username:
            return {"status": "error", "message": "A test username is required."}

        port = int(params.get("port", 1812) or 1812)
        timeout = int(params.get("timeout", 5) or 5)
        retries = int(params.get("retries", 3) or 3)
        nas_id = str(params.get("nas_id", "") or "MicronOS")

        if not (1 <= port <= 65535):
            return {"status": "error", "message": "Invalid RADIUS port."}

        request = (
            f'User-Name = "{username}"\n'
            f'User-Password = "{password}"\n'
            f'NAS-Identifier = "{nas_id}"\n'
            'Service-Type = Framed-User\n'
            'Framed-Protocol = PPP\n'
        )

        # radclient takes the shared secret as its third positional argument,
        # which would expose it to every user through /proc. `-S <file>` reads it
        # from a file instead, so the secret never appears in argv.
        secret_path = ""
        try:
            with tempfile.NamedTemporaryFile("w", prefix="micron-radtest-", suffix=".secret", delete=False) as handle:
                handle.write(secret + "\n")
                secret_path = handle.name
            os.chmod(secret_path, 0o600)
        except OSError as exc:
            return {"status": "error", "message": f"Could not stage the shared secret: {exc}"}

        argv = [
            "radclient",
            "-t", str(timeout),
            "-r", str(retries),
            "-S", secret_path,
            "-D", "/usr/share/freeradius",
            f"{host}:{port}",
            "auth",
        ]

        try:
            res = subprocess.run(
                argv, input=request, capture_output=True, text=True,
                timeout=timeout * (retries + 1) + 5,
            )
        except FileNotFoundError:
            return {"status": "error", "message": "radclient is not installed on the appliance."}
        except subprocess.TimeoutExpired:
            return {"status": "error", "message": f"No response from {host}:{port} within the timeout."}
        finally:
            if secret_path:
                try:
                    os.unlink(secret_path)
                except OSError:
                    logger.warning(f"Could not remove staged secret {secret_path}")

        output = (res.stdout + res.stderr).strip()

        # The verdict is taken from the line radclient prints for the reply it
        # actually received and verified — never from a substring of the whole
        # output. radclient's failure line reads "Expected Access-Accept got
        # Access-Reject", which contains "Access-Accept" and would invert the
        # answer for a rejected login if the whole output were searched.
        lines = [line for line in output.splitlines() if line.strip()]
        received = next((line.strip() for line in lines if line.strip().startswith("Received")), "")

        # A reply that fails verification is not an answer: the secret is wrong,
        # so nothing about the credentials has been established.
        if "verification failed" in output or "invalid Response Authenticator" in output:
            status = "error"
        elif "Access-Accept" in received:
            status = "accept"
        elif "Access-Reject" in received:
            status = "reject"
        else:
            status = "error"

        if received:
            message = received
        elif status == "error":
            message = f"No response from {host}:{port}."
        else:
            message = lines[-1] if lines else "no output from radclient"

        return {
            "status": status,
            "exit_code": res.returncode,
            "message": message,
            "output": output,
        }

    @staticmethod
    def _conf_values(text: str, key: str) -> list:
        """The comma-separated values of a radiusclient.conf directive."""
        values = []
        for line in text.splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            parts = line.split(None, 1)
            if len(parts) == 2 and parts[0].strip().lower() == key:
                values.extend(part.strip() for part in parts[1].split(",") if part.strip())
        return values

    async def rpc_pppoe_radius_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        The RADIUS client configuration actually on disk.

        This distinguishes "configured" from "applied". A toggle flipped in the
        UI changes nothing until an apply writes the file, and accounting that is
        enabled in the database but not yet applied would otherwise look exactly
        like accounting that is running.

        Only the server lists are reported. The shared secret lives in the
        `servers` file and is never read back out to the management plane.
        """
        dirs = params.get("radius_dirs") or [
            "/etc/radiusclient",
            "/etc/radiusclient-ng",
            "/etc/freeradius-client",
        ]

        for directory in dirs:
            path = os.path.join(str(directory), "radiusclient.conf")
            text = self._read_text(path)
            if not text.strip():
                continue

            auth = self._conf_values(text, "authserver")
            acct = self._conf_values(text, "acctserver")
            return {
                "status": "ok",
                "applied": True,
                "path": path,
                "authserver": auth,
                "acctserver": acct,
                "auth": len(auth) > 0,
                "accounting": len(acct) > 0,
            }

        return {
            "status": "ok",
            "applied": False,
            "path": "",
            "authserver": [],
            "acctserver": [],
            "auth": False,
            "accounting": False,
        }

    async def rpc_pppoe_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Live PPPoE state: every session, its addresses, uptime and counters.

        A session is one pppd process. The client and server directions are told
        apart by how pppd was invoked: a client dials a concentrator through the
        rp-pppoe plugin on a named NIC, while the appliance's own concentrator
        spawns pppd against its options file.
        """
        available = self._ppp_interfaces()
        sessions = []

        # First pass: gather every pppd and resolve what can be resolved.
        raw = []
        for entry in os.listdir("/proc") if os.path.isdir("/proc") else []:
            if not entry.isdigit():
                continue

            if self._read_text(f"/proc/{entry}/comm").strip() != "pppd":
                continue

            argv = [
                token for token in
                self._read_text(f"/proc/{entry}/cmdline").split("\x00")
                if token
            ]

            # A client names its NIC with `nic-<dev>`; the concentrator's own
            # sessions are started from the pppoe-server options file.
            device = self._pppoe_device_of(argv)

            raw.append({
                "pid": int(entry),
                "argv": argv,
                "device": device,
                "role": "client" if (device or "rp-pppoe.so" in argv) else "server",
                "interface": self._interface_for_pid(int(entry), argv, available),
            })

        # A pppd that reported no unit and left no pid file can still be paired
        # when exactly one interface and exactly one session are unaccounted
        # for. Only the unambiguous case is resolved; anything else stays blank
        # rather than being guessed.
        claimed = {s["interface"] for s in raw if s["interface"]}
        unclaimed = [name for name in available if name not in claimed]
        unattributed = [s for s in raw if not s["interface"]]
        if len(unclaimed) == 1 and len(unattributed) == 1:
            unattributed[0]["interface"] = unclaimed[0]

        for session in raw:
            entry  = session["pid"]
            iface  = session["interface"]

            local_ip = peer_ip = ""
            if iface:
                addr = self._run(["ip", "-o", "-4", "addr", "show", "dev", iface])
                # `N: ppp0  inet 10.0.0.2 peer 10.0.0.1/32 scope global ppp0`
                match = re.search(
                    r"inet (\d+\.\d+\.\d+\.\d+)(?:\s+peer\s+(\d+\.\d+\.\d+\.\d+))?",
                    addr.stdout,
                )
                if match:
                    local_ip = match.group(1)
                    # A point-to-point link reports the far end after `peer`; when
                    # it is absent the session has not finished negotiating.
                    peer_ip = match.group(2) or ""

                state = "UP" if self._ppp_link_is_up(iface) else "DOWN"

                rx = self._read_text(f"/sys/class/net/{iface}/statistics/rx_bytes").strip()
                tx = self._read_text(f"/sys/class/net/{iface}/statistics/tx_bytes").strip()
            else:
                state, rx, tx = "", "", ""

            uptime = 0
            etimes = self._run(["ps", "-o", "etimes=", "-p", str(entry)])
            if etimes.returncode == 0 and etimes.stdout.strip().isdigit():
                uptime = int(etimes.stdout.strip())

            sessions.append({
                "interface": iface,
                "pid": entry,
                "role": session["role"],
                "device": session["device"],
                "state": state,
                "local_ip": local_ip,
                "peer_ip": peer_ip,
                "uptime_seconds": uptime,
                "rx_bytes": int(rx) if rx.isdigit() else 0,
                "tx_bytes": int(tx) if tx.isdigit() else 0,
                "radius_attributes": self._radius_attributes(iface),
            })

        concentrators = 0
        res = self._run(["pgrep", "-c", "pppoe-server"])
        if res.returncode == 0 and res.stdout.strip().isdigit():
            concentrators = int(res.stdout.strip())

        client_sessions = [s for s in sessions if s["role"] == "client"]
        server_sessions = [s for s in sessions if s["role"] == "server"]

        return {
            "status": "ok",
            "sessions": sessions,
            # Kept for the existing callers that only wanted a count.
            "links": [{"pid": s["pid"], "interface": s["interface"]} for s in client_sessions],
            "client_links": len(client_sessions),
            "server_sessions": len(server_sessions),
            "concentrators": concentrators,
        }

    @staticmethod
    def _is_ip(value: str) -> bool:
        """True when value looks like an IPv4 or IPv6 address."""
        import ipaddress
        try:
            ipaddress.ip_address(value)
            return True
        except ValueError:
            return False

    @staticmethod
    def _show_ip_rules() -> list:
        """All IPv4 and IPv6 routing policy rules, as text lines."""
        lines = []
        for family in ("-4", "-6"):
            res = subprocess.run(["ip", family, "rule", "show"], capture_output=True, text=True)
            lines.extend(res.stdout.splitlines())
        return lines

    async def rpc_vpn_ipsec_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_vpn_ipsec_reload(params)

    # ------------------ DHCP Relay (dhcp.relay_*) ------------------
    def _dhcp_relay_validate(self, instances: Any) -> tuple:
        """Validate the caller's relay instances. Returns (clean, errors)."""
        clean: list = []
        errors: list = []
        if not isinstance(instances, list):
            return [], ["instances must be a list"]
        if len(instances) > DHCP_RELAY_MAX:
            return [], [f"too many relay instances (max {DHCP_RELAY_MAX})"]

        seen: set = set()
        for entry in instances:
            if not isinstance(entry, dict):
                errors.append("relay instance is not an object")
                continue
            name = str(entry.get("name") or "")
            if not DHCP_SLUG_RE.match(name):
                errors.append(f"invalid relay instance name: {name!r}")
                continue
            if name in seen:
                errors.append(f"duplicate relay instance name: {name}")
                continue
            seen.add(name)

            ifaces = entry.get("interfaces")
            servers = entry.get("servers")
            if not isinstance(ifaces, list) or not ifaces:
                errors.append(f"relay {name} has no interfaces")
                continue
            if not isinstance(servers, list) or not servers:
                errors.append(f"relay {name} has no servers")
                continue

            clean_ifaces = []
            for iface in ifaces:
                iface = str(iface)
                if not DHCP_IFACE_RE.match(iface):
                    errors.append(f"relay {name} has an invalid interface: {iface!r}")
                    continue
                clean_ifaces.append(iface)

            clean_servers = []
            for server in servers:
                server = str(server)
                try:
                    ipaddress.ip_address(server)
                except ValueError:
                    errors.append(f"relay {name} has an invalid server: {server!r}")
                    continue
                clean_servers.append(server)

            if not clean_ifaces or not clean_servers:
                continue
            clean.append({"name": name, "interfaces": clean_ifaces, "servers": clean_servers})

        return clean, errors

    @staticmethod
    def _dhcp_helper_unit(slug: str, interfaces: list, servers: list) -> str:
        args = [DHCP_HELPER_BIN]
        for iface in interfaces:
            args += ["-i", iface]
        for server in servers:
            args += ["-s", server]
        args += ["-r", f"/var/run/dhcp-helper-{slug}.pid"]
        return (
            "[Unit]\n"
            f"Description=MicronOS DHCP Relay ({DHCP_HELPER_PREFIX}{slug})\n"
            "After=network-online.target\nWants=network-online.target\n\n"
            "[Service]\nType=simple\n"
            f"ExecStart={' '.join(args)}\n"
            "Restart=on-failure\n\n"
            "[Install]\nWantedBy=multi-user.target\n"
        )

    @staticmethod
    def _dhcp_relay_existing_units() -> Dict[str, str]:
        out: Dict[str, str] = {}
        try:
            names = os.listdir(DHCP_HELPER_UNIT_DIR)
        except OSError:
            return out
        for name in names:
            if name.startswith(DHCP_HELPER_PREFIX) and name.endswith(".service"):
                out[name] = os.path.join(DHCP_HELPER_UNIT_DIR, name)
        return out

    async def rpc_dhcp_relay_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        clean, errors = self._dhcp_relay_validate(params.get("instances", []))
        if errors:
            return {"success": False, "status": "error", "message": "; ".join(errors)}

        steps: list = []
        warnings: list = []
        desired: set = set()

        for inst in clean:
            unit = f"{DHCP_HELPER_PREFIX}{inst['name']}.service"
            desired.add(unit)
            path = os.path.join(DHCP_HELPER_UNIT_DIR, unit)
            err = self._write_config(path, self._dhcp_helper_unit(inst["name"], inst["interfaces"], inst["servers"]))
            if err:
                warnings.append(err)
                continue
            steps.append(f"wrote {unit}")

        res = self._run(["systemctl", "daemon-reload"])
        steps.append(f"systemctl daemon-reload rc={res.returncode}")

        started = []
        for inst in clean:
            unit = f"{DHCP_HELPER_PREFIX}{inst['name']}.service"
            res = self._run(["systemctl", "restart", unit])
            steps.append(f"systemctl restart {unit} rc={res.returncode}")
            if res.returncode != 0:
                warnings.append(f"{unit} failed to restart")
            started.append({"name": inst["name"], "unit": unit, "state": self._service_state(unit)})

        # Retire instances that are no longer configured.
        for unit, path in self._dhcp_relay_existing_units().items():
            if unit in desired:
                continue
            self._run(["systemctl", "stop", unit])
            self._run(["systemctl", "disable", unit])
            steps.append(f"systemctl disable {unit}")
            try:
                os.remove(path)
            except OSError:
                pass

        return {
            "success": not warnings,
            "status": "applied" if not warnings else "error",
            "instances": started,
            "steps": steps,
            "warnings": warnings,
        }

    async def rpc_dhcp_relay_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        instances = []
        for unit in sorted(self._dhcp_relay_existing_units()):
            slug = unit[len(DHCP_HELPER_PREFIX):-len(".service")]
            instances.append({"name": slug, "unit": unit, "state": self._service_state(unit)})
        return {"success": True, "status": "ok", "instances": instances}

    async def rpc_dhcp_relay_service(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Start / stop / restart one relay helper instance.

        The instance is addressed by the same slug `dhcp.relay_apply` used to
        create it. The slug is re-validated here and the unit file must already
        exist, so this can only ever drive a `dhcp-helper@` unit MicronOS itself
        generated — never an arbitrary systemd unit.
        """
        slug = str(params.get("slug") or "")
        action = str(params.get("action") or "")

        if not DHCP_SLUG_RE.match(slug):
            return {"success": False, "status": "error", "message": f"invalid relay instance name: {slug!r}"}
        if action not in SERVICE_ACTIONS:
            return {"success": False, "status": "error", "message": f"unsupported relay action: {action!r}"}

        unit = f"{DHCP_HELPER_PREFIX}{slug}.service"
        path = os.path.join(DHCP_HELPER_UNIT_DIR, unit)
        if not os.path.exists(path):
            return {"success": False, "status": "error", "message": f"relay instance not applied yet: {slug}"}

        res = self._run(["systemctl", action, unit])
        ok = res.returncode == 0
        return {
            "success": ok,
            "status": "ok" if ok else "error",
            "action": action,
            "unit": unit,
            "message": "" if ok else (res.stdout + res.stderr).strip(),
            "state": self._service_state(unit),
        }

    async def rpc_dhcp_relay_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Recent journal lines for one relay helper unit (the widget's LED
        click-through). The slug is re-validated and the unit file must exist,
        exactly like `dhcp.relay_service`, so this never reads an arbitrary
        journal.
        """
        slug = str(params.get("slug") or "")

        if not DHCP_SLUG_RE.match(slug):
            return {"success": False, "status": "error", "message": f"invalid relay instance name: {slug!r}"}

        unit = f"{DHCP_HELPER_PREFIX}{slug}.service"
        path = os.path.join(DHCP_HELPER_UNIT_DIR, unit)
        if not os.path.exists(path):
            return {"success": False, "status": "error", "message": f"relay instance not applied yet: {slug}"}

        try:
            lines = max(1, min(int(params.get("lines") or 30), 500))
        except (TypeError, ValueError):
            lines = 30

        res = self._run(["journalctl", "-u", unit, "-n", str(lines), "--no-pager"])
        return {
            "success": True,
            "status": "ok",
            "unit": unit,
            "logs": (res.stdout or "").strip(),
        }

    # ------------------ DHCP Server (Kea, dhcp.apply / dhcp.restart / dhcp.status) ------------------
    @staticmethod
    def _dhcp_kea_validate(config: Any) -> tuple:
        """
        Structural validation of a caller-supplied Kea DHCPv4 config.

        Returns (parsed_config, errors). The parsed config is a Python object so
        the caller can re-serialize it deterministically. This is a *structural*
        check only — Kea's own `-t` test is still run before anything is written.
        """
        if isinstance(config, (dict, list)):
            data: Any = config
        elif isinstance(config, str) and config.strip():
            if len(config.encode("utf-8")) > KEA_CONFIG_MAX_BYTES:
                return None, ["config exceeds the maximum allowed size"]
            try:
                data = strict_json_loads(config)
            except (ValueError, TypeError) as exc:
                return None, [f"config is not valid JSON: {exc}"]
        else:
            return None, ["config must be a non-empty JSON string"]

        if not isinstance(data, dict) or not isinstance(data.get("Dhcp4"), dict):
            return None, ["config must be an object with a top-level 'Dhcp4' object"]

        dhcp4 = data["Dhcp4"]
        subnets = dhcp4.get("subnet4")
        if subnets is None:
            subnets = []
        if not isinstance(subnets, list):
            return None, ["Dhcp4.subnet4 must be a list"]
        if len(subnets) > KEA_MAX_SUBNETS:
            return None, [f"too many subnets (max {KEA_MAX_SUBNETS})"]

        errors: list = []
        for idx, subnet in enumerate(subnets):
            if not isinstance(subnet, dict):
                errors.append(f"subnet4[{idx}] is not an object")
                continue

            cidr = subnet.get("subnet")
            try:
                ipaddress.ip_network(str(cidr), strict=False)
            except ValueError:
                errors.append(f"subnet4[{idx}] has an invalid subnet: {cidr!r}")

            if not isinstance(subnet.get("id"), int) or isinstance(subnet.get("id"), bool):
                errors.append(f"subnet4[{idx}] is missing an integer id")

            pools = subnet.get("pools") or []
            if not isinstance(pools, list):
                errors.append(f"subnet4[{idx}].pools must be a list")
            else:
                for pool in pools:
                    rng = pool.get("pool") if isinstance(pool, dict) else None
                    if not isinstance(rng, str) or " - " not in rng:
                        errors.append(f"subnet4[{idx}] has an invalid pool range: {rng!r}")
                        continue
                    start, _, end = rng.partition(" - ")
                    for label, addr in (("start", start.strip()), ("end", end.strip())):
                        try:
                            ipaddress.ip_address(addr)
                        except ValueError:
                            errors.append(f"subnet4[{idx}] pool {label} is not a valid IP: {addr!r}")

            reservations = subnet.get("reservations") or []
            if not isinstance(reservations, list):
                errors.append(f"subnet4[{idx}].reservations must be a list")
            else:
                for res in reservations:
                    if not isinstance(res, dict):
                        errors.append(f"subnet4[{idx}] has a non-object reservation")
                        continue
                    mac = str(res.get("hw-address") or "")
                    if not MAC_RE.match(mac):
                        errors.append(f"subnet4[{idx}] has an invalid hw-address: {mac!r}")
                    try:
                        ipaddress.ip_address(str(res.get("ip-address") or ""))
                    except ValueError:
                        errors.append(f"subnet4[{idx}] has an invalid reservation ip-address")

        return data, errors

    async def rpc_dhcp_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Validate, write and activate a compiled Kea DHCPv4 configuration.

        Order matters: nothing is written until Kea's own `kea-dhcp4 -t` accepts
        the candidate, and the unit is only restarted once the file is in place.
        A config Kea rejects leaves the running configuration untouched.
        """
        data, errors = self._dhcp_kea_validate(params.get("config"))
        if errors:
            return {"success": False, "status": "error", "message": "; ".join(errors)}

        # Re-serialize so the file on disk is byte-for-byte what was validated.
        content = json.dumps(data, indent=4, ensure_ascii=False, allow_nan=False)

        steps: list = []
        warnings: list = []

        # 1. Kea's own syntax/semantic test on a throwaway file.
        fd, tmp = tempfile.mkstemp(prefix="micron-kea-", suffix=".conf")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(content)
            res = self._run([KEA_DHCP4_BIN, "-t", tmp])
            if res.returncode == 127:
                warnings.append("kea-dhcp4 binary unavailable; syntax check skipped")
            elif res.returncode != 0:
                detail = (res.stdout + res.stderr).strip()
                return {
                    "success": False,
                    "status": "error",
                    "message": "kea-dhcp4 rejected the configuration: " + (detail or f"rc={res.returncode}"),
                }
            else:
                steps.append("kea-dhcp4 -t passed")
        finally:
            try:
                os.remove(tmp)
            except OSError:
                pass

        # 2. Atomic write of the accepted configuration.
        err = self._write_config(KEA_DHCP4_CONF, content)
        if err:
            return {
                "success": False,
                "status": "error",
                "message": f"could not write {KEA_DHCP4_CONF}: {err}",
            }
        steps.append(f"wrote {KEA_DHCP4_CONF}")
        try:
            os.makedirs(os.path.dirname(KEA_MANAGED_MARKER), exist_ok=True)
            safe_atomic_write(KEA_MANAGED_MARKER, time.strftime("%Y-%m-%d %H:%M:%S") + "\n", mode=0o644)
        except OSError:
            pass

        # 3. Restart the daemon so it picks up the new file.
        res = self._run(["systemctl", "restart", KEA_DHCP4_UNIT])
        steps.append(f"systemctl restart {KEA_DHCP4_UNIT} rc={res.returncode}")
        if res.returncode != 0:
            warnings.append(f"{KEA_DHCP4_UNIT} failed to restart")

        return {
            "success": not warnings,
            "status": "applied" if not warnings else "error",
            "steps": steps,
            "warnings": warnings,
            "service": self._service_state(KEA_DHCP4_UNIT),
        }

    async def rpc_dhcp_restart(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Restart kea-dhcp4-server, optionally applying a new configuration first.

        The PHP DHCP page calls this for plain "reload the daemon" actions; when
        it supplies `config` the full validate/write/restart path is used, so the
        same method name is safe whether or not a config is being pushed.
        """
        if params.get("config") is not None:
            return await self.rpc_dhcp_apply(params)

        res = self._run(["systemctl", "restart", KEA_DHCP4_UNIT])
        ok = res.returncode == 0
        return {
            "success": ok,
            "status": "restarted" if ok else "error",
            "message": "" if ok else (res.stdout + res.stderr).strip(),
            "service": self._service_state(KEA_DHCP4_UNIT),
        }

    @staticmethod
    def _kea_lease_rows(path: str) -> list:
        """Rows of a Kea memfile lease CSV (and its rotated .1/.2 companions).

        Kea appends a record each time a lease changes, so the newest record
        for an address is its current state; files are read oldest first.
        """
        import csv
        rows: list = []
        for candidate in (path + ".2", path + ".1", path):
            try:
                with open(candidate, "r", encoding="utf-8", errors="replace", newline="") as fh:
                    reader = csv.DictReader(fh)
                    for row in reader:
                        if isinstance(row, dict) and row.get("address"):
                            rows.append(row)
            except OSError:
                continue
        return rows

    async def rpc_dhcp_leases(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Read-only: active DHCPv4 leases from Kea's lease file.

        Only leases that are in the default state and not yet expired are
        returned. When Kea has never run there is no file and the list is
        empty — no lease is ever invented.
        """
        try:
            limit = int(params.get("limit", DHCP_LEASES_MAX))
        except (TypeError, ValueError):
            limit = DHCP_LEASES_MAX
        limit = max(1, min(limit, DHCP_LEASES_MAX))
        now = int(time.time())
        latest: Dict[str, Dict[str, Any]] = {}
        for row in self._kea_lease_rows(KEA_LEASES4_CSV):
            latest[row["address"]] = row
        leases = []
        for addr, row in latest.items():
            try:
                ipaddress.IPv4Address(addr)
                expire = int(row.get("expire") or 0)
                valid = int(row.get("valid_lifetime") or 0)
                state = int(row.get("state") or 0)
            except ValueError:
                continue
            if state != 0 or expire <= now:
                continue
            leases.append({
                "ip": addr,
                "mac": (row.get("hwaddr") or "").lower(),
                "hostname": (row.get("hostname") or "").rstrip("."),
                "client_id": row.get("client_id") or "",
                "start": time.strftime("%Y-%m-%d %H:%M", time.localtime(expire - valid)) if valid else "",
                "expires": time.strftime("%Y-%m-%d %H:%M", time.localtime(expire)),
                "expires_epoch": expire,
                "subnet_id": row.get("subnet_id") or "",
                "status": "active",
                "type": "dynamic",
            })
        leases.sort(key=lambda l: tuple(int(o) for o in l["ip"].split(".")))
        return {"success": True, "status": "ok", "leases": leases[:limit], "count": len(leases),
                "source": KEA_LEASES4_CSV if os.path.exists(KEA_LEASES4_CSV) else None}

    async def rpc_dhcp_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return {
            "success": True,
            "status": "ok",
            "service": self._service_state(KEA_DHCP4_UNIT),
            "config": os.path.exists(KEA_DHCP4_CONF),
        }

    # ------------------ Generic Service Control ------------------
    async def rpc_service_restart(self, params: Dict[str, Any]) -> Dict[str, Any]:
        service = params.get("service", "")
        if not service:
            return {"status": "error", "message": "No service specified"}
        # Whitelist safe services
        allowed = {"suricata", "strongswan", "strongswan-starter", "squid", "kea-dhcp4-server", "unbound", "wireguard", "openvpn", "pppoe-server"}
        base_svc = service.split('@')[0]
        if base_svc not in allowed:
            logger.warning(f"Attempted restart of non-whitelisted service: {service}")
            return {"status": "error", "message": f"Service {service} restart not permitted"}
        if base_svc in ("strongswan", "strongswan-starter"):
            # strongSwan 6 (Debian 13) runs swanctl/VICI under strongswan.service;
            # strongswan-starter (ipsec/stroke) is only present on older images.
            for unit in ("strongswan", "strongswan-starter"):
                state = subprocess.run(["systemctl", "show", "-p", "LoadState", "--value", unit],
                                       capture_output=True, text=True)
                if state.stdout.strip() == "loaded":
                    service = unit
                    break
        res = subprocess.run(["systemctl", "restart", service], capture_output=True, text=True)
        return {"status": "restarted" if res.returncode == 0 else "error", "output": res.stdout + res.stderr}

    # ------------------ DNS Resolver Control ------------------
    async def rpc_dns_flush(self, params: Dict[str, Any]) -> Dict[str, Any]:
        res = subprocess.run(["unbound-control", "flush_zone", "."], capture_output=True, text=True)
        if res.returncode != 0:
            res = subprocess.run(["systemctl", "reload", "unbound"], capture_output=True, text=True)
        return {"status": "flushed" if res.returncode == 0 else "error", "output": res.stdout + res.stderr}

    async def rpc_dns_cache_flush(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_dns_flush(params)

    async def rpc_squid_reload(self, params: Dict[str, Any]) -> Dict[str, Any]:
        return await self.rpc_webfilter_reload(params)

    # ------------------ SD-WAN SLA Probing & Path Steering ------------------

    async def rpc_sdwan_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply compiled SD-WAN PBR rules: flush micron_sdwan nftables table and
        install the new steering chains, then sync iproute2 routing tables."""
        compiled = params.get("compiled", {})
        nft_rules = compiled.get("nftables", "")
        iproute2_cmds = compiled.get("iproute2", "")
        errors: list = []

        if not compiled.get("enabled", True):
            # SD-WAN disabled — flush the table if it exists
            try:
                await asyncio.create_subprocess_exec(
                    "nft", "delete", "table", "ip", "micron_sdwan",
                    stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
                )
            except Exception:
                pass
            return {"status": "disabled", "message": "SD-WAN engine disabled; steering table flushed."}

        # Write and apply nftables ruleset
        if nft_rules:
            try:
                import tempfile, os
                with tempfile.NamedTemporaryFile(
                    mode="w", suffix=".nft", delete=False, prefix="/tmp/micron_sdwan_"
                ) as tf:
                    tf.write(nft_rules)
                    tf_path = tf.name
                proc = await asyncio.create_subprocess_exec(
                    "nft", "-f", tf_path,
                    stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
                )
                _, stderr = await proc.communicate()
                os.unlink(tf_path)
                if proc.returncode != 0:
                    errors.append(f"nftables: {stderr.decode().strip()}")
            except Exception as exc:
                errors.append(f"nftables exception: {exc}")

        # Apply iproute2 PBR routing tables
        for cmd in iproute2_cmds.splitlines():
            cmd = cmd.strip()
            if not cmd or cmd.startswith("#"):
                continue
            try:
                proc = await asyncio.create_subprocess_shell(
                    cmd,
                    stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE
                )
                _, stderr = await proc.communicate()
                if proc.returncode not in (0, 2):  # 2 = already exists
                    errors.append(f"iproute2: {cmd!r} -> {stderr.decode().strip()}")
            except Exception as exc:
                errors.append(f"iproute2 exception for {cmd!r}: {exc}")

        # Persist SLA state to /run/micron/sdwan_sla.json for probe daemon
        sla_path = "/run/micron/sdwan_sla.json"
        try:
            state = {"applied_at": time.time(), "errors": errors, "enabled": True}
            safe_atomic_write(sla_path, json.dumps(state), mode=0o644)
        except Exception:
            pass

        return {
            "status": "applied" if not errors else "partial",
            "errors": errors,
            "message": "SD-WAN PBR rules applied." if not errors else f"Applied with {len(errors)} error(s).",
        }

    async def rpc_sdwan_probe_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Return live SLA probe metrics from /run/micron/sdwan_sla.json.
        Falls back to empty metrics when the probe daemon has not yet written results."""
        sla_path = "/run/micron/sdwan_sla.json"
        try:
            import json as _json

            def _reject_nonstandard(token):
                raise ValueError(f"non-standard JSON constant rejected: {token}")

            with open(sla_path) as f:
                data = _json.loads(f.read(), parse_constant=_reject_nonstandard)
            return {"status": "ok", "data": data}
        except FileNotFoundError:
            return {"status": "no_data", "data": {}, "message": "Probe daemon has not yet written SLA metrics."}
        except Exception as exc:
            return {"status": "error", "data": {}, "message": str(exc)}

    async def rpc_sdwan_sla_report(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Run an on-demand ICMP ping SLA probe for each configured member interface
        and return RTT / jitter / loss measurements.  Requires the `ping` binary."""
        members = params.get("members", [])
        probe_target = params.get("probe_target", "8.8.8.8")
        count = max(5, min(int(params.get("count", 10)), 50))
        results = {}

        for member in members:
            iface = member.get("interface", "")
            member_id = member.get("id", iface)
            gateway = member.get("gateway_ip", "")
            try:
                # Use interface-bound ping for accurate per-uplink measurement
                ping_argv = [
                    "ping", "-I", iface, "-c", str(count),
                    "-i", "0.2", "-W", "2", probe_target
                ]
                proc = await asyncio.create_subprocess_exec(
                    *ping_argv,
                    stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
                )
                stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=count * 2 + 5)
                output = stdout.decode(errors="replace")

                # Parse rtt min/avg/max/mdev line
                latency_ms, jitter_ms, loss_pct = 0.0, 0.0, 0.0
                for line in output.splitlines():
                    if "rtt min/avg/max/mdev" in line:
                        # "rtt min/avg/max/mdev = 1.234/2.345/3.456/0.123 ms"
                        nums = line.split("=")[-1].strip().split("/")
                        if len(nums) >= 4:
                            latency_ms = float(nums[1])
                            jitter_ms = float(nums[3].split()[0])
                    if "packet loss" in line:
                        for tok in line.split():
                            if "%" in tok:
                                loss_pct = float(tok.replace("%", ""))
                                break

                status = "alive"
                if loss_pct >= 50.0 or latency_ms >= 500.0:
                    status = "dead"
                elif loss_pct >= 5.0 or latency_ms >= 150.0 or jitter_ms >= 25.0:
                    status = "degraded"

                results[member_id] = {
                    "interface": iface,
                    "gateway": gateway,
                    "latency_ms": round(latency_ms, 2),
                    "jitter_ms": round(jitter_ms, 2),
                    "loss_pct": round(loss_pct, 2),
                    "status": status,
                    "measured_at": __import__("time").strftime("%Y-%m-%d %H:%M:%S"),
                }
            except asyncio.TimeoutError:
                results[member_id] = {
                    "interface": iface, "gateway": gateway,
                    "latency_ms": 9999.0, "jitter_ms": 0.0, "loss_pct": 100.0,
                    "status": "dead", "measured_at": __import__("time").strftime("%Y-%m-%d %H:%M:%S"),
                    "error": "probe_timeout",
                }
            except Exception as exc:
                results[member_id] = {
                    "interface": iface, "gateway": gateway,
                    "latency_ms": 0.0, "jitter_ms": 0.0, "loss_pct": 0.0,
                    "status": "unknown", "measured_at": __import__("time").strftime("%Y-%m-%d %H:%M:%S"),
                    "error": str(exc),
                }

        # Persist probe results for dashboard consumption
        sla_path = "/run/micron/sdwan_sla.json"
        try:
            safe_atomic_write(sla_path, json.dumps({"probed_at": time.time(), "members": results}), mode=0o644)
        except Exception:
            pass

        return {"status": "ok", "members": results}

    # ------------------ SSL/TLS Deep Packet Inspection (MITM CA Engine) ------------------

    async def rpc_ssl_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply SSL-Bump interception configuration: write Squid ssl_bump directives
        and signal Squid to reload gracefully."""
        errors: list = []

        # Re-use the existing Squid reload path
        reload_result = await self.rpc_webfilter_reload({})

        # Ensure the Squid DH parameters file exists (needed for ssl-bump)
        dh_path = "/etc/squid/ssl_cert/dhparam.pem"
        try:
            import os
            if not os.path.exists(dh_path):
                os.makedirs("/etc/squid/ssl_cert", exist_ok=True)
                proc = await asyncio.create_subprocess_exec(
                    "openssl", "dhparam", "-out", dh_path, "2048",
                    stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
                )
                await asyncio.wait_for(proc.communicate(), timeout=120)
        except Exception as exc:
            errors.append(f"dhparam: {exc}")

        return {
            "status": "applied" if not errors else "partial",
            "squid": reload_result,
            "errors": errors,
            "message": "SSL/TLS Interception engine updated and active.",
        }

    async def rpc_ssl_ca_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Return status of the Squid SSL-Bump interception CA certificate."""
        ca_cert_path = "/etc/squid/ssl_cert/micron_ca.pem"
        ca_key_path = "/etc/squid/ssl_cert/micron_ca.key"
        try:
            import os, subprocess
            cert_exists = os.path.exists(ca_cert_path)
            key_exists = os.path.exists(ca_key_path)
            cn = "Micron OS Proxy Inspection CA"
            cached_certs = 0

            if cert_exists:
                try:
                    result = subprocess.run(
                        ["openssl", "x509", "-noout", "-subject", "-enddate", "-in", ca_cert_path],
                        capture_output=True, text=True, timeout=5
                    )
                    subject_line = next((l for l in result.stdout.splitlines() if "subject" in l.lower()), "")
                    if "CN" in subject_line:
                        cn = subject_line.split("CN")[-1].lstrip(" =").split(",")[0].strip()
                except Exception:
                    pass

            # Count cached dynamic certificates if Squid db dir exists
            squid_db = "/var/lib/squid/ssl_db"
            if os.path.isdir(squid_db):
                try:
                    cached_certs = sum(1 for _ in os.scandir(squid_db))
                except Exception:
                    pass

            return {
                "status": "active" if cert_exists and key_exists else "not_configured",
                "common_name": cn,
                "key_type": "RSA 4096-bit",
                "cert_exists": cert_exists,
                "key_exists": key_exists,
                "cached_certs": cached_certs,
            }
        except Exception as exc:
            return {"status": "error", "message": str(exc), "cached_certs": 0}

    async def rpc_ssl_sessions_active(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Return a snapshot of active SSL-intercepted sessions from Squid's
        cache manager or /proc/net/nf_conntrack filtered for HTTPS (dport 443)."""
        sessions: list = []
        try:
            import subprocess, re
            # Query Squid's cache manager over HTTP. squidclient does not work
            # with Squid 6 (Debian 13), so use the manager URL on the plain
            # http_port, which squid.conf allows from localhost.
            mgr_port = 3128
            try:
                with open("/etc/squid/squid.conf") as conf:
                    for conf_line in conf:
                        m = re.match(r"\s*http_port\s+(?:[\d.]+:)?(\d+)\s*$", conf_line)
                        if m:
                            mgr_port = int(m.group(1))
                            break
            except OSError:
                pass
            result = subprocess.run(
                ["curl", "-fsS", "--max-time", "5",
                 f"http://127.0.0.1:{mgr_port}/squid-internal-mgr/active_requests"],
                capture_output=True, text=True, timeout=8
            )
            if result.returncode != 0:
                raise RuntimeError(result.stderr.strip() or "squid cache manager unavailable")
            # Parse minimal fields from Squid active_requests output
            current: dict = {}
            for line in result.stdout.splitlines():
                line = line.strip()
                if line.startswith("Connection:"):
                    if current:
                        sessions.append(current)
                    current = {}
                elif ":" in line:
                    k, _, v = line.partition(":")
                    current[k.strip().lower().replace(" ", "_")] = v.strip()
            if current:
                sessions.append(current)
        except Exception:
            # Fallback: parse /proc/net/nf_conntrack for ESTABLISHED port-443 TCP entries
            try:
                with open("/proc/net/nf_conntrack") as f:
                    for line in f:
                        if "dport=443" in line and "ESTABLISHED" in line:
                            parts = {p.split("=")[0]: p.split("=")[1] for p in line.split() if "=" in p}
                            sessions.append({
                                "src": parts.get("src", "?"), "dst": parts.get("dst", "?"),
                                "sport": parts.get("sport", "?"), "dport": "443",
                                "state": "ESTABLISHED",
                            })
                            if len(sessions) >= 100:
                                break
            except Exception:
                pass

        return {"status": "ok", "sessions": sessions[:100], "count": len(sessions)}

    # ------------------ Clientless Web & Desktop Gateway (ZTNA) ------------------

    async def rpc_ztna_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply Clientless ZTNA Gateway configuration: write Nginx reverse proxy
        and Guacamole tunnel configuration, test syntax, and reload Nginx & guacd."""
        errors: list = []
        config = params.get("config", {})
        nginx_conf = config.get("nginx_config", "")

        conf_path = "/etc/nginx/sites-available/micron-ztna.conf"
        enabled_link = "/etc/nginx/sites-enabled/micron-ztna.conf"

        try:
            import os
            os.makedirs("/etc/nginx/sites-available", exist_ok=True)
            os.makedirs("/etc/nginx/sites-enabled", exist_ok=True)

            if nginx_conf:
                safe_atomic_write(conf_path, nginx_conf, mode=0o644)
                if not os.path.islink(enabled_link) and not os.path.exists(enabled_link):
                    try:
                        os.symlink(conf_path, enabled_link)
                    except OSError:
                        pass

            # Nginx here is ONLY the ZTNA reverse proxy on :443 — port 80 is the
            # Apache2 management plane. Debian's nginx package ships
            # /etc/nginx/sites-enabled/default with `listen 80 default_server;`,
            # which makes nginx contend with apache2 for :80. Drop it before we
            # start or reload anything.
            default_site = "/etc/nginx/sites-enabled/default"
            if os.path.islink(default_site) or os.path.exists(default_site):
                try:
                    os.unlink(default_site)
                except OSError as exc:
                    errors.append(f"nginx: could not remove {default_site}: {exc}")

            # A `listen ... ssl` server block with no certificate makes `nginx -t`
            # fail, so provision a self-signed pair if the operator has not
            # installed a real one.
            cert_err = _ensure_ztna_certificate(str(config.get("summary", {}).get("domain") or ""))
            if cert_err:
                errors.append(cert_err)

            # Test Nginx configuration syntax if nginx is installed
            proc = await asyncio.create_subprocess_exec(
                "nginx", "-t",
                stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=10)
            if proc.returncode != 0:
                err_msg = stderr.decode(errors="replace").strip()
                errors.append(f"nginx -t failed: {err_msg}")
            else:
                # Enable AND start, then reload. The image ships nginx disabled
                # (it must not claim a port at boot), and `systemctl reload` on
                # an inactive unit is a no-op — which is why ZTNA could report
                # "applied" while nothing was ever listening on :443.
                start_proc = await asyncio.create_subprocess_exec(
                    "systemctl", "enable", "--now", "nginx",
                    stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE
                )
                _, start_err = await asyncio.wait_for(start_proc.communicate(), timeout=15)
                if start_proc.returncode != 0:
                    errors.append(
                        "nginx could not be started: "
                        + start_err.decode(errors="replace").strip()
                    )
                else:
                    # Reload Nginx gracefully so the new server block is live
                    reload_proc = await asyncio.create_subprocess_exec(
                        "systemctl", "reload", "nginx",
                        stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
                    )
                    await asyncio.wait_for(reload_proc.communicate(), timeout=10)
                    if reload_proc.returncode != 0:
                        errors.append("nginx reload returned a non-zero status")

        except FileNotFoundError:
            pass  # Nginx not installed in this environment
        except Exception as exc:
            errors.append(f"nginx: {exc}")

        # Ensure guacd is running if active
        try:
            proc = await asyncio.create_subprocess_exec(
                "systemctl", "is-active", "--quiet", "guacd",
                stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
            )
            await asyncio.wait_for(proc.communicate(), timeout=5)
            if proc.returncode != 0:
                start_proc = await asyncio.create_subprocess_exec(
                    "systemctl", "enable", "--now", "guacd",
                    stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
                )
                await asyncio.wait_for(start_proc.communicate(), timeout=10)
        except Exception:
            pass

        # Write runtime state to /run/micron/ztna_state.json atomically
        try:
            state_data = {
                "applied_at": time.time(),
                "enabled": config.get("enabled", True),
                "resources_count": config.get("summary", {}).get("resources_count", 0),
                "errors": errors,
            }
            safe_atomic_write("/run/micron/ztna_state.json", json.dumps(state_data), mode=0o644)
        except Exception:
            pass

        return {
            "status": "applied" if not errors else "partial",
            "errors": errors,
            "message": "Clientless ZTNA Gateway configuration applied successfully." if not errors else f"Applied with {len(errors)} warning(s).",
        }

    async def rpc_ztna_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Return runtime status of Clientless Gateway services (guacd and nginx)."""
        guacd_active = False
        nginx_active = False
        try:
            proc = await asyncio.create_subprocess_exec(
                "systemctl", "is-active", "--quiet", "guacd",
                stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
            )
            await asyncio.wait_for(proc.communicate(), timeout=5)
            guacd_active = (proc.returncode == 0)
        except Exception:
            pass

        try:
            proc = await asyncio.create_subprocess_exec(
                "systemctl", "is-active", "--quiet", "nginx",
                stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
            )
            await asyncio.wait_for(proc.communicate(), timeout=5)
            nginx_active = (proc.returncode == 0)
        except Exception:
            pass

        state_path = "/run/micron/ztna_state.json"
        state = {}
        try:
            import json as _json

            def _reject_nonstandard(token):
                raise ValueError(f"non-standard JSON constant rejected: {token}")

            with open(state_path) as f:
                state = _json.loads(f.read(), parse_constant=_reject_nonstandard)
        except Exception:
            pass

        return {
            "status": "ok",
            "guacd_active": guacd_active,
            "nginx_active": nginx_active,
            "state": state,
        }

    async def rpc_ztna_test_guacd(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Test socket connectivity to guacd daemon."""
        host = str(params.get("host") or "127.0.0.1").strip()
        port = int(params.get("port") or 4822)
        start_time = time.time()
        try:
            reader, writer = await asyncio.wait_for(
                asyncio.open_connection(host, port),
                timeout=4.0
            )
            elapsed_ms = round((time.time() - start_time) * 1000, 2)
            writer.close()
            await writer.wait_closed()
            return {
                "success": True,
                "host": host,
                "port": port,
                "latency_ms": elapsed_ms,
                "message": f"Successfully connected to guacd daemon at {host}:{port} ({elapsed_ms}ms).",
            }
        except Exception as exc:
            return {
                "success": False,
                "host": host,
                "port": port,
                "error": str(exc),
                "message": f"Could not connect to guacd daemon at {host}:{port}: {exc}",
            }

    async def rpc_ztna_session_kill(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Terminate an active ZTNA remote session.

        The session's tunnel token is added to /run/micron/ztna-revoked.json,
        which micron-guacd-bridge polls: it refuses the token from then on and
        closes any tunnel already open with it. Entries are kept until the
        token would have expired anyway.
        """
        session_id = str(params.get("session_id", ""))
        token = params.get("token", "")
        if not isinstance(token, str) or "." not in token or len(token) > 8192:
            return {"status": "error", "session_id": session_id, "message": "A session token is required."}
        body, signature = token.rsplit(".", 1)
        if not re.fullmatch(r"[0-9a-f]{64}", signature):
            return {"status": "error", "session_id": session_id, "message": "Malformed session token."}
        now = int(time.time())
        expires = now + 3600
        try:
            raw = base64.b64decode(body + "=" * (-len(body) % 4), altchars=b"-_", validate=True)
            claimed = strict_json_loads(raw.decode("utf-8")).get("expires")
            if isinstance(claimed, int) and claimed > now:
                expires = claimed
        except (ValueError, TypeError, AttributeError):
            pass

        revoked: Dict[str, int] = {}
        try:
            with open(ZTNA_REVOKED_PATH, "r", encoding="utf-8") as fh:
                loaded = strict_json_loads(fh.read())
            if isinstance(loaded, dict):
                revoked = {k: v for k, v in loaded.items() if isinstance(v, int) and v > now}
        except (OSError, ValueError):
            pass
        revoked[signature] = expires
        try:
            os.makedirs(os.path.dirname(ZTNA_REVOKED_PATH), exist_ok=True)
            safe_atomic_write(ZTNA_REVOKED_PATH, json.dumps(revoked), mode=0o644)
        except OSError as exc:
            return {"status": "error", "session_id": session_id, "message": f"Could not revoke session: {exc}"}
        return {
            "status": "ok",
            "session_id": session_id,
            "message": f"Session {session_id} terminated.",
        }

    # ------------------ Diagnostics Methods ------------------

    @staticmethod
    def _diag_ip_literal(value: str):
        """The `ipaddress` object for an IP literal (an optional `%scope` is
        dropped), or None when the value is not one."""
        base = value.split("%", 1)[0]
        try:
            return ipaddress.ip_address(base)
        except ValueError:
            return None

    @staticmethod
    def _diag_target_ok(value: str) -> bool:
        """True for an IP literal (v4, v6, optionally scoped) or a hostname.

        A bare option such as `-f` is neither, so it can never reach `ping` as
        a command-line flag — which is what the previous handler allowed.
        """
        if value == "" or len(value) > 253:
            return False
        if RpcHandler._diag_ip_literal(value) is not None:
            return True
        if "%" in value:
            return False        # a scope only means anything on a literal
        return DIAG_HOSTNAME_RE.fullmatch(value) is not None

    def _diag_target_refusal(self, host: str, proto: str) -> Optional[str]:
        """pfSense's host and IP-protocol checks, shared by ping and traceroute.

        Returns the message a rejected request should carry, or None when the
        pair is acceptable.
        """
        if proto not in ("ipv4", "ipv6"):
            return "IP protocol must be IPv4 or IPv6"
        if not self._diag_target_ok(host):
            return "Hostname must be a valid hostname or IP address"

        literal = self._diag_ip_literal(host)
        if proto == "ipv4" and literal is not None and literal.version == 6:
            return "When using IPv4, the target host must be an IPv4 address or hostname"
        if proto == "ipv6" and literal is not None and literal.version == 4:
            return "When using IPv6, the target host must be an IPv6 address or hostname"
        return None

    def _diag_source_refusal(self, source: str, proto: str) -> Optional[str]:
        """The source-address half of the same checks.

        A source is an address this appliance owns; anything else is refused so
        it can never become an option, and it must match the chosen protocol so
        `ping`/`ping6`/`traceroute` are not handed the wrong family.
        """
        addr = self._diag_ip_literal(source)
        if addr is None:
            return "Source address must be a valid IP address"
        if addr.version == 6 and proto != "ipv6":
            return "An IPv6 source address requires the IPv6 protocol"
        if addr.version == 4 and proto != "ipv4":
            return "An IPv4 source address requires the IPv4 protocol"
        return None

    @staticmethod
    def _diag_number(params: Dict[str, Any], key: str, default: int) -> int:
        """An integer field of a diagnostics form.

        A field that is absent — or present but empty, which is what a form
        posts for a select nobody touched — takes the default. Anything else
        must parse, and a ValueError here is the caller's "must be an integer"
        refusal.
        """
        raw = params.get(key)
        if raw is None or (isinstance(raw, str) and raw.strip() == ""):
            return default
        return int(raw)

    @staticmethod
    def _diag_checkbox(value: Any) -> bool:
        """A checkbox as the management plane posts it: true only for the
        truthy spellings, never for the strings `"0"` or `"false"`."""
        if isinstance(value, bool):
            return value
        return str(value).strip().lower() in ("1", "true", "on", "yes")

    def _ping_argv(self, params: Dict[str, Any]) -> tuple:
        """The validated `ping` argv for the Ping field set, or a refusal.

        Returns `(argv, None)` on success and `(None, message)` when a field is
        rejected. Both the one-shot `diag.ping` request and the streamed
        `diag.ping_start` session build their command line here, so the two paths
        can never validate differently.
        """
        host = str(params.get("host") or params.get("target") or "").strip()
        proto = str(params.get("ipproto") or "ipv4").strip().lower()
        source = str(params.get("sourceip") or "").strip()

        refusal = self._diag_target_refusal(host, proto)
        if refusal is not None:
            return None, refusal

        try:
            count = self._diag_number(params, "count", 3)
        except (TypeError, ValueError):
            return None, "Maximum number of pings must be an integer"
        if not 1 <= count <= DIAG_PING_MAX_COUNT:
            return None, f"Maximum number of pings must be between 1 and {DIAG_PING_MAX_COUNT}"

        try:
            wait = self._diag_number(params, "wait", 1)
        except (TypeError, ValueError):
            return None, "Seconds between pings must be an integer"
        if not 1 <= wait <= DIAG_PING_MAX_WAIT:
            return None, f"Seconds between pings must be between 1 and {DIAG_PING_MAX_WAIT}"

        argv = [PING6_BIN if proto == "ipv6" else PING_BIN]
        if source:
            refusal = self._diag_source_refusal(source, proto)
            if refusal is not None:
                return None, refusal
            argv += ["-S", str(self._diag_ip_literal(source))]
        argv += ["-c", str(count), "-i", str(wait), "-W", str(DIAG_PING_TIMEOUT), host]
        return argv, None

    async def rpc_diag_ping(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """ICMP echo, run to completion (`diag.ping`).

        Mirrors pfSense's Ping page: a hostname, an IP protocol, an optional
        source address, and the maximum number of pings and seconds between
        them. Every value is validated before `ping` runs and the command is an
        argv list, so the caller can never name a flag. `target` is accepted as
        an alias for `host` so an older caller keeps working.

        The page streams a longer ping through `diag.ping_start`/`diag.stream_poll`
        instead; this one-shot form is kept for callers that want the whole run
        in a single answer.
        """
        argv, refusal = self._ping_argv(params)
        if refusal is not None:
            return {"success": False, "message": refusal}

        res = self._run(argv)
        output = (res.stdout or "") + (res.stderr or "")
        if res.returncode != 0 and output.strip() == "":
            # `ping` prints nothing at all when the name does not resolve; say so
            # rather than returning an empty box.
            output = f'Host "{argv[-1]}" did not respond or could not be resolved.\n'

        return {"output": output, "returncode": res.returncode, "cmd": " ".join(argv)}

    async def rpc_diag_ping_start(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Start a streamed ICMP echo (`diag.ping_start`).

        The same field set and the same argv as `diag.ping`, but `ping` is left
        running on its own and the page polls `diag.stream_poll` for the lines it
        has produced since the previous poll — so each reply reaches the terminal
        as it arrives rather than in one block at the end.
        """
        argv, refusal = self._ping_argv(params)
        if refusal is not None:
            return {"success": False, "message": refusal}
        host = str(params.get("host") or params.get("target") or "").strip()
        return self._stream_open(argv, "ping", host)

    def _traceroute_argv(self, params: Dict[str, Any]) -> tuple:
        """The validated `traceroute` argv for the Traceroute field set.

        Returns `(argv, None)` on success and `(None, message)` when a field is
        rejected, so the one-shot and streamed forms build the identical command.
        """
        host = str(params.get("host") or params.get("target") or "").strip()
        proto = str(params.get("ipproto") or "ipv4").strip().lower()
        source = str(params.get("sourceip") or "").strip()
        resolve = self._diag_checkbox(params.get("resolve"))
        useicmp = self._diag_checkbox(params.get("useicmp"))

        refusal = self._diag_target_refusal(host, proto)
        if refusal is not None:
            return None, refusal

        try:
            ttl = self._diag_number(params, "ttl", DIAG_TRACEROUTE_DEFAULT_TTL)
        except (TypeError, ValueError):
            return None, "Maximum number of hops must be an integer"
        if not 1 <= ttl <= DIAG_TRACEROUTE_MAX_TTL:
            return None, f"Maximum number of hops must be between 1 and {DIAG_TRACEROUTE_MAX_TTL}"

        argv = [TRACEROUTE6_BIN if proto == "ipv6" else TRACEROUTE_BIN]
        if not resolve:
            argv += ["-n"]
        if source:
            refusal = self._diag_source_refusal(source, proto)
            if refusal is not None:
                return None, refusal
            argv += ["-s", str(self._diag_ip_literal(source))]
        argv += ["-w", str(DIAG_TRACEROUTE_WAIT)]
        if useicmp:
            argv += ["-I"]
        argv += ["-m", str(ttl), host]
        return argv, None

    async def rpc_diag_traceroute(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Path trace, run to completion (`diag.traceroute`).

        Mirrors pfSense's Traceroute page: a hostname, an IP protocol, an
        optional source address, the maximum number of hops, and the Reverse
        Address Lookup and Use ICMP switches. The reverse lookup runs only when
        asked for — `-n` is what suppresses it, so the flag is present exactly
        when the box is clear — and ICMP is added with `-I`.

        The page streams through `diag.traceroute_start`/`diag.stream_poll`; this
        one-shot form is kept for callers that want the whole trace at once.
        """
        argv, refusal = self._traceroute_argv(params)
        if refusal is not None:
            return {"success": False, "message": refusal}

        res = self._run(argv)
        output = (res.stdout or "") + (res.stderr or "")
        if res.returncode != 0 and output.strip() == "":
            output = f'Host "{argv[-1]}" did not respond or could not be resolved.\n'

        return {"output": output, "returncode": res.returncode, "cmd": " ".join(argv)}

    async def rpc_diag_traceroute_start(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Start a streamed path trace (`diag.traceroute_start`).

        Same field set and argv as `diag.traceroute`, left running so each hop
        reaches the terminal as `traceroute` finds it.
        """
        argv, refusal = self._traceroute_argv(params)
        if refusal is not None:
            return {"success": False, "message": refusal}
        host = str(params.get("host") or params.get("target") or "").strip()
        return self._stream_open(argv, "traceroute", host)

    async def rpc_diag_dns_lookup(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """DNS query (`diag.dns_lookup`) — the Diagnostics → DNS Lookup page.

        The query name, the record type and an optional server are each
        validated, so neither the name nor the server can arrive at `dig` as a
        flag.
        """
        name = str(params.get("name") or "").strip()
        qtype = str(params.get("type") or "A").strip().upper()
        server = str(params.get("server") or "").strip()

        if not self._diag_target_ok(name):
            return {"success": False, "message": "Hostname must be a valid hostname or IP address"}
        if qtype not in DIAG_DNS_TYPES:
            return {"success": False,
                    "message": "DNS record type must be one of: " + ", ".join(DIAG_DNS_TYPES)}

        argv = [DIG_BIN, "+time=3", "+tries=2"]
        if server:
            if self._diag_ip_literal(server) is None:
                return {"success": False, "message": "DNS server must be a valid IP address"}
            argv.append(f"@{server}")
        argv += [name, qtype]

        res = self._run(argv)
        output = (res.stdout or "") + (res.stderr or "")
        if not output.strip():
            output = f'DNS lookup for "{name}" ({qtype}) returned no output.\n'

        return {"output": output, "returncode": res.returncode, "cmd": " ".join(argv)}

    async def rpc_diag_tcpdump(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Packet capture (`diag.tcpdump`) — a bounded, self-terminating read.

        The capture is capped at DIAG_CAPTURE_MAX packets so a probe can never
        run unbounded, the interface must look like an interface, and the BPF
        filter is one argv element — a filter is data, never a shell command.
        """
        iface = str(params.get("interface") or "").strip()
        bpffilter = str(params.get("filter") or "").strip()
        try:
            count = self._diag_number(params, "count", 10)
        except (TypeError, ValueError):
            return {"success": False, "message": "Packet count must be an integer"}
        if not 1 <= count <= DIAG_CAPTURE_MAX:
            return {"success": False,
                    "message": f"Packet count must be between 1 and {DIAG_CAPTURE_MAX}"}
        if DIAG_IFACE_RE.fullmatch(iface) is None:
            return {"success": False, "message": "Interface name is not valid"}
        if len(bpffilter) > DIAG_CAPTURE_FILTER_MAX or any(ch in bpffilter for ch in "\x00\r\n"):
            return {"success": False, "message": "Capture filter is not valid"}

        argv = [TCPDUMP_BIN, "-i", iface, "-n", "-c", str(count)]
        if bpffilter:
            argv.append(bpffilter)

        res = self._run(argv)
        output = (res.stdout or "") + (res.stderr or "")
        return {"output": output, "returncode": res.returncode, "cmd": " ".join(argv)}

    @staticmethod
    def _diag_socket_probe(host: str, port: int, proto: str, timeout: float) -> tuple:
        """One connect attempt. Returns `(status, detail)`.

        TCP reports the handshake honestly — a refused connection and a filtered
        one are different outcomes. UDP has no handshake at all, so the most it
        can prove is that the name resolved and one datagram was sent; that is
        what it reports rather than pretending the port is open.
        """
        try:
            if proto == "tcp":
                sock = socket.create_connection((host, port), timeout=timeout)
                sock.close()
                return "open", ""

            info = socket.getaddrinfo(host, port, type=socket.SOCK_DGRAM)
            if not info:
                return "closed", "the name did not resolve"
            sock = socket.socket(info[0][0], socket.SOCK_DGRAM)
            try:
                sock.settimeout(timeout)
                sock.connect(info[0][4])
                sock.send(b"\x00")
            finally:
                sock.close()
            return "sent", "no reply is expected: UDP is connectionless"
        except socket.timeout:
            return "filtered", f"the attempt timed out after {timeout:g}s"
        except OSError as exc:
            return "closed", (str(exc) or "connection refused or host unreachable")

    async def rpc_diag_port_probe(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Port / socket probe (`diag.port_probe`).

        The host is validated like every other diagnostics target, so it can
        never arrive at the socket layer as an option, and the port and timeout
        are bounded here as well as in the management plane.
        """
        host = str(params.get("host") or "").strip()
        proto = str(params.get("protocol") or "tcp").strip().lower()

        if not self._diag_target_ok(host):
            return {"success": False, "message": "Hostname must be a valid hostname or IP address"}
        if proto not in ("tcp", "udp"):
            return {"success": False, "message": "Protocol must be TCP or UDP"}

        try:
            port = self._diag_number(params, "port", 443)
        except (TypeError, ValueError):
            return {"success": False, "message": "Port must be an integer"}
        if not 1 <= port <= 65535:
            return {"success": False, "message": "Port must be between 1 and 65535"}

        try:
            timeout = float(params.get("timeout") or DIAG_PROBE_TIMEOUT_DEFAULT)
        except (TypeError, ValueError):
            return {"success": False, "message": "Timeout must be a number"}
        timeout = max(0.5, min(timeout, DIAG_PROBE_TIMEOUT_MAX))

        try:
            service = socket.getservbyport(port, proto)
        except OSError:
            service = "custom"

        began = time.time()
        status, detail = self._diag_socket_probe(host, port, proto, timeout)
        latency = round((time.time() - began) * 1000, 2)

        output = f"PORT SCAN PROBE: {host}:{port}/{proto} ({service})\n"
        output += "─" * 65 + "\n"
        if status == "open":
            output += "Status:        OPEN / ACCEPTING CONNECTIONS\n"
            output += f"Protocol:      {proto.upper()}\n"
            output += f"Round-Trip:    {latency} ms\n"
            output += "TCP Handshake: Completed (SYN -> SYN/ACK -> ACK)\n"
            output += f"Service Guess: {service.upper()}\n"
            output += f"Result:        Host is reachable and listening on port {port}."
        elif status == "sent":
            output += "Status:        SENT (no handshake to observe)\n"
            output += f"Protocol:      {proto.upper()}\n"
            output += f"Response Time: {latency} ms\n"
            output += f"Note:          {detail}\n"
            output += f"Result:        A datagram was sent to {host}:{port}; a silent port is expected."
        elif status == "filtered":
            output += "Status:        FILTERED (no response)\n"
            output += f"Protocol:      {proto.upper()}\n"
            output += f"Response Time: {latency} ms\n"
            output += f"System Error:  {detail}\n"
            output += "Firewall Note: Packets dropped by remote security policy or host is unreachable."
        else:
            output += "Status:        CLOSED OR UNREACHABLE\n"
            output += f"Protocol:      {proto.upper()}\n"
            output += f"Response Time: {latency} ms\n"
            output += f"System Error:  {detail}\n"
            output += "Firewall Note: Packets dropped by remote security policy or host is unreachable."

        return {"output": output, "status": status, "rtt": latency, "returncode": 0,
                "cmd": f"{proto} connect {host} {port}"}

    async def rpc_diag_dns_benchmark(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Multi-resolver DNS benchmark (`diag.dns_benchmark`).

        Every row is a real query: `dig` is timed against each resolver in turn,
        so a resolver that does not answer is reported as such rather than shown
        as a fast one. The resolver set is fixed in the daemon, not chosen by the
        caller.
        """
        domain = str(params.get("domain") or "").strip()
        if not self._diag_target_ok(domain):
            return {"success": False, "message": "Hostname must be a valid hostname or IP address"}

        rows = []
        best_name, best_ms = "", None
        for name, server, category in DIAG_BENCHMARK_RESOLVERS:
            began = time.time()
            res = self._run([DIG_BIN, "+time=1", "+tries=1", "+short", f"@{server}", domain, "A"])
            elapsed = round((time.time() - began) * 1000, 2)
            answers = [line for line in (res.stdout or "").splitlines() if line.strip()]
            status = "RESOLVED" if answers else "NO ANSWER"
            if answers and (best_ms is None or elapsed < best_ms):
                best_ms, best_name = elapsed, name
            rows.append((name, server, category, elapsed, status))

        output = f"DNS MULTI-RESOLVER BENCHMARK: {domain}\n"
        output += f"{'Resolver':<24} {'Server IP':<16} {'Category':<18} {'Latency':<12} {'Status':<10}\n"
        output += "─" * 78 + "\n"
        for name, server, category, elapsed, status in rows:
            output += f"{name:<24} {server:<16} {category:<18} {str(elapsed) + ' ms':<12} {status:<10}\n"
        output += "─" * 78 + "\n"
        if best_name:
            output += f"Summary: Lowest latency resolver is {best_name} ({best_ms} ms).\n"
        else:
            output += "Summary: no resolver answered the query.\n"
        output += "Appliance DNS forwarders recommended for lowest jitter."

        return {"output": output, "returncode": 0, "cmd": f"dig @<resolver> {domain} A"}

    # ------------------ Diagnostics: live packet capture ------------------
    #
    # `diag.tcpdump` above is a one-shot: it runs to completion and returns
    # everything at once. A live capture instead outlives the request that starts
    # it, so it is a session — started once, polled for the bytes produced since
    # the last read, and stopped. The BPF expression is compiled by `tcpdump -d`
    # *before* anything is launched, so a malformed filter is refused with
    # tcpdump's own reason instead of silently capturing every frame.

    @staticmethod
    def _capture_dir() -> str:
        os.makedirs(CAPTURE_DIR, exist_ok=True)
        return CAPTURE_DIR

    @staticmethod
    def _spawn_capture(argv: list, stdout_path: str):
        """Start a detached argv (never a shell) writing to `stdout_path`.

        Its own helper, separate from `_run`, so a test can drive the session
        lifecycle without a `tcpdump` binary on the machine.
        """
        handle = open(stdout_path, "wb")
        try:
            return subprocess.Popen(
                argv, stdout=handle, stderr=subprocess.STDOUT, start_new_session=True
            )
        finally:
            handle.close()

    def _capture_filter_refusal(self, iface: str, bpffilter: str) -> Optional[str]:
        """Compile the BPF expression, or None when tcpdump accepts it."""
        if bpffilter == "":
            return None
        res = self._run([TCPDUMP_BIN, "-d", "-i", iface, bpffilter])
        if res.returncode == 0:
            return None
        lines = (res.stderr or res.stdout or "").strip().splitlines()
        detail = lines[-1].strip() if lines else "tcpdump rejected the filter"
        return f"BPF filter is not valid: {detail}"

    @staticmethod
    def _session_read(session: Dict[str, Any]) -> str:
        """The bytes a session has produced since its last read.

        Shared by a live capture and a streamed probe: the session dict carries
        its own file and read offset, so a poll only ever hands back what is new.
        """
        try:
            with open(session["path"], "rb") as handle:
                handle.seek(session["offset"])
                chunk = handle.read(DIAG_SESSION_READ_MAX)
        except OSError:
            return ""
        session["offset"] += len(chunk)
        return chunk.decode("utf-8", errors="replace")

    @staticmethod
    def _session_terminate(session: Dict[str, Any]) -> None:
        """Stop a running session (the `timeout` wrapper and its child with it).

        The child was started in its own session, so the whole group is signalled
        — killing only `timeout` would leave tcpdump capturing (or `ping`
        looping). `killpg` is POSIX-only, so a platform without it falls back to
        terminating the one process, which is enough for the tests and for a dev
        box.
        """
        proc = session["proc"]
        if proc.poll() is not None:
            session["returncode"] = proc.returncode
            return

        killpg = getattr(os, "killpg", None)
        getpgid = getattr(os, "getpgid", None)
        can_group = killpg is not None and getpgid is not None

        def _signal(sig) -> None:
            try:
                if can_group:
                    killpg(getpgid(proc.pid), sig)
                else:
                    proc.terminate()
            except OSError:
                try:
                    proc.terminate()
                except OSError:
                    pass

        _signal(signal.SIGTERM)
        try:
            proc.wait(timeout=3)
        except subprocess.TimeoutExpired:
            _signal(signal.SIGKILL)
            proc.wait()
        session["returncode"] = proc.returncode

    @staticmethod
    def _reap_sessions(sessions: Dict[str, Dict[str, Any]], max_age: int) -> None:
        """Drop finished sessions, so a cap counts live ones only.

        A finished session is kept until the page has read its tail (`closed`),
        but only for the session's own wall-clock cap — otherwise a client that
        stops polling would hold a slot (and its file) for good.
        """
        now = time.time()
        for session_id in list(sessions):
            session = sessions[session_id]
            if session["proc"].poll() is None:
                continue
            if session["closed"] or now - session["started"] > max_age:
                sessions.pop(session_id, None)
                try:
                    os.unlink(session["path"])
                except OSError:
                    pass

    async def rpc_diag_capture_start(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Start a live capture (`diag.capture_start`)."""
        iface = str(params.get("interface") or "").strip()
        bpffilter = str(params.get("filter") or "").strip()
        try:
            count = self._diag_number(params, "count", 10)
        except (TypeError, ValueError):
            return {"success": False, "message": "Packet count must be an integer"}
        if not 1 <= count <= DIAG_CAPTURE_MAX:
            return {"success": False,
                    "message": f"Packet count must be between 1 and {DIAG_CAPTURE_MAX}"}
        if DIAG_IFACE_RE.fullmatch(iface) is None:
            return {"success": False, "message": "Interface name is not valid"}
        if len(bpffilter) > DIAG_CAPTURE_FILTER_MAX or any(ch in bpffilter for ch in "\x00\r\n"):
            return {"success": False, "message": "Capture filter is not valid"}

        refusal = self._capture_filter_refusal(iface, bpffilter)
        if refusal is not None:
            return {"success": False, "message": refusal}

        self._reap_sessions(self._captures, DIAG_CAPTURE_SECONDS)
        if len(self._captures) >= DIAG_CAPTURE_MAX_SESSIONS:
            return {"success": False,
                    "message": f"Too many active captures (at most {DIAG_CAPTURE_MAX_SESSIONS})"}

        session_id = os.urandom(8).hex()
        path = os.path.join(self._capture_dir(), session_id + ".log")
        # `timeout` is the wall-clock safety net: a filter that matches nothing
        # must not leave a capture running until the box is rebooted.
        argv = ["timeout", str(DIAG_CAPTURE_SECONDS), TCPDUMP_BIN,
                "-i", iface, "-n", "-l", "-c", str(count)]
        if bpffilter:
            argv.append(bpffilter)

        try:
            proc = self._spawn_capture(argv, path)
        except OSError as exc:
            return {"success": False, "message": f"could not start the capture: {exc}"}

        self._captures[session_id] = {
            "proc": proc,
            "path": path,
            "offset": 0,
            "started": time.time(),
            "cmd": " ".join(argv),
            "closed": False,
            "returncode": None,
        }
        return {"session": session_id, "running": True, "output": "", "cmd": " ".join(argv)}

    async def rpc_diag_capture_poll(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """The capture's output since the previous poll (`diag.capture_poll`)."""
        session_id = str(params.get("session") or "").strip()
        session = self._captures.get(session_id)
        if session is None:
            return {"success": False, "message": "no such capture session"}

        running = session["proc"].poll() is None
        output = self._session_read(session)
        if not running and session["returncode"] is None:
            session["returncode"] = session["proc"].returncode
            session["closed"] = True

        return {
            "session": session_id,
            "output": output,
            "running": running,
            "returncode": session["returncode"],
            "cmd": session["cmd"],
        }

    async def rpc_diag_capture_stop(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Stop a live capture and return whatever it produced last."""
        session_id = str(params.get("session") or "").strip()
        session = self._captures.get(session_id)
        if session is None:
            return {"success": False, "message": "no such capture session"}

        self._session_terminate(session)
        output = self._session_read(session)
        session["closed"] = True
        return {
            "session": session_id,
            "output": output,
            "running": False,
            "returncode": session["returncode"],
            "stopped": True,
        }

    # ------------------ Diagnostics: streamed probes (ping, traceroute) ---
    #
    # A ping prints a line per reply and a traceroute a line per hop; both can run
    # for a minute or more, so neither should make the page wait for the whole
    # command. They are sessions for the same reason a live capture is: the
    # daemon starts the command detached, the page polls `diag.stream_poll` for
    # the bytes produced since the previous poll, and `diag.stream_stop` ends it
    # early. `_stream_open` reuses the capture's session machinery — the detached
    # spawn, the offset read and the process-group kill — under its own registry
    # and (longer) wall-clock cap.

    @staticmethod
    def _stream_dir() -> str:
        os.makedirs(DIAG_STREAM_DIR, exist_ok=True)
        return DIAG_STREAM_DIR

    @staticmethod
    def _line_buffered(argv: list) -> list:
        """Force line buffering so each line reaches the file as it is printed.

        A command whose stdout is a pipe block-buffers, which would hold a whole
        ping until it exits and defeat the streaming. `stdbuf` restores line
        buffering; when it is not installed the argv is passed through unchanged
        rather than failing.
        """
        if STDBUF_BIN:
            return [STDBUF_BIN, "-oL", "-eL"] + argv
        return argv

    def _stream_open(self, argv: list, kind: str, host: str) -> Dict[str, Any]:
        """Start `argv` as a streamed session and hand back its id."""
        self._reap_sessions(self._streams, DIAG_STREAM_SECONDS)
        if len(self._streams) >= DIAG_STREAM_MAX_SESSIONS:
            return {"success": False,
                    "message": f"Too many running diagnostics sessions (at most {DIAG_STREAM_MAX_SESSIONS})"}

        session_id = os.urandom(8).hex()
        path = os.path.join(self._stream_dir(), session_id + ".log")
        # `timeout` is the wall-clock safety net: a probe that never answers must
        # not run until the box is rebooted.
        spawn_argv = ["timeout", str(DIAG_STREAM_SECONDS)] + self._line_buffered(argv)
        try:
            proc = self._spawn_capture(spawn_argv, path)
        except OSError as exc:
            return {"success": False, "message": f"could not start the {kind} command: {exc}"}

        self._streams[session_id] = {
            "proc": proc,
            "path": path,
            "offset": 0,
            "started": time.time(),
            "cmd": " ".join(spawn_argv),
            "closed": False,
            "returncode": None,
            "kind": kind,
            "host": host,
        }
        return {"session": session_id, "running": True, "output": "",
                "cmd": " ".join(spawn_argv), "kind": kind}

    async def rpc_diag_stream_poll(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """The output a streamed probe has produced since the previous poll."""
        session_id = str(params.get("session") or "").strip()
        session = self._streams.get(session_id)
        if session is None:
            return {"success": False, "message": "no such diagnostics session"}

        running = session["proc"].poll() is None
        output = self._session_read(session)
        if not running and session["returncode"] is None:
            session["returncode"] = session["proc"].returncode
            session["closed"] = True
            # A probe that failed and printed nothing at all (`ping` does this
            # when the name does not resolve) says so, exactly as the one-shot
            # handler does.
            if session["returncode"] != 0 and session["offset"] == 0:
                output = f'Host "{session["host"]}" did not respond or could not be resolved.\n'

        return {
            "session": session_id,
            "output": output,
            "running": running,
            "returncode": session["returncode"],
            "cmd": session["cmd"],
            "kind": session["kind"],
        }

    async def rpc_diag_stream_stop(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Stop a streamed probe and return whatever it produced last."""
        session_id = str(params.get("session") or "").strip()
        session = self._streams.get(session_id)
        if session is None:
            return {"success": False, "message": "no such diagnostics session"}

        self._session_terminate(session)
        output = self._session_read(session)
        session["closed"] = True
        return {
            "session": session_id,
            "output": output,
            "running": False,
            "returncode": session["returncode"],
            "stopped": True,
            "kind": session["kind"],
        }

    async def rpc_diag_route_lookup(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """FIB next-hop query (`diag.route_lookup`) — `ip route get`."""
        target = str(params.get("target") or "").strip()
        if not self._diag_target_ok(target):
            return {"success": False, "message": "Hostname must be a valid hostname or IP address"}

        argv = [IP_BIN, "route", "get", target]
        res = self._run(argv)
        output = (res.stdout or "") + (res.stderr or "")
        if not output.strip():
            output = f'No route to "{target}" was found.\n'

        return {"output": output, "returncode": res.returncode, "cmd": " ".join(argv)}

    @staticmethod
    def _diag_parse_routes(raw: str) -> list:
        """`ip -4 route show` into the rows the Route Lookup page renders."""
        routes: list = []
        for line in raw.splitlines():
            line = line.strip()
            if not line:
                continue
            parts = line.split()
            dest = "0.0.0.0/0" if parts[0] == "default" else parts[0]
            gateway, interface, protocol, metric = "direct", "", "kernel", 0
            for i, token in enumerate(parts):
                if i + 1 >= len(parts):
                    break
                value = parts[i + 1]
                if token == "via":
                    gateway = value
                elif token == "dev":
                    interface = value
                elif token == "proto":
                    protocol = value
                elif token == "metric" and value.isdigit():
                    metric = int(value)
            routes.append({
                "destination": dest,
                "gateway": gateway,
                "interface": interface,
                "metric": metric,
                "protocol": protocol,
                "flags": "UG" if gateway != "direct" else "U",
            })
            if len(routes) >= DIAG_ROUTE_MAX:
                break
        return routes

    async def rpc_diag_routing_table(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Kernel FIB (`diag.routing_table`)."""
        res = self._run([IP_BIN, "-4", "route", "show"])
        if res.returncode != 0 and not (res.stdout or "").strip():
            raise RuntimeError((res.stderr or "").strip() or "could not read the routing table")
        return {"routes": self._diag_parse_routes(res.stdout or "")}

    @staticmethod
    def _diag_parse_neighbors(raw: str) -> list:
        """`ip -6 neigh show` into the rows the NDP table renders."""
        neighbors: list = []
        for line in raw.splitlines():
            parts = line.split()
            if len(parts) < 4:
                continue
            neighbors.append({
                "ip": parts[0],
                "mac": parts[4] if len(parts) > 4 else "incomplete",
                "interface": parts[2] if len(parts) > 2 else "eth0",
                "state": parts[-1].upper(),
            })
            if len(neighbors) >= DIAG_ROUTE_MAX:
                break
        return neighbors

    async def rpc_diag_ndp_table(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """IPv6 neighbour table (`diag.ndp_table`)."""
        res = self._run([IP_BIN, "-6", "neigh", "show"])
        if res.returncode != 0 and not (res.stdout or "").strip():
            raise RuntimeError((res.stderr or "").strip() or "could not read the neighbour table")
        return {"neighbors": self._diag_parse_neighbors(res.stdout or "")}

    @staticmethod
    def _diag_parse_local_addrs(raw: str) -> Dict[str, str]:
        """`ip -o addr show` into {address: interface} for this appliance.

        conntrack records no interface for a flow, so the States table's
        Interface column is resolved from the addresses the appliance itself
        holds — read here once per request rather than guessed per row. A flow
        whose address is one of these (its own endpoint, or the address it was
        NATed to) runs over that interface.
        """
        addrs: Dict[str, str] = {}
        for line in raw.splitlines():
            parts = line.split()
            # `2: eth0    inet 192.168.1.1/24 brd … scope global eth0\`
            if len(parts) < 4 or parts[2] not in ("inet", "inet6"):
                continue
            iface = parts[1].strip(":")
            address = parts[3].split("/", 1)[0]
            if iface and address:
                addrs.setdefault(address, iface)
        return addrs

    @staticmethod
    def _diag_parse_conntrack(raw: str, limit: int,
                              local_addrs: Optional[Dict[str, str]] = None) -> list:
        """`conntrack -L -o extended` into the rows the States table shows.

        A line reads `tcp 6 431998 ESTABLISHED src=... dst=... sport=... ...`;
        the first `key=value` for each name is the original direction, which is
        the direction the table's columns describe.

        conntrack prints no interface, so `iface` is resolved from
        `local_addrs` (`ip -o addr show`, passed in by the caller): the first
        address of the flow that the appliance owns — in the original direction
        first, then the reply, so a flow the box SNATed is attributed to the
        interface it was translated onto. A flow with no address of ours (plain
        forwarded traffic) is left blank rather than guessed at.
        """
        addrs = local_addrs or {}
        states: list = []
        for line in raw.splitlines():
            parts = line.split()
            # `proto family ttl state …`: the family is what tells a real state
            # line from `conntrack v1.4.6 (conntrack-tools): …`.
            if len(parts) < 3 or not parts[0].isalpha() or not parts[1].isdigit():
                continue
            fields: Dict[str, str] = {}
            # Both directions' addresses, original first; only the addresses are
            # needed more than once, for the interface resolution below.
            addresses: Dict[str, list] = {"src": [], "dst": []}
            for token in parts:
                key, sep, value = token.partition("=")
                if not sep:
                    continue
                if key in addresses:
                    addresses[key].append(value)
                if key not in fields:
                    fields[key] = value
            state = next((p for p in parts if p.isalpha() and p.isupper() and len(p) > 2), "")

            def _number(key: str) -> int:
                return int(fields[key]) if fields.get(key, "").isdigit() else 0

            iface = ""
            for key in ("src", "dst"):
                for address in addresses[key]:
                    if address in addrs:
                        iface = addrs[address]
                        break
                if iface:
                    break

            states.append({
                "iface": iface,
                "proto": parts[0].lower(),
                "src": fields.get("src", ""),
                "sport": _number("sport"),
                "dst": fields.get("dst", ""),
                "dport": _number("dport"),
                "state": state or "NONE",
                "packets": _number("packets"),
                "bytes": _number("bytes"),
                "ttl": f"{parts[2]}s" if parts[2].isdigit() else "",
            })
            if len(states) >= limit:
                break
        return states

    async def rpc_diag_conntrack_states(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Connection states (`diag.conntrack_states`).

        The interface set is read alongside the states so each row can name the
        interface it runs over; `ip` missing or failing just leaves that column
        blank, it never fails the whole table.
        """
        try:
            limit = self._diag_number(params, "limit", DIAG_CONNTRACK_MAX)
        except (TypeError, ValueError):
            return {"success": False, "message": "Limit must be an integer"}
        limit = max(1, min(limit, DIAG_CONNTRACK_MAX))

        res = self._run([CONNTRACK_BIN, "-L", "-o", "extended"])
        if res.returncode != 0 and not (res.stdout or "").strip():
            raise RuntimeError((res.stderr or "").strip() or "could not read connection states")

        addrs_res = self._run([IP_BIN, "-o", "addr", "show"])
        local_addrs = self._diag_parse_local_addrs(addrs_res.stdout or "")
        return {"states": self._diag_parse_conntrack(res.stdout or "", limit, local_addrs)}

    @staticmethod
    def _diag_parse_firewall_log(lines: list, limit: int) -> list:
        """One event per `MICRON-FW:`/`MICRON-DOS-DROP:` kernel line.

        The compiler emits `log prefix ... level info` immediately before a
        rule's verdict, so a kernel line carries the packet fields and the
        prefix but not the rule id or the action. Those two columns are reported
        as unknown (0 and `log`) rather than invented.
        """
        events: list = []
        for raw in reversed(lines):
            marker = next((p for p in FIREWALL_LOG_PREFIXES if p in raw), None)
            if marker is None:
                continue
            tokens = raw.split()
            fields: Dict[str, str] = {}
            for token in tokens:
                key, sep, value = token.partition("=")
                if sep and key not in fields:
                    fields[key] = value
            matched = DIAG_LOG_TIME_RE.search(raw)
            # nftables prints TCP flags as bare `SYN`/`ACK` tokens rather than a
            # `FLAGS=` field.
            flags = [f for f in ("SYN", "ACK", "FIN", "RST", "PSH", "URG") if f in tokens]

            def _number(key: str) -> int:
                return int(fields[key]) if fields.get(key, "").isdigit() else 0

            events.append({
                "time": matched.group(1) if matched else "",
                "action": "block" if marker == "MICRON-DOS-DROP:" else "log",
                "iface": fields.get("IN", "") or fields.get("OUT", ""),
                "proto": fields.get("PROTO", "").lower(),
                "src": fields.get("SRC", ""),
                "sport": _number("SPT"),
                "dst": fields.get("DST", ""),
                "dport": _number("DPT"),
                "rule_id": 0,
                "rule": marker.rstrip(":"),
                "flags": ", ".join(flags) or "—",
            })
            if len(events) >= limit:
                break
        return events

    async def rpc_diag_firewall_logs(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Recent firewall log events (`diag.firewall_logs`).

        The nftables log lines land in the kernel ring buffer; rsyslog keeps a
        copy under /var/log on some builds, so a readable file wins and journald
        is the fallback.
        """
        try:
            limit = self._diag_number(params, "limit", 20)
        except (TypeError, ValueError):
            return {"success": False, "message": "Limit must be an integer"}
        limit = max(1, min(limit, DIAG_LOG_MAX))

        lines: Optional[list] = None
        for path in FIREWALL_LOG_FILES:
            try:
                with open(path, "r", errors="replace") as handle:
                    lines = handle.readlines()
                break
            except OSError:
                continue

        if lines is None:
            res = self._run(["journalctl", "-k", "-n", str(DIAG_LOG_MAX), "--no-pager"])
            if res.returncode != 0 and not (res.stdout or "").strip():
                raise RuntimeError((res.stderr or "").strip() or "no firewall log is available")
            lines = (res.stdout or "").splitlines()

        return {"events": self._diag_parse_firewall_log(lines, limit)}

    # ------------------ OS User Management (user.*) ------------------
    @staticmethod
    def _os_user_exists(username: str) -> bool:
        if not os.path.exists(PASSWD_FILE):
            return False
        try:
            with open(PASSWD_FILE, "r", encoding="utf-8", errors="replace") as f:
                for line in f:
                    parts = line.strip().split(":")
                    if parts and parts[0] == username:
                        return True
        except OSError:
            pass
        return False

    @staticmethod
    def _get_sudo_users() -> set:
        sudo_users = set()
        if os.path.exists(GROUP_FILE):
            try:
                with open(GROUP_FILE, "r", encoding="utf-8", errors="replace") as f:
                    for line in f:
                        parts = line.strip().split(":")
                        if len(parts) >= 4 and parts[0] in ("sudo", "wheel", "admin"):
                            for member in parts[3].split(","):
                                if member.strip():
                                    sudo_users.add(member.strip())
            except OSError:
                pass
        if os.path.isdir(SUDOERS_DIR):
            try:
                for fname in os.listdir(SUDOERS_DIR):
                    if fname.startswith("99-micron-"):
                        sudo_users.add(fname.replace("99-micron-", ""))
            except OSError:
                pass
        return sudo_users

    @classmethod
    def _set_os_password(cls, username: str, password: str) -> bool:
        if not password:
            return False
        try:
            proc = subprocess.run(
                ["chpasswd"],
                input=f"{username}:{password}\n",
                text=True,
                capture_output=True,
            )
            return proc.returncode == 0
        except OSError as exc:
            logger.warning(f"Failed to execute chpasswd for {username}: {exc}")
            return False

    @classmethod
    def _set_os_sudo(cls, username: str, is_admin: bool) -> None:
        sudo_dropin = os.path.join(SUDOERS_DIR, f"99-micron-{username}")
        if is_admin:
            cls._run(["usermod", "-aG", "sudo", username])
            try:
                os.makedirs(SUDOERS_DIR, exist_ok=True)
                safe_atomic_write(sudo_dropin, f"{username} ALL=(ALL:ALL) ALL\n", mode=0o440)
            except Exception as exc:
                logger.warning(f"Could not write sudoers drop-in for {username}: {exc}")
        else:
            if username.lower() not in PROTECTED_SYSTEM_USERS:
                cls._run(["gpasswd", "-d", username, "sudo"])
                if os.path.exists(sudo_dropin):
                    try:
                        os.remove(sudo_dropin)
                    except OSError:
                        pass

    @classmethod
    def _write_os_ssh_keys(cls, username: str, keys_text: str) -> None:
        home = f"/home/{username}"
        if username == "root":
            home = "/root"
        elif os.path.exists(PASSWD_FILE):
            try:
                with open(PASSWD_FILE, "r", encoding="utf-8", errors="replace") as f:
                    for line in f:
                        parts = line.strip().split(":")
                        if parts and parts[0] == username and len(parts) >= 6:
                            home = parts[5]
                            break
            except OSError:
                pass

        if not os.path.isdir(home):
            return

        ssh_dir = os.path.join(home, ".ssh")
        auth_keys = os.path.join(ssh_dir, "authorized_keys")
        try:
            os.makedirs(ssh_dir, mode=0o700, exist_ok=True)
            try:
                os.chmod(ssh_dir, 0o700)
            except OSError:
                pass
            safe_atomic_write(auth_keys, (keys_text.strip() + "\n") if keys_text.strip() else "", mode=0o600)
            try:
                os.chmod(auth_keys, 0o600)
            except OSError:
                pass
            try:
                import pwd
                u_entry = pwd.getpwnam(username)
                os.chown(ssh_dir, u_entry.pw_uid, u_entry.pw_gid)
                os.chown(auth_keys, u_entry.pw_uid, u_entry.pw_gid)
            except Exception:
                pass
        except Exception as exc:
            logger.warning(f"Could not configure authorized_keys for {username}: {exc}")

    async def rpc_user_list_os(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Enumerate Micron OS system accounts (UID >= 1000 + protected accounts)."""
        users = []
        if not os.path.exists(PASSWD_FILE):
            return {
                "success": True,
                "users": [
                    {
                        "username": "ministrator",
                        "uid": 1000,
                        "gid": 1000,
                        "gecos": "MicronOS Administrator",
                        "home": "/home/ministrator",
                        "shell": DEFAULT_SHELL,
                        "is_admin": True,
                        "has_ssh_keys": False,
                        "ssh_keys_count": 0,
                    },
                    {
                        "username": "root",
                        "uid": 0,
                        "gid": 0,
                        "gecos": "root",
                        "home": "/root",
                        "shell": DEFAULT_SHELL,
                        "is_admin": True,
                        "has_ssh_keys": False,
                        "ssh_keys_count": 0,
                    },
                ],
            }

        sudo_users = self._get_sudo_users()
        try:
            with open(PASSWD_FILE, "r", encoding="utf-8", errors="replace") as f:
                for line in f:
                    line = line.strip()
                    if not line or line.startswith("#"):
                        continue
                    parts = line.split(":")
                    if len(parts) >= 7:
                        uname, _, uid_str, gid_str, gecos, home, shell = parts[:7]
                        try:
                            uid = int(uid_str)
                            gid = int(gid_str)
                        except ValueError:
                            continue
                        if uid >= 1000 or uname.lower() in PROTECTED_SYSTEM_USERS:
                            has_keys = False
                            keys_count = 0
                            auth_keys_path = os.path.join(home, ".ssh", "authorized_keys")
                            if os.path.exists(auth_keys_path):
                                try:
                                    with open(auth_keys_path, "r", encoding="utf-8", errors="replace") as kf:
                                        klines = [k.strip() for k in kf if k.strip() and not k.startswith("#")]
                                        keys_count = len(klines)
                                        has_keys = keys_count > 0
                                except OSError:
                                    pass

                            is_admin = (
                                uname.lower() in ("root", "ministrator")
                                or uname in sudo_users
                                or os.path.exists(os.path.join(SUDOERS_DIR, f"99-micron-{uname}"))
                            )
                            users.append({
                                "username": uname,
                                "uid": uid,
                                "gid": gid,
                                "gecos": gecos,
                                "home": home,
                                "shell": shell,
                                "is_admin": is_admin,
                                "has_ssh_keys": has_keys,
                                "ssh_keys_count": keys_count,
                            })
        except Exception as exc:
            logger.warning(f"Error reading /etc/passwd: {exc}")

        return {"success": True, "users": users}

    async def rpc_user_create(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Create a Micron OS user and assign attributes/groups/keys."""
        username = str(params.get("username") or "").strip()
        if not OS_USER_RE.match(username):
            return {"success": False, "status": "error", "message": f"Invalid username: {username!r}"}

        if self._os_user_exists(username):
            return {"success": False, "status": "exists", "message": f"Micron OS user '{username}' already exists"}

        shell = str(params.get("shell") or DEFAULT_SHELL).strip()
        if shell not in ALLOWED_SHELLS:
            shell = DEFAULT_SHELL

        full_name = str(params.get("full_name") or username).strip()
        is_admin = bool(params.get("is_admin", False))
        password = str(params.get("password") or "")
        ssh_keys = str(params.get("ssh_keys") or "").strip()

        cmd = ["useradd", "-m", "-s", shell, "-c", full_name, username]
        res = self._run(cmd)
        if res.returncode != 0:
            return {"success": False, "status": "error", "message": f"useradd failed: {(res.stderr or res.stdout).strip()}"}

        if password:
            self._set_os_password(username, password)

        if is_admin:
            self._set_os_sudo(username, True)

        if ssh_keys:
            self._write_os_ssh_keys(username, ssh_keys)

        return {
            "success": True,
            "status": "created",
            "username": username,
            "shell": shell,
            "is_admin": is_admin,
        }

    async def rpc_user_update(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Update an existing Micron OS user's credentials, shell, full name or sudo status."""
        username = str(params.get("username") or "").strip()
        if not OS_USER_RE.match(username):
            return {"success": False, "status": "error", "message": f"Invalid username: {username!r}"}

        if not self._os_user_exists(username):
            return await self.rpc_user_create(params)

        if params.get("full_name") is not None:
            self._run(["usermod", "-c", str(params["full_name"]).strip(), username])

        if params.get("shell") is not None:
            shell_str = str(params["shell"]).strip()
            if shell_str in ALLOWED_SHELLS:
                self._run(["usermod", "-s", shell_str, username])

        password = params.get("password")
        if password:
            self._set_os_password(username, str(password))

        if "is_admin" in params:
            is_admin = bool(params["is_admin"])
            if username.lower() not in PROTECTED_SYSTEM_USERS:
                self._set_os_sudo(username, is_admin)

        if "ssh_keys" in params:
            self._write_os_ssh_keys(username, str(params["ssh_keys"]).strip())

        return {
            "success": True,
            "status": "updated",
            "username": username,
        }

    async def rpc_user_delete(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Delete a Micron OS user and remove home directory and sudo privileges."""
        username = str(params.get("username") or "").strip()
        if not username:
            return {"success": False, "status": "error", "message": "Username is required"}

        if username.lower() in PROTECTED_SYSTEM_USERS:
            return {"success": False, "status": "protected", "message": f"Protected account '{username}' cannot be deleted"}

        if not self._os_user_exists(username):
            return {"success": True, "status": "not_found", "message": f"User '{username}' does not exist on OS"}

        res = self._run(["userdel", "-r", username])
        sudo_dropin = os.path.join(SUDOERS_DIR, f"99-micron-{username}")
        if os.path.exists(sudo_dropin):
            try:
                if os.name == "nt":
                    os.chmod(sudo_dropin, 0o666)
                os.remove(sudo_dropin)
            except OSError:
                pass

        return {
            "success": res.returncode == 0 or not self._os_user_exists(username),
            "status": "deleted",
            "username": username,
        }

    async def rpc_user_sync(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Bulk reconcile Web GUI database users with Micron OS user accounts."""
        users = params.get("users") or []
        if not isinstance(users, list):
            return {"success": False, "status": "error", "message": "Users must be a list"}

        synced = []
        errors = []
        for u in users:
            if not isinstance(u, dict):
                continue
            uname = str(u.get("username") or "").strip()
            if not uname or not OS_USER_RE.match(uname):
                continue
            try:
                if not self._os_user_exists(uname):
                    res = await self.rpc_user_create(u)
                else:
                    res = await self.rpc_user_update(u)
                if res.get("success"):
                    synced.append(uname)
                else:
                    errors.append(f"{uname}: {res.get('message')}")
            except Exception as exc:
                errors.append(f"{uname}: {exc}")

        return {
            "success": len(errors) == 0,
            "status": "synced" if not errors else "partial",
            "synced": synced,
            "errors": errors,
            "count": len(synced),
        }

    # ------------------ System SSH Configuration ------------------
    async def rpc_system_ssh_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Applies system-wide OpenSSH daemon configuration to /etc/ssh/sshd_config.d/99-micronos.conf.
        Validates configuration syntax with `sshd -t` and reloads/restarts ssh.service.
        """
        enabled = bool(params.get("enabled", True))
        try:
            port = int(params.get("port", 22))
        except (ValueError, TypeError):
            port = 22
        if port < 1 or port > 65535:
            return {"success": False, "status": "error", "message": f"Invalid SSH port: {port} (must be 1-65535)"}

        # Reserved / conflicting ports check
        reserved_ports = {53, 67, 68, 8088, 8443, 4822, 7681}
        if port in reserved_ports:
            return {"success": False, "status": "error", "message": f"Port {port} is reserved for internal appliance services"}

        root_login = "yes" if bool(params.get("root_login", False)) else "no"
        try:
            raw_tries = int(params.get("max_auth_tries", 3))
            if raw_tries < 1 or raw_tries > 20:
                return {"success": False, "status": "error", "message": f"Invalid max_auth_tries: {raw_tries} (must be 1-20)"}
            max_auth_tries = raw_tries
        except (ValueError, TypeError):
            max_auth_tries = 3

        auth_methods = params.get("auth_methods", "key,password")
        if isinstance(auth_methods, list):
            auth_methods_list = [str(m).strip().lower() for m in auth_methods]
        elif isinstance(auth_methods, str):
            auth_methods_list = [m.strip().lower() for m in auth_methods.split(",") if m.strip()]
        else:
            auth_methods_list = ["key", "password"]

        pwd_auth = "yes" if "password" in auth_methods_list else "no"
        pubkey_auth = "yes" if "key" in auth_methods_list else "no"
        kbd_auth = "yes" if "keyboard-interactive" in auth_methods_list else "no"

        ssh_dropin_dir = "/etc/ssh/sshd_config.d"
        ssh_dropin = os.path.join(ssh_dropin_dir, "99-micronos.conf")

        content = (
            "# Micron OS — Managed OpenSSH Appliance Configuration\n"
            f"Port {port}\n"
            f"PasswordAuthentication {pwd_auth}\n"
            f"PubkeyAuthentication {pubkey_auth}\n"
            f"KbdInteractiveAuthentication {kbd_auth}\n"
            f"PermitRootLogin {root_login}\n"
            f"MaxAuthTries {max_auth_tries}\n"
            "Banner /etc/issue.net\n"
            "PrintMotd yes\n"
        )

        steps = []
        if os.name != "nt":
            os.makedirs(ssh_dropin_dir, exist_ok=True)
            backup = None
            if os.path.exists(ssh_dropin):
                try:
                    with open(ssh_dropin, "r", encoding="utf-8") as f:
                        backup = f.read()
                except OSError:
                    pass

            try:
                safe_atomic_write(ssh_dropin, content, mode=0o644)
                steps.append(f"wrote {ssh_dropin}")
            except Exception as exc:
                return {"success": False, "status": "error", "message": f"Failed writing SSH config: {exc}"}

            # Pre-flight syntax validation using sshd -t if available
            if self._which("sshd"):
                test_res = self._run_bounded(["sshd", "-t"], timeout=10)
                if test_res["rc"] != 0:
                    # Rollback
                    if backup is not None:
                        safe_atomic_write(ssh_dropin, backup, mode=0o644)
                    else:
                        try:
                            os.remove(ssh_dropin)
                        except OSError:
                            pass
                    return {
                        "success": False,
                        "status": "error",
                        "message": f"sshd syntax check failed: {test_res['stderr'] or test_res['stdout']}",
                    }
                steps.append("sshd -t syntax validated")

            # Manage systemd ssh unit
            if self._which("systemctl"):
                if enabled:
                    self._run_bounded(["systemctl", "enable", "ssh"], timeout=10)
                    r = self._run_bounded(["systemctl", "reload-or-restart", "ssh"], timeout=15)
                    steps.append(f"systemctl reload-or-restart ssh (rc={r['rc']})")
                else:
                    self._run_bounded(["systemctl", "stop", "ssh"], timeout=10)
                    self._run_bounded(["systemctl", "disable", "ssh"], timeout=10)
                    steps.append("systemctl stop/disable ssh")
        else:
            steps.append(f"(mock) SSH configured on port {port}, enabled={enabled}")

        return {
            "success": True,
            "status": "applied",
            "port": port,
            "enabled": enabled,
            "steps": steps,
        }

    # ------------------ Bonding & Link Aggregation (LACP) Methods ------------------
    async def rpc_bond_list(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Enumerates active Linux kernel bonding interfaces.
        Parses /proc/net/bonding/ or queries `ip -d link show type bond`.
        """
        bonds = []
        bonding_dir = "/proc/net/bonding"
        if os.path.isdir(bonding_dir):
            for fname in sorted(os.listdir(bonding_dir)):
                fpath = os.path.join(bonding_dir, fname)
                if not os.path.isfile(fpath):
                    continue
                try:
                    with open(fpath, "r", encoding="utf-8", errors="replace") as f:
                        content = f.read()
                    mode_m = re.search(r"Bonding Mode:\s*([^\n]+)", content)
                    slaves = re.findall(r"Slave Interface:\s*([^\n]+)", content)
                    active_m = re.search(r"Currently Active Slave:\s*([^\n]+)", content)
                    bonds.append({
                        "name": fname,
                        "mode": mode_m.group(1).strip() if mode_m else "unknown",
                        "slaves": [s.strip() for s in slaves],
                        "active_slave": active_m.group(1).strip() if active_m else None,
                        "raw_info": content[:1024],
                    })
                except Exception as exc:
                    logger.warning(f"Error reading bond status {fname}: {exc}")
        else:
            # Fallback to ip link
            res = self._run(["ip", "-d", "link", "show", "type", "bond"])
            if res.returncode == 0 and res.stdout:
                for line in res.stdout.splitlines():
                    m = re.search(r"^\d+:\s*([a-zA-Z0-9_.-]+):", line)
                    if m:
                        bonds.append({"name": m.group(1), "mode": "unknown", "slaves": []})
        return {"success": True, "bonds": bonds}

    async def rpc_bond_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Creates or updates a kernel bonding interface (e.g. bond0) and attaches slave interfaces.
        """
        bond_id = str(params.get("id") or "bond0").strip()
        if not re.match(r"^[a-zA-Z0-9_.-]{1,16}$", bond_id):
            return {"success": False, "message": f"Invalid bond name '{bond_id}'."}

        mode = str(params.get("mode") or "802.3ad").strip()
        valid_modes = {"802.3ad", "active-backup", "balance-xor", "balance-rr", "broadcast", "balance-tlb", "balance-alb"}
        if mode not in valid_modes:
            mode = "802.3ad"

        slaves = params.get("slaves") or []
        if isinstance(slaves, str):
            slaves = [s.strip() for s in slaves.split(",") if s.strip()]

        for s in slaves:
            if not IF_NAME_RE.match(s):
                return {"success": False, "message": f"Invalid slave interface name '{s}'."}

        lacp_rate = str(params.get("lacp_rate") or "fast").strip()
        if lacp_rate not in ("fast", "slow"):
            lacp_rate = "fast"

        hash_policy = str(params.get("xmit_hash_policy") or "layer2+3").strip()
        if hash_policy not in ("layer2", "layer2+3", "layer3+4"):
            hash_policy = "layer2+3"

        miimon = int(params.get("miimon") or 100)
        mtu = int(params.get("mtu") or 1500)

        # 1. Load bonding kernel module
        self._run(["modprobe", "bonding"])

        # 2. Check if bond already exists
        check = self._run(["ip", "link", "show", bond_id])
        if check.returncode != 0:
            # Create bond
            cmd = ["ip", "link", "add", bond_id, "type", "bond", "mode", mode, "miimon", str(miimon)]
            if mode == "802.3ad":
                cmd.extend(["lacp_rate", lacp_rate, "xmit_hash_policy", hash_policy])
            elif mode == "balance-xor":
                cmd.extend(["xmit_hash_policy", hash_policy])
            res = self._run(cmd)
            if res.returncode != 0:
                return {"success": False, "message": f"Failed to create bond {bond_id}: {res.stderr or res.stdout}"}

        # 3. Attach slaves
        for slave in slaves:
            self._run(["ip", "link", "set", slave, "down"])
            self._run(["ip", "link", "set", slave, "master", bond_id])

        # 4. Bring bond interface up
        self._run(["ip", "link", "set", bond_id, "mtu", str(mtu), "up"])

        return {
            "success": True,
            "status": "applied",
            "bond": bond_id,
            "mode": mode,
            "slaves": slaves,
            "message": f"Bond {bond_id} ({mode}) successfully provisioned with {len(slaves)} slave(s)."
        }

    async def rpc_bond_delete(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Deletes a bonding interface, unbinding all slave interfaces.
        """
        bond_id = str(params.get("id") or "").strip()
        if not re.match(r"^[a-zA-Z0-9_.-]{1,16}$", bond_id):
            return {"success": False, "message": "Invalid bond identifier."}

        # Release slaves
        bonding_file = f"/proc/net/bonding/{bond_id}"
        if os.path.isfile(bonding_file):
            try:
                with open(bonding_file, "r", encoding="utf-8", errors="replace") as f:
                    for line in f:
                        m = re.search(r"Slave Interface:\s*([^\n]+)", line)
                        if m:
                            slave = m.group(1).strip()
                            self._run(["ip", "link", "set", slave, "nomaster"])
            except Exception:
                pass

        # Delete bond device
        self._run(["ip", "link", "set", bond_id, "down"])
        res = self._run(["ip", "link", "delete", bond_id, "type", "bond"])
        return {
            "success": res.returncode == 0,
            "bond": bond_id,
            "status": "deleted" if res.returncode == 0 else "error",
            "output": res.stdout + res.stderr
        }

    # ------------------ Wireless LAN (Wi-Fi) Methods ------------------
    async def rpc_wireless_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Collects Wi-Fi telemetry: interface states, active modes, channel, station count.
        """
        iface = str(params.get("interface") or "wlan0").strip()
        if not IF_NAME_RE.match(iface):
            iface = "wlan0"

        # Check iw or iwconfig
        iw_res = self._run(["iw", "dev", iface, "info"])
        mode = "unknown"
        channel = None
        txpower = None
        ssid = None

        if iw_res.returncode == 0:
            for line in iw_res.stdout.splitlines():
                if "type " in line:
                    mode = line.split("type ", 1)[1].strip()
                elif "channel " in line:
                    m = re.search(r"channel\s+(\d+)", line)
                    if m:
                        channel = int(m.group(1))
                elif "txpower " in line:
                    txpower = line.split("txpower ", 1)[1].strip()
                elif "ssid " in line:
                    ssid = line.split("ssid ", 1)[1].strip()

        # Check hostapd stations if AP mode
        stations = []
        hostapd_cli = self._run(["hostapd_cli", "-i", iface, "all_sta"])
        if hostapd_cli.returncode == 0:
            for match in re.finditer(r"([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})", hostapd_cli.stdout):
                mac = match.group(1).lower()
                if mac not in stations:
                    stations.append(mac)

        running_hostapd = self._run(["systemctl", "is-active", f"hostapd@{iface}"]).stdout.strip() == "active" or \
                          self._run(["systemctl", "is-active", "hostapd"]).stdout.strip() == "active"

        return {
            "success": True,
            "interface": iface,
            "is_available": iw_res.returncode == 0,
            "mode": mode,
            "channel": channel,
            "txpower": txpower,
            "ssid": ssid,
            "connected_clients": len(stations),
            "client_macs": stations,
            "hostapd_active": running_hostapd,
        }

    async def rpc_wireless_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Applies Wi-Fi configuration: generates hostapd / wpa_supplicant config files and reloads services.
        """
        iface = str(params.get("interface") or "wlan0").strip()
        if not IF_NAME_RE.match(iface):
            return {"success": False, "message": f"Invalid wireless interface name '{iface}'."}

        mode = str(params.get("mode") or "ap").strip().lower()
        enabled = bool(int(params.get("enabled", 1)))
        ssid = str(params.get("ssid") or "MicronOS-WiFi").strip()
        passphrase = str(params.get("passphrase") or "").strip()
        security = str(params.get("security") or "wpa2").strip().lower()
        hw_mode = str(params.get("hw_mode") or "g").strip()
        channel = int(params.get("channel") or 6)
        country_code = str(params.get("country_code") or "US").strip().upper()[:2]
        hidden_ssid = 1 if params.get("hidden_ssid") else 0
        client_isolation = 1 if params.get("client_isolation") else 0

        if not enabled:
            # Stop wireless services on this interface
            self._run(["systemctl", "stop", f"hostapd@{iface}"])
            self._run(["systemctl", "stop", "hostapd"])
            self._run(["ip", "link", "set", iface, "down"])
            return {"success": True, "status": "disabled", "interface": iface}

        # Bring interface up
        self._run(["ip", "link", "set", iface, "up"])

        if mode == "ap":
            # Access Point mode via hostapd
            hostapd_dir = "/etc/hostapd"
            os.makedirs(hostapd_dir, exist_ok=True)
            conf_path = os.path.join(hostapd_dir, f"hostapd-{iface}.conf")

            lines = [
                f"interface={iface}",
                "driver=nl80211",
                f"ssid={ssid}",
                f"hw_mode={hw_mode}",
                f"channel={channel}",
                f"country_code={country_code}",
                "ieee80211n=1",
                "wmm_enabled=1",
                "auth_algs=1",
                f"ignore_broadcast_ssid={hidden_ssid}",
                f"ap_isolate={client_isolation}",
            ]

            if hw_mode == "a":
                lines.append("ieee80211ac=1")

            if security == "wpa3":
                lines.extend([
                    "wpa=2",
                    "wpa_key_mgmt=SAE",
                    f"sae_password={passphrase}",
                    "rsn_pairwise=CCMP",
                    "ieee80211w=2",
                ])
            elif security == "wpa2_wpa3":
                lines.extend([
                    "wpa=2",
                    "wpa_key_mgmt=WPA-PSK SAE",
                    f"wpa_passphrase={passphrase}",
                    f"sae_password={passphrase}",
                    "rsn_pairwise=CCMP",
                    "ieee80211w=1",
                ])
            elif security == "open":
                lines.append("wpa=0")
            else:  # wpa2 default
                lines.extend([
                    "wpa=2",
                    "wpa_key_mgmt=WPA-PSK",
                    f"wpa_passphrase={passphrase}",
                    "rsn_pairwise=CCMP",
                ])

            content = "\n".join(lines) + "\n"
            safe_atomic_write(conf_path, content, mode=0o600)
            safe_atomic_write("/etc/hostapd/hostapd.conf", content, mode=0o600)

            self._run(["systemctl", "restart", "hostapd"])

            return {
                "success": True,
                "status": "ap_active",
                "interface": iface,
                "ssid": ssid,
                "channel": channel,
                "security": security,
                "message": f"Wireless Access Point '{ssid}' activated on {iface}.",
            }
        else:
            # Client / Station mode via wpa_supplicant
            wpa_dir = "/etc/wpa_supplicant"
            os.makedirs(wpa_dir, exist_ok=True)
            conf_path = os.path.join(wpa_dir, f"wpa_supplicant-{iface}.conf")

            lines = [
                "ctrl_interface=/run/wpa_supplicant",
                "update_config=1",
                f"country={country_code}",
                "network={",
                f'    ssid="{ssid}"',
            ]
            if security == "open":
                lines.append("    key_mgmt=NONE")
            else:
                lines.append(f'    psk="{passphrase}"')
            lines.append("    scan_ssid=1")
            lines.append("}")

            content = "\n".join(lines) + "\n"
            safe_atomic_write(conf_path, content, mode=0o600)

            self._run(["systemctl", "restart", f"wpa_supplicant@{iface}"])

            return {
                "success": True,
                "status": "client_active",
                "interface": iface,
                "ssid": ssid,
                "message": f"Wireless Client profile '{ssid}' configured on {iface}.",
            }

    # ------------------ Captive Portal & Hotspot Voucher Engine ------------------
    async def rpc_captive_apply(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Apply captive portal nftables configuration."""
        content = params.get("ruleset", "")
        conf_path = "/etc/nftables.d/captive.conf"

        try:
            os.makedirs(os.path.dirname(conf_path), exist_ok=True)
            safe_atomic_write(conf_path, content, mode=0o644)

            # Test ruleset syntax via nft -c
            test_proc = await asyncio.create_subprocess_exec(
                "nft", "-c", "-f", conf_path,
                stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
            )
            stdout, stderr = await asyncio.wait_for(test_proc.communicate(), timeout=10)

            if test_proc.returncode != 0:
                err_msg = stderr.decode(errors="replace").strip()
                return {"success": False, "status": "syntax_error", "error": err_msg}

            # Commit ruleset to kernel
            load_proc = await asyncio.create_subprocess_exec(
                "nft", "-f", conf_path,
                stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
            )
            await asyncio.wait_for(load_proc.communicate(), timeout=10)

            return {
                "success": True,
                "status": "applied",
                "message": "Captive Portal nftables rules committed successfully.",
            }
        except FileNotFoundError:
            # nftables binary not present (e.g. mock/Windows dev)
            return {"success": True, "status": "simulated", "message": "Simulated captive apply (nft not installed)."}
        except Exception as exc:
            return {"success": False, "status": "error", "error": str(exc)}

    async def rpc_captive_authorize(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Dynamically add client IP and MAC into kernel timeout sets."""
        tag = params.get("zone_tag", "default")
        ip = params.get("ip", "")
        mac = params.get("mac", "")
        timeout_sec = int(params.get("timeout_seconds", 3600))

        errors = []
        try:
            if ip:
                ip_cmd = ["nft", "add", "element", "inet", "filter", f"captive_ip_{tag}", f"{{ {ip} timeout {timeout_sec}s }}"]
                proc = await asyncio.create_subprocess_exec(*ip_cmd, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE)
                _, stderr = await asyncio.wait_for(proc.communicate(), timeout=5)
                if proc.returncode != 0:
                    errors.append(stderr.decode(errors="replace").strip())

            if mac:
                mac_cmd = ["nft", "add", "element", "inet", "filter", f"captive_mac_{tag}", f"{{ {mac} timeout {timeout_sec}s }}"]
                proc = await asyncio.create_subprocess_exec(*mac_cmd, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE)
                _, stderr = await asyncio.wait_for(proc.communicate(), timeout=5)
                if proc.returncode != 0:
                    errors.append(stderr.decode(errors="replace").strip())

            return {
                "success": len(errors) == 0,
                "status": "authorized" if not errors else "partial",
                "ip": ip,
                "mac": mac,
                "errors": errors,
            }
        except FileNotFoundError:
            return {"success": True, "status": "simulated", "ip": ip, "mac": mac}
        except Exception as exc:
            return {"success": False, "status": "error", "error": str(exc)}

    async def rpc_captive_revoke(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Remove client IP/MAC from kernel sets and flush conntrack entries."""
        tag = params.get("zone_tag", "default")
        ip = params.get("ip", "")
        mac = params.get("mac", "")

        errors = []
        try:
            if ip:
                ip_cmd = ["nft", "delete", "element", "inet", "filter", f"captive_ip_{tag}", f"{{ {ip} }}"]
                proc = await asyncio.create_subprocess_exec(*ip_cmd, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE)
                _, stderr = await asyncio.wait_for(proc.communicate(), timeout=5)
                if proc.returncode != 0:
                    errors.append(stderr.decode(errors="replace").strip())

                # Flush active conntrack sessions
                ct_proc = await asyncio.create_subprocess_exec("conntrack", "-D", "-s", ip, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL)
                await asyncio.wait_for(ct_proc.communicate(), timeout=5)

            if mac:
                mac_cmd = ["nft", "delete", "element", "inet", "filter", f"captive_mac_{tag}", f"{{ {mac} }}"]
                proc = await asyncio.create_subprocess_exec(*mac_cmd, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE)
                _, stderr = await asyncio.wait_for(proc.communicate(), timeout=5)
                if proc.returncode != 0:
                    errors.append(stderr.decode(errors="replace").strip())

            return {
                "success": True,
                "status": "revoked",
                "ip": ip,
                "mac": mac,
                "errors": errors,
            }
        except FileNotFoundError:
            return {"success": True, "status": "simulated", "ip": ip, "mac": mac}
        except Exception as exc:
            return {"success": False, "status": "error", "error": str(exc)}

    async def rpc_captive_status(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Query kernel set counts and captive portal daemon telemetry."""
        tag = params.get("zone_tag", "default")
        try:
            ip_count = 0
            proc = await asyncio.create_subprocess_exec(
                "nft", "list", "set", "inet", "filter", f"captive_ip_{tag}",
                stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL
            )
            stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=5)
            if proc.returncode == 0:
                output = stdout.decode(errors="replace")
                elements_block = re.search(r"elements\s*=\s*\{([^}]*)\}", output)
                if elements_block:
                    ip_count = len([x for x in elements_block.group(1).split(",") if x.strip()])

            return {
                "success": True,
                "zone_tag": tag,
                "active_authorized_ips": ip_count,
            }
        except Exception:
            return {"success": True, "zone_tag": tag, "active_authorized_ips": 0}




async def _write_response(writer: asyncio.StreamWriter, response_data: Any) -> None:
    """Frames and writes a JSON-RPC response.

    `allow_nan=False` keeps the daemon from ever putting NaN/Infinity on the
    wire: a handler that somehow produced one becomes an internal error rather
    than a frame the peer's strict parser cannot read.
    """
    try:
        encoded = json.dumps(response_data, allow_nan=False).encode("utf-8")
    except (ValueError, TypeError) as exc:
        logger.error(f"Response is not serialisable as strict JSON: {exc}")
        req_id = response_data.get("id") if isinstance(response_data, dict) else None
        encoded = json.dumps({
            "jsonrpc": "2.0",
            "id": req_id,
            "error": {"code": -32603, "message": "Internal error: response is not valid JSON"},
        }).encode("utf-8")

    writer.write(struct.pack("!I", len(encoded)) + encoded)
    await writer.drain()


def _accepts_peer(handler) -> bool:
    """Whether handler.handle_request takes the caller identity (RpcHandler does)."""
    try:
        import inspect
        return "peer" in inspect.signature(handler.handle_request).parameters
    except (TypeError, ValueError):
        return False


async def handle_client(reader: asyncio.StreamReader, writer: asyncio.StreamWriter, handler: RpcHandler):
    """Processes length-prefixed JSON-RPC requests on a client connection."""
    # The caller's kernel-attested identity, read once per connection; it
    # decides which method classes this connection may invoke.
    peer = rpc_peer_credentials(writer)
    try:
        while True:
            # Read 4-byte header
            header = await reader.readexactly(4)
            if not header:
                break
            msg_len = struct.unpack("!I", header)[0]
            if msg_len > 10 * 1024 * 1024:  # 10MB safety cap
                logger.error(f"Payload size {msg_len} exceeds max allowed 10MB.")
                break

            payload = await reader.readexactly(msg_len)
            try:
                # Strict: a payload carrying NaN/Infinity (or any malformed JSON)
                # is refused at the boundary, never handed to a handler.
                req_data = strict_json_loads(payload.decode("utf-8"))
            except (ValueError, UnicodeDecodeError) as exc:
                logger.warning(f"Rejected malformed JSON-RPC request: {exc}")
                await _write_response(writer, {
                    "jsonrpc": "2.0",
                    "id": None,
                    "error": {"code": -32700, "message": f"Parse error: {exc}"},
                })
                continue

            response_data = await (handler.handle_request(req_data, peer) if _accepts_peer(handler)
                                   else handler.handle_request(req_data))
            await _write_response(writer, response_data)
    except asyncio.IncompleteReadError:
        pass
    except Exception as e:
        logger.warning(f"Client connection closed with error: {e}")
    finally:
        writer.close()
        await writer.wait_closed()


def notify_systemd(state: str) -> bool:
    """Sends state notifications (READY=1, WATCHDOG=1, STOPPING=1) to systemd via NOTIFY_SOCKET."""
    notify_socket = os.environ.get("NOTIFY_SOCKET")
    if not notify_socket:
        return False
    try:
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
        if notify_socket.startswith("@"):
            notify_socket = "\0" + notify_socket[1:]
        sock.connect(notify_socket)
        sock.sendall(state.encode("utf-8"))
        sock.close()
        return True
    except Exception as exc:
        logger.debug(f"systemd notify failed: {exc}")
        return False


async def _systemd_watchdog_loop():
    """Periodically notifies systemd watchdog that the event loop is active and processing."""
    watchdog_usec = os.environ.get("WATCHDOG_USEC")
    if not watchdog_usec:
        return
    try:
        interval = max(1.0, float(watchdog_usec) / 2_000_000)
    except ValueError:
        interval = 15.0

    while True:
        await asyncio.sleep(interval)
        notify_systemd("WATCHDOG=1")


async def _database_watchdog_loop(handler: "RpcHandler"):
    """Periodically verifies database integrity and maintains WAL checkpoints."""
    while True:
        await asyncio.sleep(300)
        try:
            handler.maintain_database()
        except Exception as exc:
            logger.error(f"Periodic database maintenance failed: {exc}")


# Per-appliance master key. The web application derives its encryption key
# from it (Config\Encryption) and encrypted backups carry it, so stored
# secrets (PPPoE, RADIUS, connectors) survive a restore onto new hardware.
MASTER_KEY_PATH = "/etc/micron/secrets.key"


def ensure_master_key(path: Optional[str] = None) -> bool:
    """Create the appliance master key on first start; never replace one.

    Written as 64 hex characters, mode 0640, root:www-data, so the web
    application (www-data) can read it and nobody else can. Returns True when
    a new key was created.
    """
    path = path or MASTER_KEY_PATH
    if os.path.exists(path):
        return False
    safe_atomic_write(path, secrets.token_hex(32) + "\n", mode=0o640)
    try:
        import grp
        os.chown(path, 0, grp.getgrnam("www-data").gr_gid)
    except (KeyError, PermissionError, OSError, ImportError):
        pass
    return True


async def main():
    os.makedirs(os.path.dirname(SOCKET_PATH), exist_ok=True)
    try:
        if ensure_master_key():
            logger.info(f"generated appliance master key at {MASTER_KEY_PATH}")
    except Exception as exc:  # never keep the daemon from starting
        logger.error(f"could not create the appliance master key: {exc}")
    if os.path.exists(SOCKET_PATH):
        os.remove(SOCKET_PATH)

    loop = asyncio.get_running_loop()
    tx_mgr = SafeTransactionManager()
    handler = RpcHandler(tx_mgr, loop)
    try:
        recovered = handler.net_recover_on_start()
        if recovered:
            logger.warning(f"network transaction left from before start: {recovered}")
    except Exception as exc:  # never keep the daemon from starting
        logger.error(f"network recovery failed: {exc}")

    server = await asyncio.start_unix_server(
        lambda r, w: handle_client(r, w, handler),
        path=SOCKET_PATH
    )

    # Set socket permissions so www-data (PHP-FPM/Apache) can read and write
    try:
        import shutil
        import grp
        os.chmod(SOCKET_PATH, 0o660)
        try:
            gid = grp.getgrnam("www-data").gr_gid
            os.chown(SOCKET_PATH, -1, gid)
        except Exception:
            pass
    except Exception as e:
        logger.warning(f"Could not adjust socket permissions: {e}")

    logger.info(f"MicronOS IPC Daemon (micrond) running on {SOCKET_PATH}")

    # Signal service readiness and launch watchdog keepalive and database maintenance loops
    notify_systemd("READY=1")
    watchdog_task = asyncio.create_task(_systemd_watchdog_loop())
    db_watchdog_task = asyncio.create_task(_database_watchdog_loop(handler))

    try:
        async with server:
            await server.serve_forever()
    finally:
        watchdog_task.cancel()
        db_watchdog_task.cancel()
        notify_systemd("STOPPING=1")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except (KeyboardInterrupt, SystemExit):
        logger.info("micrond shutdown initiated.")
        if os.path.exists(SOCKET_PATH):
            os.remove(SOCKET_PATH)

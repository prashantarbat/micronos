#!/usr/bin/env python3
"""
Micron OS — network configuration engine (shared by micrond and boot).

One implementation of "make the kernel match the committed network plan",
used by:

  * micrond's `network.apply` / `network.confirm` / `system.ipv6.set` RPCs
    (Web UI, `php spark network:apply`, `microctl interface set-lan`), and
  * `micron-network` at boot (`micron_netcfg.py boot <plan>`), so a reboot
    restores exactly what was last committed — never an older address left in
    /etc/network/interfaces.

The plan is produced by the management plane from the configuration database
(App\\Libraries\\Network\\NetworkPlan) and is the only input:

    {
      "version": 1,
      "ipv6_enabled": false,
      "interfaces": [
        {"id": "wan", "role": "wan", "device": "enp1s0", "enabled": true,
         "mode": "dhcp" | "static" | "pppoe" | "none",
         "ipv4": "203.0.113.10/24" | null, "gateway": "203.0.113.1" | null,
         "mtu": 1500,
         "ipv6_mode": "none" | "static" | "slaac" | "dhcp6",
         "ipv6": "2001:db8::1/64" | null}
      ]
    }

Every function takes an injectable `run(argv) -> CompletedProcess` so the
engine is tested without touching the host. Nothing is ever passed through a
shell; every value that reaches an argv has been validated first.
"""
from __future__ import annotations

import ipaddress
import json
import os
import re
import subprocess
import sys
import tempfile
import time
from typing import Any, Callable, Dict, List, Optional, Tuple

PLAN_PATH = "/etc/micron/network.json"
IPV6_SYSCTL_PATH = "/etc/sysctl.d/60-micron-ipv6.conf"
IFUPDOWN_PATH = "/etc/network/interfaces"
IFUPDOWN_BACKUP = "/etc/network/interfaces.micron-orig"
DHCLIENT_PID = "/run/dhclient.{dev}.pid"
DHCLIENT6_PID = "/run/dhclient6.{dev}.pid"

DEVICE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$")
ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,32}$")
MODES = ("static", "dhcp", "pppoe", "none")
V6_MODES = ("none", "static", "slaac", "dhcp6")
ROLES = ("wan", "lan", "opt", "mgmt", "dmz")

Run = Callable[[List[str]], "subprocess.CompletedProcess"]


def default_run(argv: List[str]) -> "subprocess.CompletedProcess":
    try:
        return subprocess.run(argv, capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.SubprocessError) as exc:
        return subprocess.CompletedProcess(argv, 127, "", str(exc))


# --------------------------------------------------------------------------
# Validation
# --------------------------------------------------------------------------
def validate_plan(plan: Any) -> Tuple[Optional[Dict[str, Any]], List[str]]:
    """Return (normalised plan, errors). Nothing is applied when errors exist."""
    errors: List[str] = []
    if not isinstance(plan, dict):
        return None, ["plan must be an object"]
    ifaces_in = plan.get("interfaces")
    if not isinstance(ifaces_in, list) or not ifaces_in:
        return None, ["plan.interfaces must be a non-empty list"]

    out: Dict[str, Any] = {"version": 1, "ipv6_enabled": bool(plan.get("ipv6_enabled", False)), "interfaces": []}
    seen_dev: Dict[str, str] = {}
    seen_id: set = set()
    nets: List[Tuple[str, ipaddress.IPv4Network]] = []
    gateways: List[str] = []

    for raw in ifaces_in:
        if not isinstance(raw, dict):
            errors.append("each interface must be an object")
            continue
        iid = str(raw.get("id", ""))
        if not ID_RE.fullmatch(iid):
            errors.append(f"invalid interface id: {iid[:40]!r}")
            continue
        if iid in seen_id:
            errors.append(f"duplicate interface id: {iid}")
            continue
        seen_id.add(iid)
        dev = str(raw.get("device", ""))
        if not DEVICE_RE.fullmatch(dev):
            errors.append(f"{iid}: invalid kernel device name {dev[:40]!r}")
            continue
        enabled = bool(raw.get("enabled", True))
        if enabled and dev in seen_dev:
            errors.append(f"{iid}: device {dev} is already used by {seen_dev[dev]}")
            continue
        if enabled:
            seen_dev[dev] = iid
        role = str(raw.get("role", "opt")).lower()
        if role not in ROLES:
            role = "opt"
        mode = str(raw.get("mode", "none")).lower()
        if mode not in MODES:
            errors.append(f"{iid}: unknown address mode {mode!r}")
            continue
        try:
            mtu = int(raw.get("mtu") or 1500)
        except (TypeError, ValueError):
            mtu = 0
        if not 576 <= mtu <= 9216:
            errors.append(f"{iid}: MTU must be 576-9216")
            continue

        ipv4 = None
        gateway = None
        if mode == "static":
            try:
                if "/" not in str(raw.get("ipv4") or ""):
                    raise ValueError("prefix required")
                iface = ipaddress.IPv4Interface(str(raw.get("ipv4") or ""))
            except ValueError:
                errors.append(f"{iid}: static mode needs an IPv4 address with prefix (a.b.c.d/nn)")
                continue
            net = iface.network
            if net.prefixlen < 8:
                errors.append(f"{iid}: prefix /{net.prefixlen} is too large for an interface")
                continue
            if net.prefixlen < 31 and iface.ip in (net.network_address, net.broadcast_address):
                errors.append(f"{iid}: {iface.ip} is the network or broadcast address of {net}")
                continue
            if iface.ip.is_loopback or iface.ip.is_multicast or iface.ip.is_unspecified:
                errors.append(f"{iid}: {iface.ip} cannot be assigned to an interface")
                continue
            if enabled:
                for other_id, other_net in nets:
                    if net.overlaps(other_net):
                        errors.append(f"{iid}: subnet {net} overlaps {other_id} ({other_net})")
                nets.append((iid, net))
            ipv4 = str(iface)
            gw = str(raw.get("gateway") or "").strip()
            if gw:
                try:
                    gw_ip = ipaddress.IPv4Address(gw)
                except ValueError:
                    errors.append(f"{iid}: gateway {gw[:40]!r} is not an IPv4 address")
                    continue
                if gw_ip not in net:
                    errors.append(f"{iid}: gateway {gw_ip} is outside {net}")
                    continue
                if gw_ip == iface.ip:
                    errors.append(f"{iid}: gateway cannot be the interface's own address")
                    continue
                gateway = str(gw_ip)
                if enabled:
                    gateways.append(iid)

        v6mode = str(raw.get("ipv6_mode") or "none").lower()
        if v6mode not in V6_MODES:
            v6mode = "none"
        ipv6 = None
        if v6mode == "static":
            try:
                if "/" not in str(raw.get("ipv6") or ""):
                    raise ValueError("prefix required")
                ipv6 = str(ipaddress.IPv6Interface(str(raw.get("ipv6") or "")))
            except ValueError:
                errors.append(f"{iid}: static IPv6 needs an address with prefix (2001:db8::1/64)")
                continue

        out["interfaces"].append({
            "id": iid, "role": role, "device": dev, "enabled": enabled, "mode": mode,
            "ipv4": ipv4, "gateway": gateway, "mtu": mtu, "ipv6_mode": v6mode, "ipv6": ipv6,
        })

    if len(gateways) > 1:
        errors.append("only one interface may carry a static default gateway "
                      f"(found on {', '.join(gateways)}); use gateway groups for multi-WAN")
    if errors:
        return None, errors
    return out, []


# --------------------------------------------------------------------------
# Kernel state helpers
# --------------------------------------------------------------------------
def _reject_constant(name: str) -> Any:
    raise ValueError(f"non-standard JSON constant {name}")


def loads(text: str) -> Any:
    """Strict JSON: NaN / Infinity are refused, like micrond's decoder."""
    return json.loads(text, parse_constant=_reject_constant)


def _json(res: "subprocess.CompletedProcess") -> Any:
    try:
        return loads(res.stdout or "null")
    except ValueError:
        return None


def device_exists(dev: str) -> bool:
    return os.path.isdir(f"/sys/class/net/{dev}")


def current_ipv4(run: Run, dev: str) -> List[str]:
    data = _json(run(["ip", "-j", "-4", "addr", "show", "dev", dev])) or []
    out = []
    for link in data if isinstance(data, list) else []:
        for a in link.get("addr_info", []) or []:
            if a.get("family") == "inet" and a.get("scope") == "global" and not a.get("dynamic"):
                out.append(f"{a.get('local')}/{a.get('prefixlen')}")
    return out


def current_ipv4_all(run: Run, dev: str) -> List[Dict[str, Any]]:
    data = _json(run(["ip", "-j", "-4", "addr", "show", "dev", dev])) or []
    out = []
    for link in data if isinstance(data, list) else []:
        for a in link.get("addr_info", []) or []:
            if a.get("family") == "inet" and a.get("scope") == "global":
                out.append({"cidr": f"{a.get('local')}/{a.get('prefixlen')}", "dynamic": bool(a.get("dynamic"))})
    return out


def current_ipv6_global(run: Run, dev: str) -> List[str]:
    data = _json(run(["ip", "-j", "-6", "addr", "show", "dev", dev, "scope", "global"])) or []
    out = []
    for link in data if isinstance(data, list) else []:
        for a in link.get("addr_info", []) or []:
            if a.get("family") == "inet6" and not a.get("dynamic"):
                out.append(f"{a.get('local')}/{a.get('prefixlen')}")
    return out


def default_routes(run: Run) -> List[Dict[str, str]]:
    data = _json(run(["ip", "-j", "-4", "route", "show", "default"])) or []
    out = []
    for r in data if isinstance(data, list) else []:
        if r.get("gateway"):
            out.append({"gateway": str(r["gateway"]), "dev": str(r.get("dev", "")),
                        "protocol": str(r.get("protocol", ""))})
    return out


def read_text(path: str) -> Optional[str]:
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return fh.read()
    except OSError:
        return None


def write_atomic(path: str, content: str, mode: int = 0o644) -> None:
    directory = os.path.dirname(path) or "."
    os.makedirs(directory, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=directory, prefix=".micron-", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(content)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, path)
    except BaseException:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


# --------------------------------------------------------------------------
# IPv6 (single authority: plan.ipv6_enabled)
# --------------------------------------------------------------------------
def render_ipv6_sysctl(enabled: bool, plan_ifaces: List[Dict[str, Any]]) -> str:
    """Persistent sysctl drop-in. `all` and `default` cover existing and future
    devices; each managed device is listed too because a device's own
    `disable_ipv6` survives later writes to `default`."""
    val = 0 if enabled else 1
    lines = [
        "# Micron OS — IPv6 master switch (System > Networking). Generated; do not edit.",
        f"# IPv6 is {'ENABLED' if enabled else 'DISABLED'} appliance-wide.",
        f"net.ipv6.conf.all.disable_ipv6 = {val}",
        f"net.ipv6.conf.default.disable_ipv6 = {val}",
        f"net.ipv6.conf.lo.disable_ipv6 = {val}",
    ]
    for i in plan_ifaces:
        dev = i["device"]
        if "." in dev or ":" in dev:
            continue  # sysctl keys use '/' for VLANs; covered by all/default
        lines.append(f"net.ipv6.conf.{dev}.disable_ipv6 = {val}")
    if enabled:
        lines += [
            "net.ipv6.conf.all.forwarding = 1",
            "net.ipv6.conf.default.forwarding = 1",
        ]
    return "\n".join(lines) + "\n"


def _sysctl_key_dev(dev: str) -> str:
    return dev.replace(".", "/")


def apply_ipv6(run: Run, plan: Dict[str, Any], steps: List[str], warnings: List[str],
               sysctl_path: Optional[str] = None) -> None:
    sysctl_path = sysctl_path or IPV6_SYSCTL_PATH
    enabled = plan["ipv6_enabled"]
    val = "0" if enabled else "1"
    write_atomic(sysctl_path, render_ipv6_sysctl(enabled, plan["interfaces"]))
    steps.append(f"wrote {sysctl_path}")
    keys = ["net.ipv6.conf.all.disable_ipv6", "net.ipv6.conf.default.disable_ipv6", "net.ipv6.conf.lo.disable_ipv6"]
    for i in plan["interfaces"]:
        if device_exists(i["device"]):
            keys.append(f"net.ipv6.conf.{_sysctl_key_dev(i['device'])}.disable_ipv6")
    for key in keys:
        res = run(["sysctl", "-q", "-w", f"{key}={val}"])
        if res.returncode != 0:
            warnings.append(f"sysctl {key} failed: {(res.stderr or '').strip()[:120]}")
    if enabled:
        run(["sysctl", "-q", "-w", "net.ipv6.conf.all.forwarding=1"])
        for i in plan["interfaces"]:
            if not device_exists(i["device"]) or not i["enabled"]:
                continue
            dev = _sysctl_key_dev(i["device"])
            # A forwarding router ignores RAs unless accept_ra=2; only the WAN
            # side should learn a default route from them.
            ra = "2" if i["role"] == "wan" and i["ipv6_mode"] in ("slaac", "dhcp6") else "0"
            run(["sysctl", "-q", "-w", f"net.ipv6.conf.{dev}.accept_ra={ra}"])
            if i["ipv6_mode"] == "static" and i["ipv6"]:
                run(["ip", "-6", "addr", "replace", i["ipv6"], "dev", i["device"]])
                steps.append(f"{i['device']}: ipv6 {i['ipv6']}")
            if i["ipv6_mode"] == "dhcp6" and i["role"] == "wan":
                _ensure_dhclient(run, i["device"], v6=True, steps=steps)
        steps.append("ipv6 enabled")
    else:
        # The kernel removes every IPv6 address when disable_ipv6=1; DHCPv6
        # clients would only log errors, so they are stopped.
        for i in plan["interfaces"]:
            _stop_dhclient(run, i["device"], v6=True)
        steps.append("ipv6 disabled (addresses removed by the kernel)")


def effective_ipv6(read: Callable[[str], Optional[str]] = read_text) -> Optional[bool]:
    """What the kernel is doing right now (None when IPv6 is compiled out)."""
    v = read("/proc/sys/net/ipv6/conf/all/disable_ipv6")
    if v is None:
        return None
    return v.strip() == "0"


# --------------------------------------------------------------------------
# DHCP clients
# --------------------------------------------------------------------------
def _pid_alive(path: str) -> bool:
    try:
        pid = int((read_text(path) or "").strip())
        os.kill(pid, 0)
        return True
    except (ValueError, OSError):
        return False


def _ensure_dhclient(run: Run, dev: str, v6: bool, steps: List[str]) -> None:
    pidf = (DHCLIENT6_PID if v6 else DHCLIENT_PID).format(dev=dev)
    if _pid_alive(pidf):
        return
    argv = ["dhclient", "-6" if v6 else "-4", "-nw", "-pf", pidf, dev]
    res = run(argv)
    steps.append(f"{dev}: dhclient {'-6' if v6 else '-4'} rc={res.returncode}")


def _stop_dhclient(run: Run, dev: str, v6: bool) -> None:
    pidf = (DHCLIENT6_PID if v6 else DHCLIENT_PID).format(dev=dev)
    if os.path.exists(pidf):
        run(["dhclient", "-6" if v6 else "-4", "-x", "-pf", pidf, dev])


# --------------------------------------------------------------------------
# ifupdown: the installer's static stanzas must never restore an old address
# --------------------------------------------------------------------------
def neutralize_ifupdown(text: str, managed: List[str]) -> str:
    """Turn `iface <managed> inet static|dhcp` stanzas into `inet manual` and
    drop their address lines, keeping bridge/bond options so the device itself
    is still created at boot. Idempotent."""
    out: List[str] = []
    in_managed = False
    for line in text.splitlines():
        stripped = line.strip()
        m = re.match(r"^iface\s+(\S+)\s+inet6?\s+(\S+)", stripped)
        if m:
            in_managed = m.group(1) in managed
            if in_managed and stripped.split()[2] == "inet6":
                continue  # IPv6 is configured by the plan
            if in_managed and m.group(2) in ("static", "dhcp"):
                out.append(f"iface {m.group(1)} inet manual  # addressing managed by Micron OS ({PLAN_PATH})")
                continue
            out.append(line)
            continue
        if re.match(r"^(auto|allow-hotplug|allow-auto|iface|mapping|source|source-directory)\b", stripped):
            in_managed = False
        if in_managed and re.match(r"^(address|netmask|gateway|broadcast|network|dns-\S+|hwaddress|metric|pointopoint)\b", stripped):
            continue
        out.append(line)
    result = "\n".join(out)
    return result + ("\n" if not result.endswith("\n") else "")


def apply_ifupdown(plan: Dict[str, Any], steps: List[str], path: Optional[str] = None,
                   backup: Optional[str] = None) -> None:
    path = path or IFUPDOWN_PATH
    backup = backup or IFUPDOWN_BACKUP
    text = read_text(path)
    if text is None:
        return
    managed = [i["device"] for i in plan["interfaces"]]
    new = neutralize_ifupdown(text, managed)
    if new != text:
        if not os.path.exists(backup):
            write_atomic(backup, text)
        write_atomic(path, new)
        steps.append(f"{path}: addressing for {', '.join(managed)} handed to Micron OS")


# --------------------------------------------------------------------------
# Apply / snapshot / restore / verify
# --------------------------------------------------------------------------
def snapshot(run: Run, devices: List[str], plan_path: Optional[str] = None,
             sysctl_path: Optional[str] = None) -> Dict[str, Any]:
    plan_path = plan_path or PLAN_PATH
    sysctl_path = sysctl_path or IPV6_SYSCTL_PATH
    snap: Dict[str, Any] = {"taken_at": time.time(), "addresses": {}, "ipv6": {},
                            "routes": default_routes(run),
                            "plan": read_text(plan_path), "sysctl": read_text(sysctl_path),
                            "ipv6_enabled": effective_ipv6()}
    for dev in devices:
        if device_exists(dev):
            snap["addresses"][dev] = current_ipv4(run, dev)
            snap["ipv6"][dev] = current_ipv6_global(run, dev)
    return snap


def apply_plan(run: Run, plan: Dict[str, Any], previous: Optional[Dict[str, Any]] = None,
               plan_path: Optional[str] = None, sysctl_path: Optional[str] = None,
               ifupdown_path: Optional[str] = None, ifupdown_backup: Optional[str] = None,
               persist: bool = True) -> Dict[str, Any]:
    """Make the kernel match `plan`. Returns {"steps", "warnings", "errors"}."""
    plan_path = plan_path or PLAN_PATH
    steps: List[str] = []
    warnings: List[str] = []
    errors: List[str] = []
    prev_by_id = {i["id"]: i for i in (previous or {}).get("interfaces", [])}
    current_by_id = {i["id"]: i for i in plan["interfaces"]}

    apply_ifupdown(plan, steps, ifupdown_path, ifupdown_backup)

    for iface_id, prev in prev_by_id.items():
        current = current_by_id.get(iface_id)
        old_dev = prev.get("device", "")
        if (current and current.get("device") == old_dev) or not device_exists(old_dev):
            continue
        _stop_dhclient(run, old_dev, v6=False)
        old_address = prev.get("ipv4") if prev.get("mode") == "static" else None
        if old_address and old_address in [a["cidr"] for a in current_ipv4_all(run, old_dev)]:
            res = run(["ip", "addr", "del", old_address, "dev", old_dev])
            if res.returncode != 0:
                errors.append(f"{old_dev}: could not remove previous {iface_id} address {old_address}: {(res.stderr or '').strip()[:160]}")
            else:
                steps.append(f"{old_dev}: removed previous {iface_id} address {old_address}")

    wanted_gateway: Optional[Tuple[str, str]] = None
    for i in plan["interfaces"]:
        dev = i["device"]
        if not device_exists(dev):
            warnings.append(f"{i['id']}: device {dev} is not present; addressing will apply when it appears")
            continue
        if not i["enabled"]:
            for a in current_ipv4_all(run, dev):
                run(["ip", "addr", "del", a["cidr"], "dev", dev])
            run(["ip", "link", "set", "dev", dev, "down"])
            steps.append(f"{dev}: down (interface disabled)")
            continue
        run(["ip", "link", "set", "dev", dev, "mtu", str(i["mtu"]), "up"])

        prev = prev_by_id.get(i["id"])
        if i["mode"] == "static":
            _stop_dhclient(run, dev, v6=False)
            have = current_ipv4_all(run, dev)
            want = i["ipv4"]
            for a in have:
                if a["cidr"] != want:
                    run(["ip", "addr", "del", a["cidr"], "dev", dev])
                    steps.append(f"{dev}: removed {a['cidr']}")
            for other in [o.get("device") for o in plan["interfaces"] if o.get("device") and o.get("device") != dev]:
                if device_exists(other):
                    for a in current_ipv4_all(run, other):
                        if a["cidr"] == want:
                            run(["ip", "addr", "del", want, "dev", other])
                            steps.append(f"{other}: removed lingering {want}")
            res = run(["ip", "addr", "replace", want, "dev", dev])
            if res.returncode != 0:
                errors.append(f"{dev}: could not assign {want}: {(res.stderr or '').strip()[:160]}")
            else:
                steps.append(f"{dev}: {want}")
            if i["gateway"]:
                wanted_gateway = (i["gateway"], dev)
        elif i["mode"] == "dhcp":
            # Remove an address Micron assigned statically before; leases stay.
            if prev and prev.get("mode") == "static" and prev.get("ipv4"):
                run(["ip", "addr", "del", prev["ipv4"], "dev", dev])
                steps.append(f"{dev}: removed static {prev['ipv4']}")
            _ensure_dhclient(run, dev, v6=False, steps=steps)
        elif i["mode"] == "none":
            _stop_dhclient(run, dev, v6=False)
            for a in current_ipv4_all(run, dev):
                run(["ip", "addr", "del", a["cidr"], "dev", dev])
        # pppoe: the PPP link (pppN) owns the address; nothing to do here.

    # Default route: a static gateway replaces whatever is there; a gateway
    # that was static before and is gone now is removed.
    if wanted_gateway:
        gw, dev = wanted_gateway
        res = run(["ip", "route", "replace", "default", "via", gw, "dev", dev])
        if res.returncode != 0:
            errors.append(f"default route via {gw}: {(res.stderr or '').strip()[:160]}")
        else:
            steps.append(f"default via {gw} dev {dev}")
    else:
        for p in (previous or {}).get("interfaces", []):
            if p.get("gateway"):
                run(["ip", "route", "del", "default", "via", p["gateway"]])
                steps.append(f"removed static default via {p['gateway']}")

    try:
        apply_ipv6(run, plan, steps, warnings, sysctl_path)
    except OSError as exc:
        errors.append(f"ipv6: {exc}")

    if persist and not errors:
        write_atomic(plan_path, json.dumps(plan, indent=2, sort_keys=True) + "\n")
        steps.append(f"committed {plan_path}")
    return {"steps": steps, "warnings": warnings, "errors": errors}


def verify(run: Run, plan: Dict[str, Any]) -> List[str]:
    problems: List[str] = []
    for i in plan["interfaces"]:
        dev = i["device"]
        if not i["enabled"] or not device_exists(dev):
            continue
        if i["mode"] == "static" and i["ipv4"] not in current_ipv4(run, dev):
            problems.append(f"{dev}: {i['ipv4']} is not assigned")
        if i["gateway"]:
            if not any(r["gateway"] == i["gateway"] for r in default_routes(run)):
                problems.append(f"default route via {i['gateway']} is missing")
    v6 = effective_ipv6()
    if v6 is not None and v6 != plan["ipv6_enabled"]:
        problems.append(f"kernel IPv6 is {'on' if v6 else 'off'}, plan says {'on' if plan['ipv6_enabled'] else 'off'}")
    return problems


def restore(run: Run, snap: Dict[str, Any], plan_path: Optional[str] = None,
            sysctl_path: Optional[str] = None) -> List[str]:
    """Put addresses, the default route, IPv6 and the committed files back."""
    plan_path = plan_path or PLAN_PATH
    sysctl_path = sysctl_path or IPV6_SYSCTL_PATH
    steps: List[str] = []
    for dev, addrs in (snap.get("addresses") or {}).items():
        if not DEVICE_RE.fullmatch(dev) or not device_exists(dev):
            continue
        for a in current_ipv4(run, dev):
            if a not in addrs:
                run(["ip", "addr", "del", a, "dev", dev])
        for a in addrs:
            run(["ip", "addr", "replace", a, "dev", dev])
        steps.append(f"{dev}: restored {', '.join(addrs) or 'no static address'}")
    if snap.get("ipv6_enabled") is not None:
        val = "0" if snap["ipv6_enabled"] else "1"
        run(["sysctl", "-q", "-w", f"net.ipv6.conf.all.disable_ipv6={val}"])
        run(["sysctl", "-q", "-w", f"net.ipv6.conf.default.disable_ipv6={val}"])
        for dev, addrs in (snap.get("ipv6") or {}).items():
            if DEVICE_RE.fullmatch(dev) and device_exists(dev):
                run(["sysctl", "-q", "-w", f"net.ipv6.conf.{_sysctl_key_dev(dev)}.disable_ipv6={val}"])
                for a in addrs:
                    run(["ip", "-6", "addr", "replace", a, "dev", dev])
    routes = snap.get("routes") or []
    if routes:
        r = routes[0]
        run(["ip", "route", "replace", "default", "via", r["gateway"], "dev", r["dev"]])
        steps.append(f"default via {r['gateway']} dev {r['dev']}")
    for path, content in ((plan_path, snap.get("plan")), (sysctl_path, snap.get("sysctl"))):
        if content is None:
            try:
                os.remove(path)
            except OSError:
                pass
        else:
            write_atomic(path, content)
    steps.append("committed files restored")
    return steps


# --------------------------------------------------------------------------
# Boot entry point
# --------------------------------------------------------------------------
def boot(plan_file: str, run: Run = default_run, plan_path: Optional[str] = None) -> int:
    """Apply a plan at boot. Falls back to the last committed plan when the
    freshly generated one is missing or invalid."""
    plan_path = plan_path or PLAN_PATH
    candidates = [plan_file, plan_path] if plan_file else [plan_path]
    for candidate in candidates:
        text = read_text(candidate) if candidate else None
        if not text:
            continue
        try:
            plan, errors = validate_plan(loads(text))
        except ValueError:
            plan, errors = None, ["not JSON"]
        if errors or plan is None:
            print(f"[micron-netcfg] {candidate}: rejected: {'; '.join(errors)}", file=sys.stderr)
            continue
        previous = None
        try:
            previous = loads(read_text(plan_path) or "null")
        except ValueError:
            previous = None
        result = apply_plan(run, plan, previous, plan_path=plan_path)
        for line in result["steps"]:
            print(f"[micron-netcfg] {line}")
        for line in result["warnings"]:
            print(f"[micron-netcfg] warning: {line}", file=sys.stderr)
        for line in result["errors"]:
            print(f"[micron-netcfg] error: {line}", file=sys.stderr)
        return 0 if not result["errors"] else 1
    print("[micron-netcfg] no usable network plan; leaving legacy boot defaults", file=sys.stderr)
    return 2


def main(argv: List[str]) -> int:
    if len(argv) >= 2 and argv[1] == "boot":
        return boot(argv[2] if len(argv) > 2 else "")
    if len(argv) >= 3 and argv[1] == "validate":
        try:
            plan = loads(read_text(argv[2]) or "null")
        except ValueError:
            print("not JSON", file=sys.stderr)
            return 1
        _, errors = validate_plan(plan)
        for e in errors:
            print(e, file=sys.stderr)
        return 1 if errors else 0
    if len(argv) >= 2 and argv[1] == "show":
        print(read_text(PLAN_PATH) or "{}")
        return 0
    print("usage: micron_netcfg.py boot [plan.json] | validate <plan.json> | show", file=sys.stderr)
    return 64


if __name__ == "__main__":
    sys.exit(main(sys.argv))

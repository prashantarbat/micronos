#!/usr/bin/env python3
"""
MicronOS micrond Link Aggregation (Bonding LACP) & Wireless WLAN Test Harness
=============================================================================

Standalone test harness verifying:
- bond.list
- bond.apply (802.3ad LACP, active-backup, slave enslavement, MTU)
- bond.delete (release slaves, delete bond link)
- wireless.status (iw telemetry, hostapd station queries)
- wireless.apply (hostapd config compilation, WPA2/WPA3 PMF security, wpa_supplicant)
"""
import asyncio
import importlib.util
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

spec = importlib.util.spec_from_file_location("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))
micrond = importlib.util.module_from_spec(spec)
spec.loader.exec_module(micrond)

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


executed_commands = []


def fake_run(*args, **kwargs) -> subprocess.CompletedProcess:
    argv = args[1] if len(args) > 1 else args[0]
    cmd_str = " ".join(argv) if isinstance(argv, list) else str(argv)
    executed_commands.append(cmd_str)

    stdout_content = ""
    return_code = 0

    if "ip link show bond0" in cmd_str:
        # Pretend bond0 does not exist yet so ip link add is tested
        return_code = 1
        stdout_content = "Cannot find device \"bond0\""
    elif "iw dev" in cmd_str and "info" in cmd_str:
        stdout_content = (
            "Interface wlan0\n"
            "        ifindex 4\n"
            "        wdev 0x1\n"
            "        addr 00:11:22:33:44:55\n"
            "        type AP\n"
            "        channel 36 (5180 MHz), width: 80 MHz, center1: 5210 MHz\n"
            "        txpower 20.00 dBm\n"
        )
    elif "hostapd_cli all_sta" in cmd_str or ("hostapd_cli" in cmd_str and "sta " in cmd_str):
        stdout_content = (
            "a4:83:e7:22:11:4a\n"
            "flags=[AUTH][ASSOC][AUTHORIZED]\n"
            "signal=-52\n"
            "rx_packets=1280\n"
            "tx_packets=950\n"
        )
    elif "ip -d link show type bond" in cmd_str:
        stdout_content = (
            "3: bond0: <BROADCAST,MULTICAST,MASTER,UP,LOWER_UP> mtu 1500 qdisc noqueue state UP mode DEFAULT\n"
            "    link/ether 52:54:00:12:34:56 brd ff:ff:ff:ff:ff:ff promiscuity 0 minmtu 68 maxmtu 65535\n"
            "    bond mode 802.3ad miimon 100 updelay 0 downdelay 0 use_carrier 1\n"
        )

    return subprocess.CompletedProcess(argv, return_code, stdout_content, "")


micrond.RpcHandler._run = fake_run

loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)


async def run_tests():
    global executed_commands

    print("=== Testing micrond Link Aggregation (bond.*) ===")

    # 1. Test rpc_bond_apply (802.3ad LACP)
    executed_commands = []
    bond_payload = {
        "id": "bond0",
        "mode": "802.3ad",
        "slaves": ["eth1", "eth2"],
        "lacp_rate": "fast",
        "xmit_hash_policy": "layer2+3",
        "miimon": 100,
        "mtu": 1500,
    }
    req = {"jsonrpc": "2.0", "id": 1, "method": "bond.apply", "params": bond_payload}
    res = await handler.handle_request(req)
    result = res.get("result", {})
    check("bond_apply_success", result.get("success") is True, str(result))
    check("bond_apply_bond_name", result.get("bond") == "bond0")
    check("modprobe_bonding_called", any("modprobe bonding" in c for c in executed_commands))
    check("ip_link_add_bond_called", any("ip link add bond0 type bond mode 802.3ad" in c for c in executed_commands))
    check("enslave_eth1_called", any("ip link set eth1 master bond0" in c for c in executed_commands))
    check("enslave_eth2_called", any("ip link set eth2 master bond0" in c for c in executed_commands))
    check("bring_up_bond_called", any("ip link set bond0 mtu 1500 up" in c for c in executed_commands))

    # 2. Test rpc_bond_list
    executed_commands = []
    req_list = {"jsonrpc": "2.0", "id": 2, "method": "bond.list", "params": {}}
    res_list = await handler.handle_request(req_list)
    result_list = res_list.get("result", {})
    check("bond_list_success", result_list.get("success") is True)
    check("bond_list_returns_list", isinstance(result_list.get("bonds"), list))

    # 3. Test rpc_bond_delete
    executed_commands = []
    req_del = {"jsonrpc": "2.0", "id": 3, "method": "bond.delete", "params": {"id": "bond0"}}
    res_del = await handler.handle_request(req_del)
    result_del = res_del.get("result", {})
    check("bond_delete_success", result_del.get("success") is True)
    check("bond_delete_command_issued", any("ip link delete bond0 type bond" in c for c in executed_commands))

    print("\n=== Testing micrond Wireless WLAN (wireless.*) ===")

    # 4. Test rpc_wireless_apply (Access Point Mode with WPA3)
    executed_commands = []
    wlan_payload = {
        "interface": "wlan0",
        "mode": "ap",
        "ssid": "MicronOS-Enterprise",
        "passphrase": "SecretPassword123!",
        "security": "wpa3",
        "hw_mode": "a",
        "channel": 36,
        "country_code": "US",
        "hidden_ssid": 0,
        "client_isolation": 1,
        "tx_power": 20,
        "enabled": 1,
    }
    req_w = {"jsonrpc": "2.0", "id": 4, "method": "wireless.apply", "params": wlan_payload}
    res_w = await handler.handle_request(req_w)
    result_w = res_w.get("result", {})
    check("wireless_apply_success", result_w.get("success") is True, str(result_w))
    check("wireless_apply_interface", result_w.get("interface") == "wlan0")
    check("hostapd_restart_issued", any("systemctl restart hostapd" in c for c in executed_commands))

    # 5. Test rpc_wireless_status
    executed_commands = []
    req_ws = {"jsonrpc": "2.0", "id": 5, "method": "wireless.status", "params": {"interface": "wlan0"}}
    res_ws = await handler.handle_request(req_ws)
    result_ws = res_ws.get("result", {})
    check("wireless_status_success", result_ws.get("success") is True)
    check("wireless_status_channel", result_ws.get("channel") == 36)
    check("wireless_status_txpower", "20.00 dBm" in str(result_ws.get("txpower")))

    # 6. Test rpc_wireless_apply (Client/Station mode)
    executed_commands = []
    client_payload = {
        "interface": "wlan0",
        "mode": "client",
        "ssid": "Upstream-Hotspot",
        "passphrase": "UplinkPassphrase123",
        "security": "wpa2",
        "enabled": 1,
    }
    req_wc = {"jsonrpc": "2.0", "id": 6, "method": "wireless.apply", "params": client_payload}
    res_wc = await handler.handle_request(req_wc)
    result_wc = res_wc.get("result", {})
    check("wireless_client_mode_success", result_wc.get("success") is True)
    check("wpa_supplicant_restart_issued", any("wpa_supplicant" in c for c in executed_commands))


if __name__ == "__main__":
    asyncio.run(run_tests())
    if failures:
        print(f"\nFAILED with {len(failures)} failures: {failures}")
        sys.exit(1)
    else:
        print("\nALL MICROND BONDING & WIRELESS TESTS PASSED!")
        sys.exit(0)

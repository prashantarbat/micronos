#!/usr/bin/env python3
"""
MicronOS micrond Micron OS User Management RPC verification
===========================================================

Standalone harness for Micron OS IAM user methods:
- user.list_os
- user.create
- user.update
- user.delete
- user.sync

External commands (useradd, usermod, userdel, chpasswd) are captured/mocked.
File I/O for /etc/passwd, /etc/group, /etc/sudoers.d, and ~/.ssh/authorized_keys
is exercised against isolated temporary directories.
"""
import asyncio
import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

spec = importlib.util.spec_from_file_location("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))
micrond = importlib.util.module_from_spec(spec)
spec.loader.exec_module(micrond)

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


# ---- Isolate filesystem ----
TEMP_DIR = tempfile.mkdtemp(prefix="micron-user-test-")
FAKE_SUDOERS = os.path.join(TEMP_DIR, "sudoers.d")
FAKE_PASSWD = os.path.join(TEMP_DIR, "passwd")
FAKE_GROUP = os.path.join(TEMP_DIR, "group")
os.makedirs(FAKE_SUDOERS, exist_ok=True)
micrond.SUDOERS_DIR = FAKE_SUDOERS
micrond.PASSWD_FILE = FAKE_PASSWD
micrond.GROUP_FILE = FAKE_GROUP

with open(FAKE_PASSWD, "w", encoding="utf-8") as f:
    f.write("root:x:0:0:root:/root:/bin/bash\n")
    f.write("ministrator:x:1000:1000:MicronOS Administrator:/home/ministrator:/bin/bash\n")

with open(FAKE_GROUP, "w", encoding="utf-8") as f:
    f.write("root:x:0:\n")
    f.write("sudo:x:27:ministrator\n")

# Track command executions
executed_cmds = []


def fake_run(*args, **kwargs) -> subprocess.CompletedProcess:
    argv = args[1] if len(args) > 1 else args[0]
    executed_cmds.append(list(argv))
    cmd = argv[0]
    if cmd == "useradd":
        uname = argv[-1]
        with open(FAKE_PASSWD, "a", encoding="utf-8") as pf:
            pf.write(f"{uname}:x:1001:1001:{uname}:/home/{uname}:/bin/bash\n")
    elif cmd == "userdel":
        uname = argv[-1]
        if os.path.exists(FAKE_PASSWD):
            with open(FAKE_PASSWD, "r", encoding="utf-8") as pf:
                lines = [line for line in pf if not line.startswith(f"{uname}:")]
            with open(FAKE_PASSWD, "w", encoding="utf-8") as pf:
                pf.writelines(lines)
    return subprocess.CompletedProcess(argv, 0, "", "")


orig_run = micrond.RpcHandler._run
orig_chpasswd = micrond.RpcHandler._set_os_password
micrond.RpcHandler._run = fake_run

chpasswd_calls = []


def fake_chpasswd(*args, **kwargs) -> bool:
    username = args[-2]
    password = args[-1]
    chpasswd_calls.append((username, password))
    return True


micrond.RpcHandler._set_os_password = fake_chpasswd

loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)


async def main():
    global executed_cmds, chpasswd_calls

    print("\n--- 1. user.list_os ---")
    res = await handler.handle_request({"jsonrpc": "2.0", "id": 1, "method": "user.list_os", "params": {}})
    check("list_os returns success", res.get("result", {}).get("success") is True)
    users = res.get("result", {}).get("users", [])
    check("list_os includes ministrator", any(u["username"] == "ministrator" for u in users))
    check("list_os includes root", any(u["username"] == "root" for u in users))

    print("\n--- 2. user.create validation ---")
    res_bad = await handler.handle_request({
        "jsonrpc": "2.0", "id": 2, "method": "user.create",
        "params": {"username": "bad user name with spaces!"}
    })
    check("user.create rejects invalid username", res_bad.get("result", {}).get("success") is False)

    print("\n--- 3. user.create successful creation ---")
    executed_cmds = []
    chpasswd_calls = []
    res_create = await handler.handle_request({
        "jsonrpc": "2.0", "id": 3, "method": "user.create",
        "params": {
            "username": "netadmin",
            "password": "SecretPassword123!",
            "full_name": "Network Administrator",
            "shell": "/bin/bash",
            "is_admin": True,
            "ssh_keys": "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... user@host",
        }
    })
    check("user.create succeeds", res_create.get("result", {}).get("success") is True)
    check("user.create invokes useradd", any(cmd[0] == "useradd" and "netadmin" in cmd for cmd in executed_cmds))
    check("user.create calls chpasswd", ("netadmin", "SecretPassword123!") in chpasswd_calls)
    check("user.create adds to sudo", any(cmd[0] == "usermod" and "-aG" in cmd and "sudo" in cmd and "netadmin" in cmd for cmd in executed_cmds))

    # Check sudoers drop-in was written
    sudo_dropin = os.path.join(FAKE_SUDOERS, "99-micron-netadmin")
    check("sudoers drop-in file created", os.path.exists(sudo_dropin))
    if os.path.exists(sudo_dropin):
        with open(sudo_dropin, "r") as f:
            content = f.read()
        check("sudoers drop-in content matches", "netadmin ALL=(ALL:ALL) ALL" in content)

    print("\n--- 4. user.update ---")
    executed_cmds = []
    chpasswd_calls = []
    res_up = await handler.handle_request({
        "jsonrpc": "2.0", "id": 4, "method": "user.update",
        "params": {
            "username": "netadmin",
            "password": "NewSecretPassword456!",
            "full_name": "Lead Network Admin",
            "shell": "/bin/bash",
            "is_admin": True,
        }
    })
    check("user.update succeeds", res_up.get("result", {}).get("success") is True)
    check("user.update sets new password", ("netadmin", "NewSecretPassword456!") in chpasswd_calls)
    check("user.update calls usermod -c", any(cmd[0] == "usermod" and "-c" in cmd and "Lead Network Admin" in cmd for cmd in executed_cmds))

    print("\n--- 5. user.delete protection ---")
    res_del_root = await handler.handle_request({
        "jsonrpc": "2.0", "id": 5, "method": "user.delete",
        "params": {"username": "root"}
    })
    check("user.delete refuses root", res_del_root.get("result", {}).get("status") == "protected")

    res_del_admin = await handler.handle_request({
        "jsonrpc": "2.0", "id": 6, "method": "user.delete",
        "params": {"username": "ministrator"}
    })
    check("user.delete refuses ministrator", res_del_admin.get("result", {}).get("status") == "protected")

    print("\n--- 6. user.delete standard account ---")
    executed_cmds = []
    res_del_user = await handler.handle_request({
        "jsonrpc": "2.0", "id": 7, "method": "user.delete",
        "params": {"username": "netadmin"}
    })
    check("user.delete succeeds for netadmin", res_del_user.get("result", {}).get("success") is True)
    check("user.delete invokes userdel -r", any(cmd[0] == "userdel" and "-r" in cmd and "netadmin" in cmd for cmd in executed_cmds))
    check("sudoers dropin removed on delete", not os.path.exists(sudo_dropin))

    print("\n--- 7. user.sync ---")
    executed_cmds = []
    chpasswd_calls = []
    res_sync = await handler.handle_request({
        "jsonrpc": "2.0", "id": 8, "method": "user.sync",
        "params": {
            "users": [
                {
                    "username": "operator1",
                    "password": "OpPassword1!",
                    "full_name": "Appliance Operator 1",
                    "shell": "/bin/bash",
                    "is_admin": False,
                },
                {
                    "username": "operator2",
                    "password": "OpPassword2!",
                    "full_name": "Appliance Operator 2",
                    "shell": "/bin/bash",
                    "is_admin": False,
                }
            ]
        }
    })
    check("user.sync succeeds", res_sync.get("result", {}).get("success") is True)
    check("user.sync created 2 users", res_sync.get("result", {}).get("count") == 2)
    check("user.sync invoked useradd for operator1", any(cmd[0] == "useradd" and "operator1" in cmd for cmd in executed_cmds))
    check("user.sync invoked useradd for operator2", any(cmd[0] == "useradd" and "operator2" in cmd for cmd in executed_cmds))

    # Cleanup temp
    shutil.rmtree(TEMP_DIR, ignore_errors=True)

    if failures:
        print(f"\nFAILED ({len(failures)} failures): {failures}")
        sys.exit(1)
    else:
        print("\nALL MICROND USER TESTS PASSED")


if __name__ == "__main__":
    asyncio.run(main())

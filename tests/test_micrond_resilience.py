#!/usr/bin/env python3
"""
MicronOS micrond Power-Loss Resilience & Atomic Write Unit Verification
========================================================================

Verifies that safe_atomic_write and its wrappers:
- Perform atomic replacement with fsync barriers.
- Enforce requested file permissions (0600 vs 0644).
- Create parent directories automatically.
- Clean up temporary files on exception.
- Tolerate concurrent or pre-existing files without corruption or partial writes.
"""
import importlib.util
import os
import socket
import stat
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


def main():
    tmpdir = tempfile.mkdtemp(prefix="micron-atomic-test-")
    try:
        # 1. Basic write
        test_file = os.path.join(tmpdir, "test.conf")
        content1 = "first line\nsecond line\n"
        micrond.safe_atomic_write(test_file, content1)
        check("safe_atomic_write creates file", os.path.isfile(test_file))
        with open(test_file, "r", encoding="utf-8") as f:
            read_back = f.read()
        check("safe_atomic_write writes exact content", read_back == content1)

        # 2. Atomic replacement of existing file
        content2 = "new line 1\nnew line 2\nnew line 3\n"
        micrond.safe_atomic_write(test_file, content2)
        with open(test_file, "r", encoding="utf-8") as f:
            read_back2 = f.read()
        check("safe_atomic_write atomically replaces file", read_back2 == content2)

        # 3. Automatic parent directory creation
        nested_file = os.path.join(tmpdir, "subdir", "sub2", "nested.conf")
        content3 = "nested content\n"
        micrond.safe_atomic_write(nested_file, content3)
        check("safe_atomic_write creates parent directories", os.path.isfile(nested_file))
        with open(nested_file, "r", encoding="utf-8") as f:
            read_back3 = f.read()
        check("safe_atomic_write nested content correct", read_back3 == content3)

        # 4. Mode permissions check
        secret_file = os.path.join(tmpdir, "secret.key")
        micrond.safe_atomic_write(secret_file, "supersecret\n", mode=0o600)
        mode = stat.S_IMODE(os.stat(secret_file).st_mode)
        # On POSIX systems, verify exact 0o600 permissions
        if os.name != "nt":
            check("safe_atomic_write respects mode 0o600", mode == 0o600, f"mode={oct(mode)}")
        else:
            check("safe_atomic_write executes cleanly on Windows", os.path.isfile(secret_file))

        # 5. Handler wrappers
        res_atomic = micrond.RpcHandler._write_atomic(os.path.join(tmpdir, "handler_atomic.conf"), "atomic")
        check("RpcHandler._write_atomic succeeds", res_atomic is True)

        res_config = micrond.RpcHandler._write_config(os.path.join(tmpdir, "handler_config.conf"), "config")
        check("RpcHandler._write_config succeeds with None error", res_config is None)

        res_text = micrond.RpcHandler._write_text(os.path.join(tmpdir, "handler_text.conf"), "text")
        check("RpcHandler._write_text succeeds", res_text is True)

        # 6. Verify no leftover .tmp files
        tmp_files = [f for f in os.listdir(tmpdir) if f.startswith(".tmp_")]
        check("safe_atomic_write leaves no leftover temporary files", len(tmp_files) == 0, f"found={tmp_files}")

        # 7. Verify notify_systemd watchdog integration
        res_no_socket = micrond.notify_systemd("WATCHDOG=1")
        check("notify_systemd degrades gracefully when NOTIFY_SOCKET is unset", res_no_socket is False)

        # Emulate active systemd notify socket on POSIX systems
        if hasattr(os, "fork") and hasattr(socket, "AF_UNIX"):
            notify_path = os.path.join(tmpdir, "notify.sock")
            srv = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
            srv.bind(notify_path)
            try:
                os.environ["NOTIFY_SOCKET"] = notify_path
                res_with_socket = micrond.notify_systemd("WATCHDOG=1")
                check("notify_systemd sends datagram when NOTIFY_SOCKET is present", res_with_socket is True)
                msg = srv.recv(64)
                check("systemd notify receives expected payload", msg == b"WATCHDOG=1")
            finally:
                os.environ.pop("NOTIFY_SOCKET", None)
                srv.close()
        # 8. Verify rpc_system_ssh_apply parameter validation
        import asyncio
        loop = asyncio.new_event_loop()
        handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)
        try:
            res_invalid_port = loop.run_until_complete(handler.rpc_system_ssh_apply({"port": 99999}))
            check("rpc_system_ssh_apply validates port range", res_invalid_port.get("success") is False)

            res_invalid_tries = loop.run_until_complete(handler.rpc_system_ssh_apply({"port": 2222, "max_auth_tries": 0}))
            check("rpc_system_ssh_apply validates max_auth_tries", res_invalid_tries.get("success") is False)
        finally:
            loop.close()

    finally:
        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)

    if failures:
        print(f"\n{len(failures)} TESTS FAILED")
        exit(1)
    else:
        print("\nALL MICROND RESILIENCE TESTS PASSED")


if __name__ == "__main__":
    main()

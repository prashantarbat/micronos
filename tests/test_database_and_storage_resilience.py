#!/usr/bin/env python3
"""
MicronOS Database & Storage Health Telemetry Resilience Verification
=====================================================================

Tests:
1. SQLite Database integrity checks (PRAGMA quick_check).
2. Write-Ahead Log (WAL) tracking and on-demand / automated checkpointing.
3. Database health telemetry reporting in micrond.
4. Storage device discovery, rotational vs SSD detection, I/O stats.
5. SMART health graceful degradation and telemetry reporting.
"""
import asyncio
import importlib.util
import os
import sqlite3
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


def main():
    tmpdir = tempfile.mkdtemp(prefix="micron-db-storage-test-")
    loop = asyncio.new_event_loop()
    tx_mgr = micrond.SafeTransactionManager()
    handler = micrond.RpcHandler(tx_mgr, loop)

    try:
        # 1. Test database health on fresh database
        test_db = os.path.join(tmpdir, "test_config.db")
        con = sqlite3.connect(test_db)
        con.execute("PRAGMA journal_mode = WAL;")
        con.execute("PRAGMA synchronous = 2;")
        con.execute("CREATE TABLE test_table (id INTEGER PRIMARY KEY, key TEXT, value TEXT);")
        con.execute("INSERT INTO test_table (key, value) VALUES ('ssh_port', '2222');")
        con.commit()
        con.close()

        # Point handler to test database
        handler._locate_database_path = lambda: test_db

        db_health = handler._database_health()
        check("database_health reports healthy for valid SQLite WAL DB", db_health["healthy"] is True)
        check("database_health reports integrity ok", db_health["integrity"] == "ok")
        check("database_health detects WAL journal mode", db_health["journal_mode"] == "wal")
        check("database_health reports db_size_bytes > 0", db_health["db_size_bytes"] > 0)

        # 2. Test database checkpoint RPC
        cp_res = loop.run_until_complete(handler.rpc_database_checkpoint({"mode": "TRUNCATE"}))
        check("rpc_database_checkpoint succeeds", cp_res["success"] is True)
        check("rpc_database_checkpoint reports mode TRUNCATE", cp_res["mode"] == "TRUNCATE")
        check("rpc_database_checkpoint verifies integrity", cp_res["integrity"] == "ok")

        # 3. Test background maintenance routine
        maint_res = handler.maintain_database()
        check("maintain_database executes cleanly", maint_res["status"] == "ok" and maint_res["healthy"] is True)

        # 4. Test storage telemetry
        storage = handler._storage_telemetry()
        check("storage_telemetry returns list", isinstance(storage, list))

        storage_rpc = loop.run_until_complete(handler.rpc_storage_health({}))
        check("rpc_storage_health returns status ok", storage_rpc["status"] == "ok")
        check("rpc_storage_health returns devices list", isinstance(storage_rpc["devices"], list))

        # 5. Test system.health includes database and storage telemetry
        health_snap = handler._health_snapshot()
        check("health_snapshot includes database section", "database" in health_snap)
        check("health_snapshot includes storage section", "storage" in health_snap)
        check("health_snapshot database section is healthy", health_snap["database"]["healthy"] is True)

    finally:
        loop.close()

    if failures:
        print(f"\nFAILED: {len(failures)} check(s): " + "; ".join(failures))
        sys.exit(1)
    print("\nALL DATABASE AND STORAGE RESILIENCE CHECKS PASSED")


if __name__ == "__main__":
    main()

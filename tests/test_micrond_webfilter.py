#!/usr/bin/env python3
"""
MicronOS micrond WebFilter cache RPC verification
=================================================

Standalone harness (mirrors tests/test_*.php) for the Squid cache purge and
live-status RPC handlers. Every external command is stubbed, so this runs
anywhere Python 3 does without a Squid install and never touches the host.

  python3 tests/test_micrond_webfilter.py
"""
import asyncio
import importlib.util
import os
import subprocess
import sys
import tempfile
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

spec = importlib.util.spec_from_file_location("micrond", os.path.join(ROOT, "system", "micrond", "micrond.py"))
micrond = importlib.util.module_from_spec(spec)
spec.loader.exec_module(micrond)

failures = []


def check(name, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail else ""))
    if not cond:
        failures.append(name)


# ---- Fixtures -------------------------------------------------------------
tmp = tempfile.mkdtemp(prefix="micron_test_")
log_path = os.path.join(tmp, "access.log")
spool = os.path.join(tmp, "spool")
os.makedirs(os.path.join(spool, "00", "0F"))
with open(os.path.join(spool, "00", "0F", "obj1"), "wb") as fh:
    fh.write(b"x" * 4096)

today_epoch = time.mktime(time.localtime()[:3] + (0, 0, 0, 0, 0, -1))
old_epoch = today_epoch - 90000
with open(log_path, "w", encoding="utf-8") as fh:
    fh.write(f"{today_epoch + 100:.3f} 123 10.0.0.5 TCP_HIT/200 1000 GET http://a/\n")
    fh.write(f"{today_epoch + 200:.3f} 123 10.0.0.6 TCP_MISS/200 1000 GET http://b/\n")
    fh.write(f"{today_epoch + 300:.3f} 123 10.0.0.7 TCP_DENIED/403 0 GET http://c/\n")
    fh.write(f"{today_epoch + 400:.3f} 123 10.0.0.8 TCP_MEM_HIT/200 1000 GET http://d/\n")
    fh.write(f"{old_epoch:.3f} 123 10.0.0.9 TCP_MISS/200 1000 GET http://e/\n")

conf_path = os.path.join(tmp, "squid.conf")
with open(conf_path, "w", encoding="utf-8") as fh:
    fh.write("# test\ncache_mem 256 MB\ncache_dir ufs /var/spool/squid 4096 16 256\n")

micrond.SQUID_ACCESS_LOG = log_path
micrond.SQUID_CONF = conf_path
micrond.SQUID_SPOOL_DIR = spool

loop = asyncio.new_event_loop()
handler = micrond.RpcHandler(micrond.SafeTransactionManager(), loop)

# Stub every external command: record instead of executing, report "not found".
calls = []


def fake_run(argv):
    calls.append(list(argv))
    return subprocess.CompletedProcess(argv, 127, "", "stub: command unavailable")


handler._run = fake_run

# The cache manager is probed over loopback HTTP; stub it so the harness stays
# offline. `manager_text` is what `mgr:info` would return (None = unreachable).
manager_text = None
handler._squid_manager_info = lambda port: manager_text

print("========================================================")
print("   MicronOS micrond WebFilter Cache RPC Verification")
print("========================================================\n")

# ---- Access log parsing ---------------------------------------------------
stats = handler._squid_access_log_stats(log_path)
check("access-log counts only today's requests", stats["requests"] == 4, str(stats))
check("access-log counts denials", stats["denied"] == 1, str(stats))
check("access-log hit/miss split", stats["hits"] == 2 and stats["misses"] == 1, str(stats))
check("access-log hit ratio", stats["hit_rate"] == "66.7%", stats["hit_rate"])

# ---- Access log tail (Real-Time monitor) ----------------------------------
entries = handler._squid_access_log_tail(log_path, 10)
check("access-log tail parses entries", len(entries) == 5, str(entries))
check("access-log tail is newest-first",
      entries[0]["timestamp"] >= entries[-1]["timestamp"], str([e["timestamp"] for e in entries]))
check("access-log tail maps TCP_DENIED to BLOCK",
      any(e["action"] == "BLOCK" for e in entries), str(entries))
check("access-log tail extracts the domain",
      any(e["domain"] == "a" for e in entries), str([e["domain"] for e in entries]))
check("access-log tail flags cache hits",
      any(e["cached"] for e in entries if e["cache_status"] == "TCP_HIT"), str(entries))
check("access-log tail respects the limit",
      len(handler._squid_access_log_tail(log_path, 2)) == 2)
check("access-log tail returns empty for unknown file",
      handler._squid_access_log_tail(os.path.join(tmp, "missing.log"), 5) == [])

resp = loop.run_until_complete(handler.rpc_webfilter_access_log({"limit": 3}))
check("access-log RPC returns the requested count",
      resp.get("success") is True and len(resp.get("entries", [])) == 3, str(resp))
check("access-log RPC clamps a non-numeric limit",
      len(loop.run_until_complete(handler.rpc_webfilter_access_log({"limit": "x"}))["entries"]) == 5)
resp = loop.run_until_complete(handler.rpc_squid_access_log({}))
check("squid.access_log alias present", resp.get("success") is True, str(resp))

# Missing log file must degrade to an empty stream, not raise.
orig_log = micrond.SQUID_ACCESS_LOG
micrond.SQUID_ACCESS_LOG = os.path.join(tmp, "does_not_exist.log")
resp = loop.run_until_complete(handler.rpc_webfilter_access_log({}))
check("access-log RPC degrades when the log is absent",
      resp.get("success") is True and resp.get("entries") == [], str(resp))
micrond.SQUID_ACCESS_LOG = orig_log

# ---- Cache clear path validation -----------------------------------------
res = loop.run_until_complete(handler.rpc_webfilter_cache_clear({"spool_dir": "/etc"}))
check("cache clear refuses a foreign spool path",
      res.get("success") is False and "unrecognised" in res.get("message", ""), str(res))
check("refusal touched no external command", calls == [], str(calls))

res = loop.run_until_complete(handler.rpc_webfilter_cache_clear({"spool_dir": "/var/spool/squid"}))
check("cache clear degrades gracefully when squid is absent",
      res.get("success") is False and "Could not stop squid" in res.get("message", ""), str(res))
check("cache clear stops squid first", ["systemctl", "stop", "squid"] in calls, str(calls))

res = loop.run_until_complete(handler.rpc_squid_cache_clear({"spool_dir": "/var/spool/squid"}))
check("squid.cache.clear alias present", res.get("status") == "error", str(res))

# ---- Reload result signalling --------------------------------------------
res = loop.run_until_complete(handler.rpc_webfilter_reload({}))
check("reload reports failure when squid is absent",
      res.get("success") is False and res.get("status") == "error", str(res))


def ok_run(argv):
    calls.append(list(argv))
    return subprocess.CompletedProcess(argv, 0, "Reconfiguring Squid...", "")


handler._run = ok_run
res = loop.run_until_complete(handler.rpc_webfilter_reload({}))
check("reload reports success with an explicit flag",
      res.get("success") is True and res.get("status") == "reconfigured", str(res))
handler._run = fake_run

# ---- Dispatch -------------------------------------------------------------
for method in ("webfilter.status", "squid.status", "webfilter.cache.clear", "squid.cache.clear",
               "webfilter.reload", "squid.reload", "webfilter.access_log", "squid.access_log"):
    resp = loop.run_until_complete(handler.handle_request({"jsonrpc": "2.0", "id": 1, "method": method, "params": {}}))
    check(f"dispatch {method}", "result" in resp, str(resp))

# ---- Live status shape ----------------------------------------------------
calls.clear()
status = loop.run_until_complete(handler.rpc_webfilter_status({}))
expected = {"running", "uptime", "requests_today", "blocked_today", "cache_hit_rate", "mem_usage", "disk_usage"}
check("status returns the full dashboard shape", expected.issubset(status.keys()), str(sorted(status.keys())))
check("status reports not running without systemd", status["running"] is False)
check("status requests_today is live", status["requests_today"] == 4, str(status))
check("status blocked_today is live", status["blocked_today"] == 1, str(status))
check("status hit rate is live", status["cache_hit_rate"] == "66.7%", str(status))
check("status mem_usage reflects cache_mem", status["mem_usage"] == "0 MB / 256 MB", status["mem_usage"])
check("status disk_usage reflects cache_dir", status["disk_usage"].endswith("/ 4.0 GB"), status["disk_usage"])
check("status marks cache manager unavailable", status["cache_manager"] is False)

# ---- Live cache-manager statistics (mgr:info) -----------------------------
MGR_INFO = (
    "Squid Object Cache: Version 6.10\n"
    "Request Hit Ratios:     5min: 57.3%, 60min: 51.2%\n"
    "Byte Hit Ratios:        5min: 22.1%, 60min: 19.4%\n"
    "Memory usage for squid: 131072.00 KB\n"
    "Storage Swap size:      524288 KB\n"
    "Storage Swap capacity:  2.00 GB, 25.0% used\n"
)
manager_text = MGR_INFO
status = loop.run_until_complete(handler.rpc_webfilter_status({}))
check("mgr:info marks the cache manager live", status["cache_manager"] is True)
check("mgr:info hit ratio is used", status["cache_hit_rate"] == "57.3%", str(status["cache_hit_rate"]))
check("mgr:info memory is used", status["mem_usage"] == "128 MB / 256 MB", status["mem_usage"])
check("mgr:info swap capacity is used", status["disk_usage"] == "512.0 MB / 2.0 GB", status["disk_usage"])

check("mgr hit-ratio parser falls back to any window",
      micrond.RpcHandler._squid_manager_hit_ratio("Request Hit Ratios:  60min: 39.1%") == "39.1%")
check("mgr disk parser falls back to swap size",
      handler._squid_manager_disk("Storage Swap size: 262144 KB").endswith("/ 4.0 GB"),
      handler._squid_manager_disk("Storage Swap size: 262144 KB"))
check("mgr parsers return None for empty input",
      micrond.RpcHandler._squid_manager_hit_ratio(None) is None and handler._squid_manager_disk("") is None)

print()
if failures:
    print(f"{len(failures)} FAILURE(S): {failures}")
    sys.exit(1)
print("ALL MICROND WEBFILTER CACHE CHECKS PASSED")

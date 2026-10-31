#!/usr/bin/env python3
"""
MicronOS Systemd & Sysctl Resilience Unit Verification
======================================================

Verifies that:
1. system/50-micron-resilience.conf defines all kernel panic auto-reboot,
   memory reservation, conntrack scale, and anti-spoofing parameters.
2. system/50-micron-watchdog.conf configures runtime watchdog and reboot limits.
3. Critical systemd unit files in system/ enforce aggressive restart policies,
   timeout caps, and OOM score immunities.
4. Packaging hooks and the installer provision resilience configurations onto
   the appliance filesystem.
"""
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


class TestSysctlResilience(unittest.TestCase):
    def setUp(self):
        self.conf_path = os.path.join(ROOT, "system", "50-micron-resilience.conf")
        self.assertTrue(os.path.isfile(self.conf_path), f"Missing {self.conf_path}")
        with open(self.conf_path, "r", encoding="utf-8") as f:
            self.lines = [line.strip() for line in f if line.strip() and not line.strip().startswith("#")]

        self.sysctl = {}
        for line in self.lines:
            if "=" in line:
                k, v = line.split("=", 1)
                self.sysctl[k.strip()] = v.strip()

    def test_kernel_panic_auto_reboot(self):
        self.assertEqual(self.sysctl.get("kernel.panic"), "10")
        self.assertEqual(self.sysctl.get("kernel.panic_on_oops"), "1")
        self.assertEqual(self.sysctl.get("kernel.softlockup_panic"), "1")
        self.assertEqual(self.sysctl.get("kernel.hardlockup_panic"), "1")
        self.assertEqual(self.sysctl.get("kernel.hung_task_timeout_secs"), "60")
        self.assertEqual(self.sysctl.get("kernel.hung_task_panic"), "1")

    def test_memory_and_oom_protection(self):
        self.assertEqual(self.sysctl.get("vm.min_free_kbytes"), "65536")
        self.assertEqual(self.sysctl.get("vm.swappiness"), "10")

    def test_conntrack_scale_and_reclamation(self):
        self.assertEqual(self.sysctl.get("net.netfilter.nf_conntrack_max"), "524288")
        self.assertEqual(self.sysctl.get("net.netfilter.nf_conntrack_tcp_timeout_established"), "7200")
        self.assertEqual(self.sysctl.get("net.netfilter.nf_conntrack_tcp_timeout_close_wait"), "30")

    def test_network_anti_spoofing(self):
        self.assertEqual(self.sysctl.get("net.ipv4.tcp_syncookies"), "1")
        self.assertEqual(self.sysctl.get("net.ipv4.conf.all.rp_filter"), "1")
        self.assertEqual(self.sysctl.get("net.ipv4.conf.all.accept_source_route"), "0")
        self.assertEqual(self.sysctl.get("net.ipv4.conf.all.accept_redirects"), "0")


class TestSystemdWatchdogAndUnits(unittest.TestCase):
    def parse_ini(self, path):
        content = {}
        cur_section = None
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or line.startswith(";"):
                    continue
                if line.startswith("[") and line.endswith("]"):
                    cur_section = line[1:-1]
                    content[cur_section] = {}
                elif "=" in line and cur_section:
                    k, v = line.split("=", 1)
                    content[cur_section][k.strip()] = v.strip()
        return content

    def test_watchdog_config(self):
        watchdog_path = os.path.join(ROOT, "system", "50-micron-watchdog.conf")
        self.assertTrue(os.path.isfile(watchdog_path))
        cfg = self.parse_ini(watchdog_path)
        mgr = cfg.get("Manager", {})
        self.assertEqual(mgr.get("RuntimeWatchdogSec"), "15s")
        self.assertEqual(mgr.get("RebootWatchdogSec"), "60s")
        self.assertEqual(mgr.get("DefaultTimeoutStopSec"), "15s")
        self.assertEqual(mgr.get("DefaultRestartSec"), "2s")

    def test_micrond_service(self):
        svc_path = os.path.join(ROOT, "system", "micrond.service")
        self.assertTrue(os.path.isfile(svc_path))
        cfg = self.parse_ini(svc_path)
        unit = cfg.get("Unit", {})
        svc = cfg.get("Service", {})

        self.assertEqual(unit.get("StartLimitIntervalSec"), "60s")
        self.assertEqual(unit.get("StartLimitBurst"), "5")
        self.assertEqual(svc.get("Restart"), "always")
        self.assertEqual(svc.get("RestartSec"), "2s")
        self.assertEqual(svc.get("WatchdogSec"), "30s")
        self.assertEqual(svc.get("TimeoutStopSec"), "10s")
        self.assertEqual(svc.get("OOMScoreAdjust"), "-900")
        self.assertEqual(svc.get("LimitNOFILE"), "65536")

    def test_sdwan_probe_service(self):
        svc_path = os.path.join(ROOT, "system", "micron-sdwan-probe.service")
        self.assertTrue(os.path.isfile(svc_path))
        cfg = self.parse_ini(svc_path)
        svc = cfg.get("Service", {})
        self.assertEqual(svc.get("Restart"), "always")
        self.assertEqual(svc.get("RestartSec"), "2s")
        self.assertEqual(svc.get("TimeoutStopSec"), "10s")
        self.assertEqual(svc.get("OOMScoreAdjust"), "-500")

    def test_mdns_repeater_service(self):
        svc_path = os.path.join(ROOT, "system", "mdns-repeater.service")
        self.assertTrue(os.path.isfile(svc_path))
        cfg = self.parse_ini(svc_path)
        svc = cfg.get("Service", {})
        self.assertEqual(svc.get("Restart"), "always")
        self.assertEqual(svc.get("RestartSec"), "2s")
        self.assertEqual(svc.get("TimeoutStopSec"), "10s")
        self.assertEqual(svc.get("OOMScoreAdjust"), "-200")

    def test_terminald_service(self):
        svc_path = os.path.join(ROOT, "system", "micron-terminald.service")
        self.assertTrue(os.path.isfile(svc_path))
        cfg = self.parse_ini(svc_path)
        svc = cfg.get("Service", {})
        self.assertEqual(svc.get("Restart"), "always")
        self.assertEqual(svc.get("RestartSec"), "2s")
        self.assertEqual(svc.get("OOMScoreAdjust"), "-200")
        self.assertEqual(svc.get("KillMode"), "control-group")

    def test_wireguard_service(self):
        svc_path = os.path.join(ROOT, "system", "wireguard.service")
        self.assertTrue(os.path.isfile(svc_path))
        cfg = self.parse_ini(svc_path)
        svc = cfg.get("Service", {})
        self.assertEqual(svc.get("TimeoutStartSec"), "60s")
        self.assertEqual(svc.get("TimeoutStopSec"), "30s")

    def test_db_check_service(self):
        svc_path = os.path.join(ROOT, "system", "micron-db-check.service")
        self.assertTrue(os.path.isfile(svc_path))
        cfg = self.parse_ini(svc_path)
        unit = cfg.get("Unit", {})
        svc = cfg.get("Service", {})
        self.assertEqual(svc.get("Type"), "oneshot")
        self.assertIn("micron:db-resilience --auto-heal", svc.get("ExecStart", ""))
        self.assertIn("micrond.service", unit.get("Before", ""))
        self.assertIn("apache2.service", unit.get("Before", ""))


class TestInstallerAndHookResilience(unittest.TestCase):
    def test_live_build_hook_installs_resilience(self):
        hook_path = os.path.join(ROOT, "packaging", "live-build", "config", "hooks", "live", "01-micronos-setup.hook.chroot")
        with open(hook_path, "r", encoding="utf-8") as f:
            content = f.read()

        self.assertIn("50-micron-resilience.conf", content)
        self.assertIn("50-micron-watchdog.conf", content)
        self.assertIn("apache2.service.d", content)
        self.assertIn("OOMScoreAdjust=-800", content)
        self.assertIn("micron-db-check.service", content)

    def test_install_script_provisions_resilience(self):
        install_path = os.path.join(ROOT, "installer", "install.sh")
        with open(install_path, "r", encoding="utf-8") as f:
            content = f.read()

        self.assertIn("50-micron-resilience.conf", content)
        self.assertIn("50-micron-watchdog.conf", content)
        self.assertIn("apache2.service.d", content)
        self.assertIn("OOMScoreAdjust=-800", content)
        self.assertIn("micron-db-check.service", content)


if __name__ == "__main__":
    unittest.main()


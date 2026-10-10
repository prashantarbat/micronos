<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';

echo "========================================================\n";
echo "      MicronOS STEP 2 / Phase 3 IPC Bridge Tests        \n";
echo "========================================================\n\n";

$ipc = new \App\Libraries\IpcClient();

echo "Running environment: " . (PHP_OS_FAMILY) . "\n";
echo "Mock Mode active   : " . ($ipc->isMock() ? 'YES (Windows Simulation)' : 'NO (Linux Socket)') . "\n\n";

$tests = [
    'system.ping'             => [],
    'system.info'             => [],
    'interface.list'          => [],
    'interface.status'        => ['device' => 'eth0'],
    'network.apply'           => ['safe_mode' => true],
    'interface.confirm'       => [],
    'firewall.list_rules'     => [],
    'firewall.apply'          => ['table' => 'filter'],
    'firewall.confirm'        => [],
    'nat.apply'               => ['rules' => []],
    'network.route.add'       => ['destination' => '10.50.0.0/16', 'gateway' => '192.168.1.254'],
    'vpn.wireguard.status'    => [],
    'vpn.wireguard.sync'      => [],
    'vpn.ipsec.sync'          => [],
    'service.restart'         => ['service' => 'suricata'],
    'dns.cache.flush'         => [],
    'webfilter.reload'        => [],
    'diag.ping'               => ['host' => '1.1.1.1', 'count' => 2],
];

$passed = 0;
$failed = 0;

foreach ($tests as $method => $params) {
    try {
        $resp = $ipc->callOrFail($method, $params);
        echo sprintf("[PASS] %-25s => %s\n", $method, json_encode($resp));
        $passed++;
    } catch (\Throwable $e) {
        echo sprintf("[FAIL] %-25s => ERROR: %s\n", $method, $e->getMessage());
        $failed++;
    }
}

echo "\n========================================================\n";
echo "  RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}

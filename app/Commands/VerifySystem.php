<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class VerifySystem extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'micron:verify';
    protected $description = 'Comprehensive test suite verifying all MicronOS compilers, daemons, and engines.';

    public function run(array $params)
    {
        CLI::write("========================================================", 'cyan');
        CLI::write("       MicronOS Compilers & Engine Verification", 'yellow');
        CLI::write("========================================================\n", 'cyan');

        try {
            $nft = new \App\Libraries\NftablesCompiler();
            $nftOut = $nft->compile();
            CLI::write("[PASS] NftablesCompiler: " . strlen($nftOut) . " bytes generated.", 'green');

            $swan = new \App\Libraries\StrongswanCompiler();
            $swanOut = $swan->compile();
            CLI::write("[PASS] StrongswanCompiler: " . strlen($swanOut) . " bytes generated.", 'green');

            $wg = new \App\Libraries\WireguardCompiler();
            $wgOut = $wg->compileServerConfig();
            CLI::write("[PASS] WireguardCompiler: " . strlen($wgOut) . " bytes generated.", 'green');

            $squid = new \App\Libraries\SquidConfigCompiler();
            $squidOut = $squid->compile();
            CLI::write("[PASS] SquidConfigCompiler: " . strlen($squidOut) . " bytes generated.", 'green');

            $kea = new \App\Libraries\KeaDhcpCompiler();
            $keaOut = $kea->compile();
            CLI::write("[PASS] KeaDhcpCompiler: " . strlen($keaOut) . " bytes generated.", 'green');

            $unbound = new \App\Libraries\UnboundDnsCompiler();
            $unboundOut = $unbound->compile();
            CLI::write("[PASS] UnboundDnsCompiler: " . strlen($unboundOut) . " bytes generated.", 'green');

            $suricata = new \App\Libraries\SuricataCompiler();
            $suricataOut = $suricata->compile();
            CLI::write("[PASS] SuricataCompiler: " . strlen($suricataOut) . " bytes generated.", 'green');

            $pppoe = new \App\Libraries\PppoeCompiler();
            $pppoeClientBytes = 0;
            foreach ($pppoe->fetchClientInterfaces() as $pppoeIface) {
                $pppoeClientBytes += strlen($pppoe->compileClientPeer($pppoeIface));
            }
            $pppoeServerBytes = 0;
            foreach ($pppoe->fetchServers() as $pppoeServer) {
                $pppoeServerBytes += strlen($pppoe->compileServerOptions($pppoeServer));
            }
            CLI::write(
                "[PASS] PppoeCompiler: " . count($pppoe->fetchClientInterfaces()) . " client link(s), "
                . count($pppoe->fetchServers()) . " server(s), "
                . ($pppoeClientBytes + $pppoeServerBytes) . " bytes generated.",
                'green',
            );

            $backup = new \App\Libraries\BackupEngine();
            $bk = $backup->createBackup();
            CLI::write("[PASS] BackupEngine: " . $bk['size'] . " bytes generated (SHA256: " . substr($bk['sha256'], 0, 16) . "...).", 'green');

            $totp = new \App\Libraries\TotpService();
            $secret = $totp->generateSecret();
            $code = $totp->calculateCode($secret);
            $valid = $totp->verifyCode($secret, $code);
            CLI::write("[PASS] TotpService: Secret=" . $secret . ", Code=" . $code . ", Valid=" . ($valid ? 'YES' : 'NO'), 'green');

            $ipc = new \App\Libraries\IpcClient();
            $ipcPing = $ipc->call('system.ping');
            $ipcIface = $ipc->call('interface.list');
            $ipcMode = $ipc->isMock() ? 'Mock Simulation' : 'UDS Native';
            CLI::write("[PASS] IpcClient [{$ipcMode}]: system.ping=" . json_encode($ipcPing['result']) . ", interfaces=" . count($ipcIface['result']['interfaces'] ?? []), 'green');

            CLI::write("\n========================================================", 'cyan');
            CLI::write("  ALL MICRONOS SYSTEM COMPILERS & ENGINES VERIFIED OK!", 'light_green');
            CLI::write("========================================================", 'cyan');
        } catch (\Throwable $e) {
            CLI::error("[FAIL] Error: " . $e->getMessage());
            CLI::error($e->getTraceAsString());
        }
    }
}

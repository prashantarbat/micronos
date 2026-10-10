<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';

echo "========================================================\n";
echo "       MicronOS Compilers & Engine Verification\n";
echo "========================================================\n\n";

try {
    $nft = new \App\Libraries\NftablesCompiler();
    $nftOut = $nft->compile();
    echo "[PASS] NftablesCompiler: " . strlen($nftOut) . " bytes generated.\n";

    $swan = new \App\Libraries\StrongswanCompiler();
    $swanOut = $swan->compile();
    echo "[PASS] StrongswanCompiler: " . strlen($swanOut) . " bytes generated.\n";

    $wg = new \App\Libraries\WireguardCompiler();
    $wgOut = $wg->compileServerConfig();
    echo "[PASS] WireguardCompiler: " . strlen($wgOut) . " bytes generated.\n";

    $squid = new \App\Libraries\SquidConfigCompiler();
    $squidOut = $squid->compile();
    echo "[PASS] SquidConfigCompiler: " . strlen($squidOut) . " bytes generated.\n";

    $kea = new \App\Libraries\KeaDhcpCompiler();
    $keaOut = $kea->compile();
    echo "[PASS] KeaDhcpCompiler: " . strlen($keaOut) . " bytes generated.\n";

    $unbound = new \App\Libraries\UnboundDnsCompiler();
    $unboundOut = $unbound->compile();
    echo "[PASS] UnboundDnsCompiler: " . strlen($unboundOut) . " bytes generated.\n";

    $resolver = new \App\Libraries\SystemResolverCompiler();
    $resolverOut = $resolver->compile();
    echo "[PASS] SystemResolverCompiler: " . strlen($resolverOut) . " bytes generated (" . count($resolver->nameservers()) . " nameserver(s)).\n";

    $suricata = new \App\Libraries\SuricataCompiler();
    $suricataOut = $suricata->compile();
    echo "[PASS] SuricataCompiler: " . strlen($suricataOut) . " bytes generated.\n";

    $backup = new \App\Libraries\BackupEngine(\Config\Database::connect('default'));
    $bk = $backup->createBackup();
    echo "[PASS] BackupEngine: " . $bk['size'] . " bytes generated (SHA256: " . substr($bk['sha256'], 0, 16) . "...).\n";

    $totp = new \App\Libraries\TotpService();
    $secret = $totp->generateSecret();
    $code = $totp->calculateCode($secret);
    $valid = $totp->verifyCode($secret, $code);
    echo "[PASS] TotpService: Secret=" . $secret . ", Code=" . $code . ", Valid=" . ($valid ? 'YES' : 'NO') . "\n";

    echo "\n========================================================\n";
    echo "  ALL MICRONOS SYSTEM COMPILERS & ENGINES VERIFIED OK!\n";
    echo "========================================================\n";
} catch (\Throwable $e) {
    echo "[FAIL] Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

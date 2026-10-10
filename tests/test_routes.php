<?php

$routes = [
    '/auth/login',
    '/dashboard',
    '/interfaces',
    '/firewall/rules',
    '/nat',
    '/routing',
    '/dhcp',
    '/dns',
    '/vpn/wireguard',
    '/vpn/openvpn',
    '/vpn/ipsec',
    '/webfilter',
    '/ids',
    '/backup',
    '/users',
    '/roles',
    '/diag',
    '/system',
    '/profile'
];

$baseUrl  = 'http://micronos.test';
$resolveHosts = ['micronos.test:80:127.0.0.1'];  // Force DNS resolution for CLI curl
$cookieJar = tempnam(sys_get_temp_dir(), 'micron_cookie');

// 1. Get CSRF from login page
$ch = curl_init($baseUrl . '/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RESOLVE        => $resolveHosts,
]);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/name="csrf_test_name" value="([^"]+)"/', $html, $matches);
$csrf = $matches[1] ?? '';

// 2. Perform Login as Ministrator
$ch = curl_init($baseUrl . '/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'csrf_test_name' => $csrf,
        'username'       => 'Ministrator',
        'password'       => 'M1Cr0N'
    ]),
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RESOLVE        => $resolveHosts,
]);
$loginResp = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response HTTP Code: {$loginCode}\n\n";

// 3. Test Each Route
$allPassed = true;
foreach ($routes as $route) {
    $url = $baseUrl . $route;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_RESOLVE        => $resolveHosts,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $status = ($httpCode === 200) ? 'PASS' : 'FAIL';
    if ($status === 'FAIL') {
        $allPassed = false;
    }
    printf("[%s] %-30s HTTP %d (Size: %d bytes)\n", $status, $route, $httpCode, strlen($resp));
}

@unlink($cookieJar);

echo "\nResult: " . ($allPassed ? "ALL 19 ROUTES PASSED (200 OK)" : "SOME ROUTES FAILED") . "\n";

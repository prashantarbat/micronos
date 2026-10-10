<?php

$cookieFile = __DIR__ . '/../writable/test_cookies.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

// 1. Get login page to extract CSRF token
$ch = curl_init('http://micronos.test/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/name=["\']csrf_test_name["\']\s+value=["\']([a-f0-9]+)["\']/i', $html, $m);
$csrf = $m[1] ?? '';
echo "CSRF Token: " . ($csrf ? substr($csrf, 0, 8) . '...' : 'none') . "\n";

// 2. Post credentials
$ch = curl_init('http://micronos.test/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_test_name' => $csrf,
    'username'       => 'Ministrator',
    'password'       => 'M1Cr0N',
]));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$loginResult = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login HTTP Code: {$loginCode}\n";

// 3. Request /firewall/rules
$ch = curl_init('http://micronos.test/firewall/rules');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$fwHtml = curl_exec($ch);
$fwCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Firewall Rules HTTP Code: {$fwCode}\n";
echo "HTML Length: " . strlen($fwHtml) . " bytes\n";

if (strpos($fwHtml, 'Firewall Policy &amp; Objects') !== false || strpos($fwHtml, 'Firewall Policy') !== false) {
    echo "[PASS] Firewall Policy header present.\n";
} else {
    echo "[FAIL] Missing policy header in response.\n";
}

if (strpos($fwHtml, 'LAN to WAN Internet Access') !== false) {
    echo "[PASS] Seeded policy 'LAN to WAN Internet Access' rendered.\n";
} else {
    echo "[FAIL] Seeded policy row not found.\n";
}

if (strpos($fwHtml, 'Web_Browsing') !== false) {
    echo "[PASS] Service group 'Web_Browsing' pill rendered.\n";
}

if (strpos($fwHtml, 'Public_DNS_Servers') !== false || strpos($fwHtml, 'Google_DNS') !== false) {
    echo "[PASS] Address objects rendered in catalog and selectors.\n";
}

if (strpos($fwHtml, 'ACCEPT') !== false) {
    echo "[PASS] ACCEPT action badge rendered.\n";
}

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

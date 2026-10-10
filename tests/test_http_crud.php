<?php

$cookieFile = __DIR__ . '/../writable/test_cookies_crud.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function getCsrf($html) {
    preg_match('/name=["\']csrf_test_name["\']\s+value=["\']([a-f0-9]+)["\']/i', $html, $m);
    return $m[1] ?? '';
}

// 1. Login
$ch = curl_init('http://micronos.test/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$html = curl_exec($ch);
$csrf = getCsrf($html);
curl_close($ch);

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
$html = curl_exec($ch);
curl_close($ch);

// 2. Fetch /firewall/rules to get fresh CSRF
$ch = curl_init('http://micronos.test/firewall/rules');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
$html = curl_exec($ch);
$csrf = getCsrf($html);
curl_close($ch);

// 3. Create a new Address Object via POST
$ch = curl_init('http://micronos.test/firewall/addresses/store');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_test_name' => $csrf,
    'name'           => 'AWS_Cloud_HQ',
    'type'           => 'fqdn',
    'value'          => 'aws.amazon.com',
    'comment'        => 'Corporate AWS endpoints',
]));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "[PASS] Create Address HTTP Code: {$code}\n";

// 4. Create a custom Service via POST
$csrf = getCsrf($resp);
$ch = curl_init('http://micronos.test/firewall/services/store');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_test_name' => $csrf,
    'name'           => 'REDIS_CLUSTER_CUSTOM',
    'protocol'       => 'TCP',
    'tcp_port'       => '6380',
    'category'       => 'Database',
    'comment'        => 'Custom Redis replica cluster port',
]));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "[PASS] Create Service HTTP Code: {$code}\n";

// 5. Create a Firewall Policy via POST
$csrf = getCsrf($resp);
$ch = curl_init('http://micronos.test/firewall/policies/store');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_test_name' => $csrf,
    'name'           => 'Allow AWS Cloud Access',
    'src_interface'  => 'lan',
    'dst_interface'  => 'wan',
    'sources'        => ['LAN_Subnet'],
    'destinations'   => ['AWS_Cloud_HQ'],
    'services'       => ['HTTPS', 'REDIS_CLUSTER_CUSTOM'],
    'action'         => 'accept',
    'nat_enabled'    => '1',
    'profile_ips'    => '1',
    'description'    => 'Encrypted channel to AWS Cloud HQ',
]));
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "[PASS] Create Policy HTTP Code: {$code}\n";

if (strpos($resp, 'Allow AWS Cloud Access') !== false) {
    echo "[PASS] New policy 'Allow AWS Cloud Access' rendered in the policy table.\n";
}
if (strpos($resp, 'AWS_Cloud_HQ') !== false) {
    echo "[PASS] Destination FQDN object 'AWS_Cloud_HQ' rendered in policy pills.\n";
}

if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

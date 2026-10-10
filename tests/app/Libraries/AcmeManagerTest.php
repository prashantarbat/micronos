<?php

declare(strict_types=1);

use App\Libraries\Acme;
use App\Libraries\AcmeManager;
use App\Libraries\OpenSslConfig;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\AcmeStubCa;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * ACME issuance and renewal, end to end, against a stub CA.
 *
 * The stub is the part that matters. It does not merely answer "ok": on the
 * challenge endpoint it **reads the file back from disk** at the path the public
 * HTTP-01 route uses, and refuses to validate a name whose file is missing or
 * whose body does not begin with the token. A manager that stored the challenge
 * in a database column, or wrote it one directory over, would fail here while
 * every database assertion still passed.
 *
 * It also hands the real CSR to OpenSSL and returns the signed certificate, so
 * the certificate that ends up in `pki_certificates` is a genuine X.509 object
 * parsed by the same code the rest of the module uses — not a fixture string.
 *
 * @internal
 */
final class AcmeManagerTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAcme();
    }

    protected function tearDown(): void
    {
        $this->clearAcme();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Domain validation
    // ------------------------------------------------------------------

    public function testOnlyPlausibleDnsNamesAreAccepted(): void
    {
        $error = null;

        $this->assertSame(
            ['vpn.example.com', 'www.vpn.example.com'],
            AcmeManager::normaliseDomains("VPN.Example.com,\nwww.vpn.example.com.", 'http-01', $error)
        );
        $this->assertNull($error);

        // Duplicates collapse: the CA would reject an order naming a host twice.
        $this->assertSame(['a.example.com'], AcmeManager::normaliseDomains('a.example.com a.example.com', 'http-01', $error));

        foreach (['', 'localhost', 'no-dot-here', '-bad.example.com', 'bad-.example.com', 'a..example.com', '10.0.0.1', 'exa mple.com'] as $bad) {
            $error = null;
            $this->assertSame([], AcmeManager::normaliseDomains($bad, 'http-01', $error), "'{$bad}' must be refused");
            $this->assertNotNull($error, "'{$bad}' must come with a reason");
        }
    }

    /**
     * A wildcard is only issuable over DNS-01, and this appliance cannot publish
     * a TXT record by itself, so accepting one over HTTP-01 would produce an
     * order that can never be completed.
     */
    public function testAWildcardIsRefusedUnlessTheChallengeIsDns(): void
    {
        $error = null;

        $this->assertSame([], AcmeManager::normaliseDomains('*.example.com', 'http-01', $error));
        $this->assertStringContainsString('wildcard', (string) $error);
        $this->assertStringContainsString('DNS-01', (string) $error);

        $this->assertSame(['*.example.com'], AcmeManager::normaliseDomains('*.example.com', 'dns-01', $error));
        $this->assertNull($error);
    }

    public function testAnUnlistedDirectoryIsRefusedBeforeAnythingIsContacted(): void
    {
        $calls  = 0;
        $manager = new AcmeManager(
            function () use (&$calls): Acme {
                $calls++;

                return new Acme(null, Acme::LETSENCRYPT);
            },
            new FakeIpcClient(),
        );

        $result = $manager->begin(['directory' => 'https://evil.example/directory', 'domains' => 'vpn.example.com']);

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_NOT_CONFIGURED, $result['reason']);
        $this->assertSame(0, $calls);
        $this->assertCount(0, $manager->accounts());
    }

    // ------------------------------------------------------------------
    // Issuance
    // ------------------------------------------------------------------

    public function testAnHttp01OrderIsIssuedAndInstalled(): void
    {
        $ca   = new AcmeStubCa();
        $ipc  = new FakeIpcClient();
        $acme = $this->manager($ca, $ipc);

        $result = $acme->request([
            'directory'      => Acme::LETSENCRYPT,
            'email'          => 'ops@example.com',
            'domains'        => 'vpn.example.com, www.vpn.example.com',
            'challenge_type' => 'http-01',
        ], 3, 0);

        $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
        $this->assertSame('valid', $acme->order((int) $result['order_id'])['status']);

        $cert = $this->db->table('pki_certificates')->where('id', (int) $result['cert_id'])->get()->getRowArray();
        $this->assertIsArray($cert);
        $this->assertSame('acme', $cert['source']);
        $this->assertSame('vpn.example.com', $cert['common_name']);
        $this->assertSame('vpn.example.com,www.vpn.example.com', $cert['san_dns']);

        // The stored material has to be a real, parseable certificate whose key
        // matches it — that is what every consumer of this table relies on.
        $parsed = \App\Libraries\X509::parseCertificate((string) $cert['certificate']);
        $this->assertNotNull($parsed);
        $this->assertTrue(\App\Libraries\X509::keyMatchesCertificate((string) $cert['private_key'], (string) $cert['certificate']));
        $this->assertSame($parsed['valid_until'], $cert['valid_until']);

        // The authority holds the issuer chain and no signing key: this appliance
        // cannot sign with a public CA, and claiming otherwise would put a CA in
        // the list that can never issue.
        $authority = $this->db->table('pki_authorities')->where('id', (int) $cert['ca_id'])->get()->getRowArray();
        $this->assertIsArray($authority);
        $this->assertSame('acme', $authority['source']);
        $this->assertSame('', $authority['private_key']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $authority['certificate']);

        // Installing means telling the daemon to reload, or the certificate is
        // stored but nothing that presents TLS knows about it.
        $this->assertContains('cert.reload', array_column($ipc->calls, 'method'));
    }

    /**
     * The strongest check in this file: the stub CA refuses to validate a name
     * unless the answer is readable at the real HTTP-01 path.
     */
    public function testTheChallengeTheStubCaReadsIsTheOneTheApplianceServes(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca, new FakeIpcClient());

        $begun = $acme->begin([
            'directory'      => Acme::LETSENCRYPT,
            'email'          => 'ops@example.com',
            'domains'        => 'vpn.example.com',
            'challenge_type' => 'http-01',
        ]);

        $this->assertTrue($begun['ok']);
        $token = $begun['challenges'][0]['token'];

        // This is exactly what the public route returns for the CA's request.
        $body = $acme->challengeResponse($token);
        $this->assertNotNull($body);
        $this->assertStringStartsWith($token . '.', $body);
        $this->assertSame($begun['challenges'][0]['value'], $body);

        // A token that is not base64url is never a file this appliance wrote.
        foreach (['../../config/App', 'a/b', 'tok en', '', '.hidden'] as $refused) {
            $this->assertNull($acme->challengeResponse($refused), "'{$refused}' must not resolve to a file");
        }

        $done = $acme->complete((int) $begun['order_id'], 3, 0);
        $this->assertTrue($done['ok'], (string) ($done['message'] ?? ''));

        // Once the order is done the answer is gone: a key authorization that
        // outlives its order is a standing answer to a challenge.
        $this->assertNull($acme->challengeResponse($token));
        $this->assertSame(0, $acme->pendingChallengeCount());
    }

    public function testADns01OrderReturnsTheTxtRecordsAndCompletesAfterwards(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca, new FakeIpcClient());

        $begun = $acme->request([
            'directory'      => Acme::LETSENCRYPT,
            'email'          => 'ops@example.com',
            'domains'        => 'vpn.example.com',
            'challenge_type' => 'dns-01',
        ], 3, 0);

        // A request alone must stop here: this appliance has no DNS provider
        // integration, so a human publishes the record.
        $this->assertTrue($begun['ok']);
        $this->assertSame('pending', $begun['status'] ?? $acme->order((int) $begun['order_id'])['status']);
        $this->assertStringContainsString('_acme-challenge.vpn.example.com', (string) $begun['message']);

        $challenge = $begun['challenges'][0];
        $this->assertSame('dns-01', $challenge['type']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge['value'], 'the TXT value is a base64url SHA-256');

        // Nothing was written for HTTP-01, so the public route answers nothing.
        $this->assertNull($acme->challengeResponse($challenge['token']));

        $done = $acme->complete((int) $begun['order_id'], 3, 0);
        $this->assertTrue($done['ok'], (string) ($done['message'] ?? ''));
    }

    public function testAnAuthorizationTheCaRejectsIsRecordedOnTheOrder(): void
    {
        $ca          = new AcmeStubCa();
        $ca->rejectChallenge = 'DNS problem: NXDOMAIN looking up A for vpn.example.com';

        $acme   = $this->manager($ca, new FakeIpcClient());
        $result = $acme->request([
            'directory' => Acme::LETSENCRYPT,
            'domains'   => 'vpn.example.com',
        ], 2, 0);

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_AUTHORIZATION_FAILED, $result['reason']);
        $this->assertStringContainsString('NXDOMAIN', (string) $result['message']);

        $order = $acme->order((int) $result['order_id']);
        $this->assertSame('failed', $order['status']);
        $this->assertStringContainsString('NXDOMAIN', (string) $order['last_error']);

        // A failed order must not leave a certificate behind.
        $this->assertSame(0, $this->db->table('pki_certificates')->where('source', 'acme')->countAllResults());
        $this->assertSame(0, $acme->pendingChallengeCount(), 'a failed order must not leave its challenge served');
    }

    public function testTheAccountKeyIsReusedRatherThanRegenerated(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca, new FakeIpcClient());

        $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'a.example.com'], 2, 0);
        $first = (string) $acme->account(Acme::LETSENCRYPT)['private_key'];

        $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'b.example.com'], 2, 0);
        $second = (string) $acme->account(Acme::LETSENCRYPT)['private_key'];

        // The account key *is* the account: a new key would be a new account at
        // the CA, and every certificate issued so far would belong to the old one.
        $this->assertSame($first, $second);
        $this->assertSame('registered', $acme->account(Acme::LETSENCRYPT)['status']);
        $this->assertSame('ops@example.com', $acme->contactEmail(Acme::LETSENCRYPT));

        // One account, not one per request.
        $this->assertCount(1, $acme->accounts());
    }

    // ------------------------------------------------------------------
    // Renewal
    // ------------------------------------------------------------------

    /**
     * The renewal window is the whole reason a scheduled sweep is useful, and an
     * off-by-one here either renews every certificate continuously or lets one
     * expire.
     */
    public function testOnlyCertificatesInsideTheWindowAreDue(): void
    {
        $acme = $this->manager(new AcmeStubCa(), new FakeIpcClient());

        $this->insertAcmeCert('due-soon', date('Y-m-d H:i:s', time() + 10 * 86400));
        $this->insertAcmeCert('due-now', date('Y-m-d H:i:s', time() + 29 * 86400));
        $this->insertAcmeCert('not-yet', date('Y-m-d H:i:s', time() + 31 * 86400));
        $locallyIssued = $this->insertCert('local', date('Y-m-d H:i:s', time() + 2 * 86400), 'generated');

        $due = array_column($acme->due(), 'name');

        $this->assertContains('due-soon', $due);
        $this->assertContains('due-now', $due);
        $this->assertNotContains('not-yet', $due, 'a certificate with 31 days left is not in the 30-day window');
        $this->assertNotContains('local', $due, 'a certificate this appliance signed itself is not ACME-managed');

        $this->assertNotSame([], $due, 'something must be due, or this test proves nothing');

        // The window is a parameter, not a constant: a CA with short-lived
        // certificates needs a wider one.
        $this->assertContains('not-yet', array_column($acme->due(60), 'name'));
        $this->assertNotContains('local', array_column($acme->due(60), 'name'));
        $this->assertGreaterThan(0, $locallyIssued);
    }

    public function testTheSoonestExpiryIsFirstAndAnExpiredCertificateIsDue(): void
    {
        $acme = $this->manager(new AcmeStubCa(), new FakeIpcClient());

        $this->insertAcmeCert('later', date('Y-m-d H:i:s', time() + 20 * 86400));
        $this->insertAcmeCert('expired', date('Y-m-d H:i:s', time() - 86400));

        $due = $acme->due();

        $this->assertSame(['expired', 'later'], array_column($due, 'name'));
        $this->assertLessThan(0, $due[0]['days_left'], 'an expired certificate owes a negative count of days');
        $this->assertGreaterThan(0, $due[1]['days_left']);
        $this->assertLessThanOrEqual(AcmeManager::RENEW_WINDOW_DAYS, $due[1]['days_left']);
    }

    /**
     * Renewal must reuse the certificate row. Every binding that points at a
     * certificate — the Web GUI certificate id, a tunnel, a service — points at
     * its id, so inserting a new row would silently orphan them.
     */
    public function testRenewalReplacesTheCertificateInPlace(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca, new FakeIpcClient());

        $first = $acme->request([
            'directory' => Acme::LETSENCRYPT,
            'email'     => 'ops@example.com',
            'domains'   => 'vpn.example.com',
            'name'      => 'VPN Portal',
            'cert_type' => 'server',
        ], 2, 0);

        $this->assertTrue($first['ok'], (string) ($first['message'] ?? ''));
        $certId = (int) $first['cert_id'];
        $before = $this->db->table('pki_certificates')->where('id', $certId)->get()->getRowArray();

        $renewed = $acme->renew($certId, 2, 0);

        $this->assertTrue($renewed['ok'], (string) ($renewed['message'] ?? ''));
        $this->assertSame($certId, (int) $renewed['cert_id'], 'renewal must update the same certificate row');
        $this->assertTrue((bool) $renewed['renewed']);

        $after = $this->db->table('pki_certificates')->where('id', $certId)->get()->getRowArray();
        $this->assertSame(1, $this->db->table('pki_certificates')->where('source', 'acme')->countAllResults(), 'renewal must not add a second certificate');
        $this->assertSame('VPN Portal', $after['name'], 'the operator-chosen name survives a renewal');
        $this->assertSame('server', $after['cert_type']);

        // The certificate really was re-issued: a new key, a new serial.
        $this->assertNotSame($before['private_key'], $after['private_key']);
        $this->assertNotSame($before['serial_number'], $after['serial_number']);
        $this->assertNotSame($before['certificate'], $after['certificate']);

        // And the order row is reused too, because it is the record of which
        // directory and challenge type this certificate is renewed through.
        $this->assertSame(1, $this->db->table('pki_acme_orders')->where('cert_id', $certId)->countAllResults());
        $this->assertSame(Acme::LETSENCRYPT, $acme->order((int) $renewed['order_id'])['directory']);
    }

    public function testRenewingSomethingThisApplianceDidNotGetFromAcmeIsRefused(): void
    {
        $acme = $this->manager(new AcmeStubCa(), new FakeIpcClient());
        $id   = $this->insertCert('local', date('Y-m-d H:i:s', time() + 2 * 86400), 'generated');

        $result = $acme->renew($id);

        $this->assertFalse($result['ok']);
        $this->assertSame(Acme::REASON_NOT_CONFIGURED, $result['reason']);

        $this->assertFalse($acme->renew(999999)['ok']);
    }

    public function testTheScheduledSweepRenewsEverythingDueAndReportsWhatItCouldNot(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca, new FakeIpcClient());

        $good = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'good.example.com'], 2, 0);
        $bad  = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'broken.example.com'], 2, 0);
        $this->assertTrue($good['ok'], (string) ($good['message'] ?? ''));
        $this->assertTrue($bad['ok'], (string) ($bad['message'] ?? ''));

        // The CA starts refusing the second name only now, so the sweep meets a
        // genuine failure rather than a setup one.
        $ca->rejectDomain = 'broken.example.com';

        // Push both inside the window; the healthy one must renew, the other must
        // be reported rather than silently skipped.
        $this->db->table('pki_certificates')->whereIn('id', [(int) $good['cert_id'], (int) $bad['cert_id']])
            ->update(['valid_until' => date('Y-m-d H:i:s', time() + 5 * 86400)]);

        $sweep = $acme->renewDue(2, 0);

        $this->assertSame(2, $sweep['attempted']);
        $this->assertSame(1, $sweep['renewed']);
        $this->assertCount(1, $sweep['failed'], 'a renewal that failed must be reported, not skipped');
        // An unnamed certificate is named after the domain it covers, so a failed
        // renewal is identifiable without a second lookup.
        $this->assertSame("Let's Encrypt: broken.example.com", $sweep['failed'][0]['name']);
        $this->assertNotSame('', (string) $sweep['failed'][0]['message']);

        // The one that renewed is out of the window; the one that failed is still
        // due, so the next sweep will try it again.
        $this->assertSame(["Let's Encrypt: broken.example.com"], array_column($acme->due(), 'name'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function manager(AcmeStubCa $ca, FakeIpcClient $ipc): AcmeManager
    {
        return new AcmeManager(
            static fn (string $directory, ?string $key): Acme => new Acme($ca->transport(), $directory, $key),
            $ipc,
        );
    }

    /** An ACME certificate row, as `store()` would have written it. */
    private function insertAcmeCert(string $name, string $validUntil): int
    {
        return $this->insertCert($name, $validUntil, 'acme');
    }

    private function insertCert(string $name, string $validUntil, string $source): int
    {
        $this->db->table('pki_certificates')->insert([
            'ca_id'         => 1,
            'cert_type'     => 'server',
            'name'          => $name,
            'common_name'   => $name . '.example.com',
            'certificate'   => 'stub',
            'private_key'   => 'stub',
            'serial_number' => '1',
            'valid_until'   => $validUntil,
            'revoked'       => 0,
            'source'        => $source,
            'san_dns'       => $name . '.example.com',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function clearAcme(): void
    {
        if (! $this->db->tableExists('pki_acme_orders')) {
            return;
        }

        $this->db->table('pki_acme_orders')->emptyTable();
        $this->db->table('pki_acme_accounts')->emptyTable();
        $this->db->table('pki_certificates')->where('source', 'acme')->delete();
        $this->db->table('pki_authorities')->where('source', 'acme')->delete();

        foreach (glob(rtrim(WRITEPATH, '/\\') . '/acme-challenges/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}

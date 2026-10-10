<?php

declare(strict_types=1);

use App\Libraries\Acme;
use App\Libraries\AcmeManager;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\AcmeStubCa;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The ACME half of the Certificates page: the routes, the panel, and the one
 * endpoint that is deliberately reachable without a session.
 *
 * The public HTTP-01 endpoint is where this feature is most likely to be broken
 * in a way unit tests cannot see. A CA fetches it anonymously on port 80, so it
 * has to survive the *global* filters — an `auth` filter that answers with a
 * redirect to the login page would fail every order while every library test
 * still passed. The tests below drive it through the router with no session at
 * all, which is the only way that failure shows up.
 *
 * @internal
 */
final class CertificatesAcmeTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['cert.view', 'cert.create', 'cert.acme.manage'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAcme();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    protected function tearDown(): void
    {
        $this->clearAcme();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The public HTTP-01 endpoint
    // ------------------------------------------------------------------

    /**
     * The CA has no session, so this must answer with no authentication — and
     * with a real key authorization, not a login redirect.
     */
    public function testTheChallengeEndpointAnswersAnonymouslyWhileAnOrderIsPending(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);

        $begun = $acme->begin([
            'directory'      => Acme::LETSENCRYPT,
            'email'          => 'ops@example.com',
            'domains'        => 'vpn.example.com',
            'challenge_type' => 'http-01',
        ]);
        $this->assertTrue($begun['ok']);

        $token = $begun['challenges'][0]['token'];

        // No withSession(...) on purpose: this is the CA's request.
        $result = $this->get('.well-known/acme-challenge/' . $token);

        $result->assertOK();

        // The raw response, not the DOM-normalised body the harness offers: the
        // CA compares this byte for byte against `token.thumbprint`, so an extra
        // newline or a stray wrapper here is a failed validation.
        $body = (string) $result->response()->getBody();
        $this->assertSame($begun['challenges'][0]['value'], $body);
        $this->assertStringStartsWith('text/plain', (string) $result->response()->getHeaderLine('Content-Type'));

        // The answer is per-token and meaningless the moment the order ends, so
        // no cache may keep it: the framework adds its own directives on top.
        $cache = (string) $result->response()->getHeaderLine('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('no-cache', $cache);
    }

    public function testTheChallengeEndpointIs404ForAnythingItDoesNotServe(): void
    {
        // A token nobody wrote is indistinguishable from a file that is not there:
        // the answer must not reveal whether a token was ever valid.
        foreach (['tok-nothing-here', '.env', 'nope-env-file', str_repeat('a', 128)] as $token) {
            $result = $this->get('.well-known/acme-challenge/' . $token);

            $this->assertSame(404, $result->response()->getStatusCode(), "'{$token}' must not resolve");
        }

        // A name containing a separator cannot even match this route, which is the
        // first of the two things that make path traversal impossible here (the
        // second is the token grammar the manager enforces before reading).
        try {
            $this->get('.well-known/acme-challenge/..%2f..%2fapp%2fConfig%2fDatabase.php');
            $this->fail('a path with separators must not reach the challenge endpoint');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
            $this->assertStringContainsString('route', strtolower($e->getMessage()));
        }
    }

    public function testTheChallengeStopsBeingServedOnceTheOrderFinishes(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);

        $begun = $acme->request([
            'directory' => Acme::LETSENCRYPT,
            'email'     => 'ops@example.com',
            'domains'   => 'vpn.example.com',
        ], 3, 0);

        $this->assertTrue($begun['ok'], (string) ($begun['message'] ?? ''));

        $token = $this->db->table('pki_acme_orders')->where('id', (int) $begun['order_id'])->get()->getRowArray()['token'];

        $this->assertSame(404, $this->get('.well-known/acme-challenge/' . $token)->response()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // The authenticated actions
    // ------------------------------------------------------------------

    public function testRequestingAnHttp01CertificateThroughThePageIssuesAndStoresIt(): void
    {
        $ca = new AcmeStubCa();
        Services::injectMock('acme', $this->manager($ca));

        $result = $this->withSession($this->session())
            ->post('certificates/acme/request', $this->csrf([
                'directory'      => Acme::LETSENCRYPT,
                'email'          => 'ops@example.com',
                'domains'        => 'vpn.example.com',
                'challenge_type' => 'http-01',
                'cert_type'      => 'server',
                'name'           => 'VPN Portal',
            ]));

        $result->assertRedirect();

        $cert = $this->db->table('pki_certificates')->where('source', 'acme')->get()->getRowArray();
        $this->assertIsArray($cert, 'the issued certificate must be in the PKI store');
        $this->assertSame('VPN Portal', $cert['name']);
        $this->assertSame('vpn.example.com', $cert['common_name']);

        $this->withSession($this->session())->get('certificates')->assertSee('VPN Portal');
    }

    /** A DNS-01 request must hand back the records, not silently claim success. */
    public function testADns01RequestRedirectsWithTheRecordsToPublish(): void
    {
        $ca = new AcmeStubCa();
        Services::injectMock('acme', $this->manager($ca));

        $result = $this->withSession($this->session())
            ->post('certificates/acme/request', $this->csrf([
                'directory'      => Acme::LETSENCRYPT,
                'email'          => 'ops@example.com',
                'domains'        => 'vpn.example.com',
                'challenge_type' => 'dns-01',
            ]));

        $result->assertRedirect();
        $result->assertSessionHas('warning');

        $warning = (string) session()->getFlashdata('warning');
        $this->assertStringContainsString('_acme-challenge.vpn.example.com', $warning);

        $order = $this->db->table('pki_acme_orders')->get()->getRowArray();
        $this->assertSame('pending', $order['status']);

        // And the page offers the operator the button that continues it. The
        // harness normalises the body through the DOM, so the entity form is what
        // is present.
        $this->withSession($this->session())->get('certificates')->assertSee('Check &amp; finish');
    }

    public function testCheckingAnOrderFromThePageCompletesAndInstallsIt(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $begun = $acme->begin([
            'directory'      => Acme::LETSENCRYPT,
            'email'          => 'ops@example.com',
            'domains'        => 'vpn.example.com',
            'challenge_type' => 'dns-01',
        ]);
        $this->assertTrue($begun['ok']);

        $result = $this->withSession($this->session())->post('certificates/acme/check/' . (int) $begun['order_id'], $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame(1, $this->db->table('pki_certificates')->where('source', 'acme')->countAllResults());
        $this->assertSame('valid', $acme->order((int) $begun['order_id'])['status']);
    }

    public function testRenewingFromThePageReplacesTheCertificateInPlace(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $issued = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'vpn.example.com'], 3, 0);
        $this->assertTrue($issued['ok'], (string) ($issued['message'] ?? ''));
        $certId = (int) $issued['cert_id'];

        $result = $this->withSession($this->session())->post('certificates/acme/renew/' . $certId, $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame(1, $this->db->table('pki_certificates')->where('source', 'acme')->countAllResults());
        $this->assertNotSame('', (string) $this->db->table('pki_certificates')->where('id', $certId)->get()->getRowArray()['certificate']);
    }

    /** Asking a public CA for a certificate is its own capability. */
    public function testAUserWithoutTheAcmePermissionCannotReachTheActions(): void
    {
        $ca = new AcmeStubCa();
        Services::injectMock('acme', $this->manager($ca));

        $session = $this->session();
        $session['permissions']      = ['cert.view'];
        $session['user_permissions'] = ['cert.view'];

        $result = $this->withSession($session)->post('certificates/acme/request', $this->csrf([
            'directory' => Acme::LETSENCRYPT,
            'domains'   => 'vpn.example.com',
        ]));

        $result->assertRedirect();
        $this->assertSame(0, $this->db->table('pki_certificates')->where('source', 'acme')->countAllResults());
        $this->assertSame(0, $this->db->table('pki_acme_orders')->countAllResults());
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    public function testThePageRendersTheAcmePanelWithTheRenewalWindowAndDueCertificates(): void
    {
        $acme = $this->manager(new AcmeStubCa());
        Services::injectMock('acme', $acme);
        Services::injectMock('ipc', new FakeIpcClient());

        $issued = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'vpn.example.com', 'name' => 'VPN Portal'], 3, 0);
        $this->assertTrue($issued['ok'], (string) ($issued['message'] ?? ''));

        $this->db->table('pki_certificates')->where('id', (int) $issued['cert_id'])
            ->update(['valid_until' => date('Y-m-d H:i:s', time() + 5 * 86400)]);

        $result = $this->withSession($this->session())->get('certificates');

        $result->assertOK();
        $result->assertSee('ACME / Let\'s Encrypt');
        $result->assertSee('ACME Account');
        $result->assertSee('Due for renewal');
        $result->assertSee('Renew now');
        // The window is stated in the UI, so the operator knows why a certificate
        // with three weeks left is listed as due.
        $result->assertSee((string) AcmeManager::RENEW_WINDOW_DAYS);
        $result->assertSee('vpn.example.com');
    }

    /**
     * The panel is gated, not merely disabled: a user who cannot manage ACME
     * should not be shown a form that answers with an access-denied redirect.
     */
    public function testThePanelIsAbsentWithoutThePermissionAndNoAccountKeyIsRendered(): void
    {
        $acme = $this->manager(new AcmeStubCa());
        Services::injectMock('acme', $acme);
        Services::injectMock('ipc', new FakeIpcClient());

        $acme->registerAccount(Acme::LETSENCRYPT, 'ops@example.com');
        $key = (string) $acme->account(Acme::LETSENCRYPT)['private_key'];
        $this->assertNotSame('', $key);

        $session = $this->session();
        $session['permissions']      = ['cert.view'];
        $session['user_permissions'] = ['cert.view'];

        $result = $this->withSession($session)->get('certificates');

        $result->assertOK();
        $result->assertDontSee('ACME Account');
        $result->assertDontSee('Renew now');

        // The account key is the whole account: it must never reach a view. The
        // page's *other* modals do show `BEGIN PRIVATE KEY` as paste-format help,
        // so the check is against this key's own material.
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString(substr($key, 64, 32), $body, 'the account key must not be rendered');
        $this->assertStringNotContainsString(substr($key, 200, 32), $body, 'the account key must not be rendered');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function manager(AcmeStubCa $ca): AcmeManager
    {
        return new AcmeManager(
            static fn (string $directory, ?string $key): Acme => new Acme($ca->transport(), $directory, $key),
            new FakeIpcClient(['cert.reload' => ['result' => ['success' => true], 'error' => null]]),
        );
    }

    /** @return array<string, mixed> */
    private function session(): array
    {
        return [
            'user_id'          => 'usr_acme_tester',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function csrf(array $params): array
    {
        return $params + ['csrf_test_name' => service('security')->getHash()];
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

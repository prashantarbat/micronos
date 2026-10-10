<?php

use App\Libraries\InterceptionCa;
use App\Libraries\NftablesCompiler;
use App\Libraries\SuricataCompiler;
use App\Libraries\X509;
use App\Models\IdsSettingsModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Deep Packet Inspection enforcement.
 *
 * Two separate DPI paths share a failure mode: the config compiler emits a
 * coherent engine configuration but the plumbing that feeds it is missing, so
 * the feature looks enabled while inspecting nothing. These tests pin down the
 * half that used to be absent:
 *
 *  1. Suricata inline IPS — the nftables ruleset must divert accepted packets
 *     into the Netfilter queue the engine's `nfq:` block reads.
 *  2. Squid SSL-Bump — the interception CA served to clients must be a real,
 *     usable CA rather than a placeholder that can never validate a session.
 *
 * @internal
 */
final class DpiEnforcementTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = ['webfilter.view', 'webfilter.policy.edit'];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        $this->db->table('pki_authorities')->where('common_name', InterceptionCa::COMMON_NAME)->delete();

        // Make sure a settings row exists before each test mutates it.
        (new IdsSettingsModel())->getSettings();
    }

    private function sessionData(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    private function setIdsSettings(array $overrides): void
    {
        (new IdsSettingsModel())->update(1, $overrides);
    }

    // ------------------------------------------------------------------
    // Suricata inline IPS — NFQUEUE diversion
    // ------------------------------------------------------------------

    public function testNftablesDivertsAcceptedTrafficToTheIpsQueue(): void
    {
        $this->setIdsSettings(['enabled' => 1, 'mode' => 'ips', 'nfqueue_num' => 7, 'queue_fail_mode' => 'open']);

        $ruleset = (new NftablesCompiler())->compile();

        $this->assertStringContainsString('table inet micron_ids {', $ruleset);
        $this->assertStringContainsString('queue num 7 bypass;', $ruleset);
        // Forwarded traffic and traffic to the appliance itself are both fed to
        // the engine, after the filter chains have had their say.
        $this->assertStringContainsString('type filter hook forward priority 10; policy accept;', $ruleset);
        $this->assertStringContainsString('type filter hook input priority 10; policy accept;', $ruleset);
    }

    public function testQueueDiversionRunsAfterTheFilterDecision(): void
    {
        $this->setIdsSettings(['enabled' => 1, 'mode' => 'ips', 'nfqueue_num' => 0, 'queue_fail_mode' => 'open']);

        $ruleset = (new NftablesCompiler())->compile();

        // The filter table drops at `priority filter` (= 0); diverting at a
        // higher number means only already-accepted packets reach the scanner.
        $filterPos = strpos($ruleset, 'type filter hook forward priority filter; policy drop;');
        $idsPos    = strpos($ruleset, 'type filter hook forward priority 10; policy accept;');

        $this->assertNotFalse($filterPos, 'The filter forward chain should be present.');
        $this->assertNotFalse($idsPos, 'The IDS forward chain should be present.');
        $this->assertGreaterThan($filterPos, $idsPos, 'Diversion must be emitted after the filter chain.');
    }

    public function testPassiveIdsModeEmitsNoQueueDiversion(): void
    {
        $this->setIdsSettings(['enabled' => 1, 'mode' => 'ids']);

        $ruleset = (new NftablesCompiler())->compile();

        $this->assertStringNotContainsString('micron_ids', $ruleset);
        $this->assertStringNotContainsString('queue num', $ruleset);
    }

    public function testDisabledEngineEmitsNoQueueDiversion(): void
    {
        $this->setIdsSettings(['enabled' => 0, 'mode' => 'ips']);

        $ruleset = (new NftablesCompiler())->compile();

        $this->assertStringNotContainsString('queue num', $ruleset);
    }

    public function testFailClosedQueueOmitsTheBypassFlag(): void
    {
        $this->setIdsSettings(['enabled' => 1, 'mode' => 'ips', 'nfqueue_num' => 3, 'queue_fail_mode' => 'closed']);

        $ruleset = (new NftablesCompiler())->compile();

        $this->assertStringContainsString('queue num 3;', $ruleset);
        $this->assertStringNotContainsString('queue num 3 bypass', $ruleset);
    }

    /** The engine side of the same contract: inline mode reads an NFQ. */
    public function testSuricataReadsTheQueueOnlyInInlineMode(): void
    {
        $this->setIdsSettings(['enabled' => 1, 'mode' => 'ips']);
        $inline = (new SuricataCompiler())->compile();
        $this->assertStringContainsString('nfq:', $inline);
        $this->assertStringContainsString('copy-mode: ips', $inline);

        $this->setIdsSettings(['mode' => 'ids']);
        $passive = (new SuricataCompiler())->compile();
        $this->assertStringNotContainsString('nfq:', $passive);
    }

    // ------------------------------------------------------------------
    // Squid SSL-Bump — interception CA
    // ------------------------------------------------------------------

    public function testInterceptionCaIsGeneratedAsARealCertificateAuthority(): void
    {
        $ca = InterceptionCa::ensure();

        $this->assertNotNull($ca, 'OpenSSL must be able to generate the interception CA.');
        $this->assertSame(InterceptionCa::COMMON_NAME, $ca['common_name']);
        $this->assertTrue(X509::looksLikeCertificate((string) $ca['certificate']));
        $this->assertNotEmpty($ca['private_key']);

        $parsed = X509::parseCertificate((string) $ca['certificate']);
        $this->assertNotNull($parsed, 'The generated certificate must parse as X.509.');
        $this->assertSame(InterceptionCa::COMMON_NAME, $parsed['common_name']);
        $this->assertTrue($parsed['self_signed'], 'An interception CA must be self-signed.');

        // A CA that cannot sign the per-host certificates is useless to Squid,
        // so basicConstraints CA:TRUE is the property that actually matters.
        $this->assertTrue($parsed['is_ca'], 'The certificate must carry basicConstraints CA:TRUE.');
    }

    public function testInterceptionCaIsReusedRatherThanRegenerated(): void
    {
        $first  = InterceptionCa::ensure();
        $second = InterceptionCa::ensure();

        $this->assertNotNull($first);
        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame(
            1,
            $this->db->table('pki_authorities')->where('common_name', InterceptionCa::COMMON_NAME)->countAllResults()
        );
    }

    public function testDownloadCaServesTheRealCertificateNotAPlaceholder(): void
    {
        $result = $this->withSession($this->sessionData())->get('/webfilter/download-ca');

        $result->assertStatus(200);

        $body = (string) $result->getBody();
        $this->assertStringContainsString('BEGIN CERTIFICATE', $body);
        $this->assertTrue(X509::looksLikeCertificate($body));

        // The old hardcoded stub — a client installing it could never validate
        // an intercepted session, so it must never be served again.
        $this->assertStringNotContainsString('MIIClzCCAX8CCQCT3V0Y56zS1jANBgkqhkiG9w0BAQsFADBL', $body);
    }

    public function testProvisionCaEndpointGeneratesAndRecordsTheCa(): void
    {
        $payload = ['csrf_test_name' => service('security')->getHash()];

        $result = $this->withSession($this->sessionData())->post('/webfilter/provision-ca', $payload);

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('webfilter?tab=ssl'));

        $this->assertSame(
            1,
            $this->db->table('pki_authorities')->where('common_name', InterceptionCa::COMMON_NAME)->countAllResults()
        );
    }
}

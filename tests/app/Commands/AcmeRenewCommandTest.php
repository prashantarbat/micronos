<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use App\Libraries\Acme;
use App\Libraries\AcmeManager;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\AcmeStubCa;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The scheduled half of the ACME panel (`micron:acme-renew`).
 *
 * A renewal task is only worth scheduling if it is safe to run when nobody is
 * watching, so the assertions here are about the things that make it safe:
 *
 *  - a `--dry-run` contacts no CA and writes nothing;
 *  - a certificate that is *not* due is left alone, so a daily task cannot burn
 *    a CA's rate limit;
 *  - a DNS-01 certificate is reported rather than opened, because a scheduled run
 *    has no way to publish the TXT record and an order it cannot finish is only a
 *    mess to clean up;
 *  - a failure exits non-zero, so cron mail and monitors see it.
 *
 * The CA is stubbed at the transport, so the whole issuance path still runs.
 *
 * @internal
 */
final class AcmeRenewCommandTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** @var array<string, mixed>|null the renewal job row before this test */
    private ?array $cronRow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAcme();

        // The test connection is shared for the whole process, so a run recorded
        // here would otherwise leak into whatever asserts the schedule later.
        $this->cronRow = $this->db->table('cron_jobs')->where('id', 'cron_acme_renew')->get()->getRowArray();
    }

    protected function tearDown(): void
    {
        $this->clearAcme();

        if (is_array($this->cronRow)) {
            $this->db->table('cron_jobs')->where('id', 'cron_acme_renew')->update([
                'last_run_at' => $this->cronRow['last_run_at'] ?? null,
                'last_status' => $this->cronRow['last_status'] ?? null,
                'last_output' => $this->cronRow['last_output'] ?? null,
            ]);
        }

        parent::tearDown();
    }

    public function testTheCommandIsRegisteredAndTheJobIsScheduled(): void
    {
        $this->assertArrayHasKey('micron:acme-renew', service('commands')->getCommands());

        $job = $this->db->table('cron_jobs')->where('id', 'cron_acme_renew')->get()->getRowArray();
        $this->assertIsArray($job, 'the renewal sweep must be scheduled by the migration');
        $this->assertSame('php spark micron:acme-renew', $job['command']);
        $this->assertSame(1, (int) $job['enabled']);

        // A task that only runs when something remembers is not a schedule.
        $this->assertSame('*', $job['day_of_month']);
        $this->assertSame('*', $job['day_of_week']);
    }

    /**
     * A dry run must not reach the CA. Injecting a stub and asserting it saw no
     * request proves the issuance path was never entered — a stronger statement
     * than "nothing changed in the database".
     */
    public function testDryRunListsWhatIsDueWithoutContactingTheCa(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $this->insertAcmeCert('due-soon', date('Y-m-d H:i:s', time() + 3 * 86400));
        $this->insertAcmeCert('not-yet', date('Y-m-d H:i:s', time() + 200 * 86400));

        $exit   = $this->runSweep(['dry-run' => null, 'json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(['due-soon'], array_column($report['due'], 'name'));
        $this->assertSame([], $ca->requests, 'a dry run must not talk to the CA');
        $this->assertSame(0, $report['renewed']);
    }

    public function testADueCertificateIsRenewedInPlaceAndTheRunExitsClean(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $issued = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'vpn.example.com', 'name' => 'VPN Portal'], 3, 0);
        $this->assertTrue($issued['ok'], (string) ($issued['message'] ?? ''));
        $certId = (int) $issued['cert_id'];
        $before = $this->db->table('pki_certificates')->where('id', $certId)->get()->getRowArray();

        $this->makeDue($certId, 4);

        $exit   = $this->runSweep(['json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertSame(1, $report['renewed']);
        $this->assertSame([], $report['failed']);

        $after = $this->db->table('pki_certificates')->where('id', $certId)->get()->getRowArray();
        $this->assertSame(1, $this->db->table('pki_certificates')->where('source', 'acme')->countAllResults(), 'the renewal must not add a second certificate');
        $this->assertSame('VPN Portal', $after['name']);
        $this->assertNotSame($before['serial_number'], $after['serial_number']);
    }

    /** A certificate with months left must be untouched, however often this runs. */
    public function testACertificateOutsideTheWindowIsNeverRenewed(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $issued = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'vpn.example.com'], 3, 0);
        $this->assertTrue($issued['ok'], (string) ($issued['message'] ?? ''));

        $requestsAfterIssue = count($ca->requests);

        $exit   = $this->runSweep(['json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit);
        $this->assertSame([], $report['due']);
        $this->assertSame(0, $report['renewed']);
        $this->assertSame($requestsAfterIssue, count($ca->requests), 'a certificate outside the window must not cause any CA traffic');
    }

    public function testAWindowCanBeWidenedButNotTheDefault(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $this->insertAcmeCert('due-in-40-days', date('Y-m-d H:i:s', time() + 40 * 86400));

        $this->assertSame([], $this->jsonFor(['dry-run' => null, 'json' => null])['due']);

        $widened = $this->jsonFor(['dry-run' => null, 'json' => null, 'window' => '60']);
        $this->assertSame(['due-in-40-days'], array_column($widened['due'], 'name'));
        $this->assertSame(60, $widened['window_days']);
    }

    /**
     * DNS-01 cannot be completed by a scheduled run: it would open an order, fail
     * to publish the record and leave a pending row behind on every run forever.
     */
    public function testADnsCertificateIsReportedRatherThanOpened(): void
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

        $certId = $this->insertAcmeCert('dns-cert', date('Y-m-d H:i:s', time() + 2 * 86400));
        $this->db->table('pki_acme_orders')->where('id', (int) $begun['order_id'])->update(['cert_id' => $certId]);

        $requestsBefore = count($ca->requests);

        $exit   = $this->runSweep(['json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_SUCCESS, $exit, 'something needing a human is not a failure of the task');
        $this->assertSame(0, $report['renewed']);
        $this->assertSame([], $report['failed']);
        $this->assertSame(['dns-cert'], array_column($report['skipped'], 'name'));
        $this->assertSame('dns_01', $report['skipped'][0]['reason']);
        $this->assertSame($requestsBefore, count($ca->requests), 'no order may be opened for a DNS-01 certificate');
    }

    /**
     * A failed renewal is the one thing an external monitor has to be able to see,
     * so it both exits non-zero and lands in the task's history.
     */
    public function testAFailedRenewalExitsNonZeroAndIsRecordedInTheTaskHistory(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $issued = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'vpn.example.com', 'name' => 'VPN Portal'], 3, 0);
        $this->assertTrue($issued['ok'], (string) ($issued['message'] ?? ''));
        $this->makeDue((int) $issued['cert_id'], 2);

        // The CA starts refusing the order, so the renewal meets a real failure.
        $ca->rejectDomain = 'vpn.example.com';

        $exit   = $this->runSweep(['json' => null]);
        $report = $this->json();

        $this->assertSame(EXIT_ERROR, $exit, 'a failed renewal must be visible to a monitor');
        $this->assertSame(0, $report['renewed']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(Acme::REASON_ORDER_FAILED, $report['failed'][0]['reason']);
        $this->assertStringContainsString('vpn.example.com', $report['failed'][0]['message']);

        // The certificate was left alone rather than half-replaced.
        $cert = $this->db->table('pki_certificates')->where('id', (int) $issued['cert_id'])->get()->getRowArray();
        $this->assertSame('VPN Portal', $cert['name']);
        $this->assertNotSame('', (string) $cert['certificate']);

        $job = $this->db->table('cron_jobs')->where('id', 'cron_acme_renew')->get()->getRowArray();
        $this->assertSame('error', $job['last_status']);
        $this->assertStringContainsString('VPN Portal', (string) $job['last_output']);
        $this->assertStringContainsString('acme renewal:', (string) $job['last_output']);
    }

    /** `--id` narrows a run, so one certificate can be retried on its own. */
    public function testAnExplicitIdLimitsTheRunToThatCertificate(): void
    {
        $ca   = new AcmeStubCa();
        $acme = $this->manager($ca);
        Services::injectMock('acme', $acme);

        $first  = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'a.example.com'], 3, 0);
        $second = $acme->request(['directory' => Acme::LETSENCRYPT, 'email' => 'ops@example.com', 'domains' => 'b.example.com'], 3, 0);
        $this->assertTrue($first['ok'] && $second['ok']);

        $this->makeDue((int) $first['cert_id'], 3);
        $this->makeDue((int) $second['cert_id'], 3);

        $report = $this->jsonFor(['json' => null, 'id' => (string) $first['cert_id']]);

        $this->assertSame([(int) $first['cert_id']], array_column($report['due'], 'id'));
        $this->assertSame(1, $report['renewed']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     */
    private function runSweep(array $params): int
    {
        $this->resetStreamFilterBuffer();

        return (int) service('commands')->run('micron:acme-renew', $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function jsonFor(array $params): array
    {
        $this->runSweep($params);

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode(trim($this->getStreamFilterBuffer()), true);

        $this->assertIsArray($decoded, 'the command must emit well-formed JSON');

        return $decoded;
    }

    private function manager(AcmeStubCa $ca): AcmeManager
    {
        return new AcmeManager(
            static fn (string $directory, ?string $key): Acme => new Acme($ca->transport(), $directory, $key),
            new FakeIpcClient(['cert.reload' => ['result' => ['success' => true], 'error' => null]]),
        );
    }

    /** Push a certificate inside the renewal window. */
    private function makeDue(int $certId, int $daysLeft): void
    {
        $this->db->table('pki_certificates')->where('id', $certId)
            ->update(['valid_until' => date('Y-m-d H:i:s', time() + $daysLeft * 86400)]);
    }

    private function insertAcmeCert(string $name, string $validUntil): int
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
            'source'        => 'acme',
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

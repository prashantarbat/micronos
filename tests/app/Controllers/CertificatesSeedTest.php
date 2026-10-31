<?php

namespace Tests\App\Controllers;

use App\Controllers\Certificates;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The PKI a fresh appliance gets on its first visit to Certificates: a real
 * Root CA and real certificates it signs, never placeholder PEM text.
 */
final class CertificatesSeedTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['pki_certificates', 'pki_authorities'] as $t) {
            $this->saved[$t] = $this->db->table($t)->get()->getResultArray();
            $this->db->table($t)->emptyTable();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $t => $rows) {
            $this->db->table($t)->emptyTable();
            if ($rows !== []) {
                $this->db->table($t)->insertBatch($rows);
            }
        }
        parent::tearDown();
    }

    public function testTheDefaultPkiIsRealAndSignedByTheDefaultCa(): void
    {
        $seed = new \ReflectionMethod(Certificates::class, 'seedInitialPki');
        $seed->invoke(new Certificates());

        $cas = $this->db->table('pki_authorities')->get()->getResultArray();
        $this->assertCount(1, $cas);
        $caCert = openssl_x509_read($cas[0]['certificate']);
        $this->assertNotFalse($caCert, 'the CA certificate parses');
        $this->assertTrue(openssl_x509_check_private_key($caCert, $cas[0]['private_key']), 'the CA key matches');

        $certs = $this->db->table('pki_certificates')->orderBy('serial_number')->get()->getResultArray();
        $this->assertSame(['Web GUI HTTPS Certificate', 'OpenVPN Server Authority Cert'], array_column($certs, 'name'));
        foreach ($certs as $c) {
            $x509 = openssl_x509_read($c['certificate']);
            $this->assertNotFalse($x509, "{$c['name']} parses");
            $this->assertTrue(openssl_x509_check_private_key($x509, $c['private_key']), "{$c['name']} has its own matching key");
            $this->assertNotSame($cas[0]['private_key'], $c['private_key'], "{$c['name']} does not reuse the CA key");
            $this->assertSame(1, openssl_x509_verify($x509, openssl_pkey_get_public($caCert)), "{$c['name']} is signed by the CA");
            $this->assertStringContainsString('DNS:' . $c['common_name'], (string) (openssl_x509_parse($x509)['extensions']['subjectAltName'] ?? ''));
        }
    }
}

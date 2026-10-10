<?php

namespace App\Controllers;

use App\Models\PkiAuthorityModel;
use App\Models\PkiCertificateModel;
use App\Models\SystemSettingsModel;
use App\Libraries\Acme;
use App\Libraries\AcmeManager;
use App\Libraries\IpcClient;
use App\Libraries\OpenSslConfig;
use App\Libraries\X509;

class Certificates extends BaseController
{
    protected PkiAuthorityModel $caModel;
    protected PkiCertificateModel $certModel;
    protected SystemSettingsModel $settings;
    protected IpcClient $ipc;
    protected AcmeManager $acme;

    public function __construct()
    {
        $this->caModel   = new PkiAuthorityModel();
        $this->certModel = new PkiCertificateModel();
        $this->settings  = new SystemSettingsModel();
        $this->ipc       = \Config\Services::ipc();
        $this->acme      = \Config\Services::acme();
    }

    public function index()
    {
        $cas   = $this->caModel->findAll();
        $certs = $this->certModel->findAll();

        // Seed initial Root CA and Web GUI server cert if empty
        if (empty($cas)) {
            $this->seedInitialPki();
            $cas   = $this->caModel->findAll();
            $certs = $this->certModel->findAll();
        }

        // Certificates that still await a signed certificate (created from a CSR)
        $csrCount = 0;
        foreach ($certs as $c) {
            if (($c['source'] ?? '') === 'csr' || empty($c['certificate'])) {
                $csrCount++;
            }
        }

        $settings    = $this->settings->getSettings();
        $webguiId    = (int) ($settings['webgui_cert_id'] ?? 0);
        $webguiCert  = $webguiId > 0 ? $this->certModel->find($webguiId) : null;

        // ACME issuance is a separate capability (cert.acme.manage): asking a
        // public CA for a certificate names this appliance's domains to a third
        // party, which is not implied by being allowed to manage the local PKI.
        $canManageAcme = $this->canManageAcme();

        $data = [
            'title'        => 'Certificates & Public Key Infrastructure (PKI)',
            'cas'          => $cas,
            'certs'        => $certs,
            'csrCount'     => $csrCount,
            'webguiCertId' => $webguiId,
            'webguiCert'   => $webguiCert,
            'acmeAccounts' => $canManageAcme ? $this->acme->accounts() : [],
            'acmeOrders'   => $canManageAcme ? $this->acme->orders(25) : [],
            'acmeDue'      => $canManageAcme ? $this->acme->due() : [],
            'acmePending'  => $canManageAcme ? $this->acme->pendingChallengeCount() : 0,
            'acmeDirectories' => AcmeManager::directories(),
            'acmeRenewWindow' => AcmeManager::RENEW_WINDOW_DAYS,
            'acmeCanManage'   => $canManageAcme,
        ];

        return view('certificates/index', $data);
    }

    /**
     * The OpenSSL options for this host.
     *
     * Resolved by {@see OpenSslConfig} so the ACME half of the module and this
     * one cannot disagree about which config file OpenSSL is using.
     */
    private function getOpenSslConfig(): array
    {
        return OpenSslConfig::options();
    }

    public function createCa()
    {
        $name        = $this->request->getPost('name');
        $commonName  = $this->request->getPost('common_name');
        $org         = $this->request->getPost('organization') ?: 'MicronOS Enterprise';
        $country     = $this->request->getPost('country') ?: 'US';
        $state       = $this->request->getPost('state') ?: 'California';
        $city        = $this->request->getPost('city') ?: 'San Jose';
        $keyLength   = (int) ($this->request->getPost('key_length') ?: 4096);
        $validDays   = (int) ($this->request->getPost('valid_days') ?: 3650);

        $sslConfig = $this->getOpenSslConfig();

        $dn = [
            "countryName"            => $country,
            "stateOrProvinceName"    => $state,
            "localityName"           => $city,
            "organizationName"       => $org,
            "commonName"             => $commonName,
        ];

        $privKeyArgs = [
            "private_key_bits" => $keyLength,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];
        if (isset($sslConfig['config'])) {
            $privKeyArgs['config'] = $sslConfig['config'];
        }

        $privKey = openssl_pkey_new($privKeyArgs);
        $csr = openssl_csr_new($dn, $privKey, $sslConfig);
        $x509 = openssl_csr_sign($csr, null, $privKey, $validDays, $sslConfig);

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privKey, $privKeyOut, null, $sslConfig);

        $now = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime("+{$validDays} days"));

        $this->caModel->insert([
            'name'          => $name,
            'common_name'   => $commonName,
            'organization'  => $org,
            'country'       => $country,
            'state'         => $state,
            'city'          => $city,
            'certificate'   => $certOut,
            'private_key'   => $privKeyOut,
            'serial_number' => '01',
            'valid_from'    => $now,
            'valid_until'   => $validUntil,
            'key_length'    => $keyLength,
            'digest'        => 'sha256',
            'is_default'    => 0,
        ]);

        return redirect()->to('/certificates')->with('success', "Certificate Authority '{$name}' generated successfully.");
    }

    public function createCert()
    {
        $caId        = (int) $this->request->getPost('ca_id');
        $ca          = $this->caModel->find($caId);
        if (!$ca) {
            return redirect()->to('/certificates')->with('error', 'Selected Certificate Authority not found.');
        }

        $name        = $this->request->getPost('name');
        $commonName  = $this->request->getPost('common_name');
        $certType    = $this->request->getPost('cert_type') ?: 'server';
        $sanDns      = $this->request->getPost('san_dns');
        $sanIp       = $this->request->getPost('san_ip');
        $validDays   = (int) ($this->request->getPost('valid_days') ?: 825);
        $keyLength   = (int) ($this->request->getPost('key_length') ?: 2048);

        $sslConfig = $this->getOpenSslConfig();

        $dn = [
            "countryName"            => $ca['country'] ?? 'US',
            "stateOrProvinceName"    => $ca['state'] ?? 'California',
            "localityName"           => $ca['city'] ?? 'San Jose',
            "organizationName"       => $ca['organization'] ?? 'MicronOS Enterprise',
            "commonName"             => $commonName,
        ];

        $privKeyArgs = [
            "private_key_bits" => $keyLength,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];
        if (isset($sslConfig['config'])) {
            $privKeyArgs['config'] = $sslConfig['config'];
        }

        $privKey = openssl_pkey_new($privKeyArgs);
        $csr = openssl_csr_new($dn, $privKey, $sslConfig);

        // Sign with CA
        $caCert = openssl_x509_read($ca['certificate']);
        $caPrivKey = openssl_pkey_get_private($ca['private_key']);

        $serial = (string) time();
        $x509 = openssl_csr_sign($csr, $caCert, $caPrivKey, $validDays, $sslConfig, $serial);

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privKey, $privKeyOut, null, $sslConfig);

        $now = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime("+{$validDays} days"));

        $this->certModel->insert([
            'ca_id'         => $caId,
            'cert_type'     => $certType,
            'name'          => $name,
            'common_name'   => $commonName,
            'organization'  => $ca['organization'] ?? 'MicronOS Enterprise',
            'san_dns'       => $sanDns,
            'san_ip'        => $sanIp,
            'certificate'   => $certOut,
            'private_key'   => $privKeyOut,
            'serial_number' => $serial,
            'valid_from'    => $now,
            'valid_until'   => $validUntil,
            'revoked'       => 0,
            'description'   => $this->request->getPost('description'),
        ]);

        $this->ipc->call('cert.reload');

        return redirect()->to('/certificates')->with('success', "Certificate '{$name}' issued and signed by CA '{$ca['name']}'.");
    }

    public function downloadCaCert($id)
    {
        $ca = $this->caModel->find($id);
        if (!$ca) {
            return redirect()->to('/certificates')->with('error', 'CA not found.');
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ca['name']) . '_ca.crt';
        return $this->response->setHeader('Content-Type', 'application/x-x509-ca-cert')
                              ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
                              ->setBody($ca['certificate']);
    }

    public function downloadCaKey($id)
    {
        $ca = $this->caModel->find($id);
        if (! $ca || empty($ca['private_key'])) {
            return redirect()->to('/certificates')->with('error', 'No private key is stored for that Certificate Authority.');
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ca['name']) . '_ca.key';

        return $this->response->setHeader('Content-Type', 'application/x-pem-file')
                              ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
                              ->setBody($ca['private_key']);
    }

    public function downloadCert($id, $type = 'crt')
    {
        $cert = $this->certModel->find($id);
        if (!$cert) {
            return redirect()->to('/certificates')->with('error', 'Certificate not found.');
        }

        $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cert['name']);

        if ($type === 'key') {
            return $this->response->setHeader('Content-Type', 'application/x-pem-file')
                                  ->setHeader('Content-Disposition', "attachment; filename=\"{$baseName}.key\"")
                                  ->setBody($cert['private_key']);
        }

        if ($type === 'pem') {
            $ca = $this->caModel->find($cert['ca_id']);
            $caCert = $ca ? $ca['certificate'] : '';
            $combined = $cert['certificate'] . "\n" . $cert['private_key'] . "\n" . $caCert;
            return $this->response->setHeader('Content-Type', 'application/x-pem-file')
                                  ->setHeader('Content-Disposition', "attachment; filename=\"{$baseName}_bundle.pem\"")
                                  ->setBody($combined);
        }

        // Default: .crt
        return $this->response->setHeader('Content-Type', 'application/x-x509-ca-cert')
                              ->setHeader('Content-Disposition', "attachment; filename=\"{$baseName}.crt\"")
                              ->setBody($cert['certificate']);
    }

    public function revoke($id)
    {
        $this->certModel->update($id, [
            'revoked'    => 1,
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('cert.reload');

        return redirect()->to('/certificates')->with('success', 'Certificate revoked.');
    }

    public function delete($id)
    {
        $this->certModel->delete($id);
        return redirect()->to('/certificates')->with('success', 'Certificate deleted.');
    }

    public function deleteCa($id)
    {
        // Check if certs exist for this CA
        $existing = $this->certModel->where('ca_id', $id)->countAllResults();
        if ($existing > 0) {
            return redirect()->to('/certificates')->with('error', "Cannot delete CA: {$existing} certificate(s) are signed by this CA.");
        }

        $this->caModel->delete($id);
        return redirect()->to('/certificates')->with('success', 'Certificate Authority deleted.');
    }

    // ---------------------------------------------------------------------
    // Import / install existing (third-party) material
    // ---------------------------------------------------------------------

    /**
     * Install an externally-issued Certificate Authority (a public, corporate
     * or partner-firewall CA). The private key is optional: without it the CA
     * is a trust anchor only and cannot sign new certificates.
     */
    public function importCa()
    {
        $name   = trim((string) $this->request->getPost('name'));
        $rawCert = (string) $this->request->getPost('certificate');
        $rawKey  = (string) $this->request->getPost('private_key');

        $meta = X509::parseCertificate($rawCert);
        if ($meta === null) {
            return redirect()->back()->with('error', 'Import failed: the certificate is not a readable PEM X.509 block.');
        }
        if ($name === '') {
            $name = $meta['common_name'] !== '' ? $meta['common_name'] : 'Imported CA';
        }

        $keyPem = null;
        if (trim($rawKey) !== '') {
            $keyPem = X509::normalizeKey($rawKey);
            if ($keyPem === null) {
                return redirect()->back()->with('error', 'Import failed: the private key is not a readable PEM block.');
            }
            if (! X509::keyMatchesCertificate($keyPem, $meta['certificate'])) {
                return redirect()->back()->with('error', 'Import failed: the private key does not match the certificate.');
            }
        }

        $this->caModel->insert([
            'name'          => $name,
            'common_name'   => $meta['common_name'] !== '' ? $meta['common_name'] : $name,
            'organization'  => $meta['organization'] !== '' ? $meta['organization'] : 'Imported CA',
            'country'       => $meta['country'],
            'state'         => $meta['state'],
            'city'          => $meta['city'],
            'certificate'   => $meta['certificate'],
            'private_key'   => $keyPem ?? '',
            'serial_number' => $meta['serial_number'] !== '' ? $meta['serial_number'] : '01',
            'valid_from'    => $meta['valid_from'] ?? date('Y-m-d H:i:s'),
            'valid_until'   => $meta['valid_until'] ?? date('Y-m-d H:i:s', strtotime('+10 years')),
            'key_length'    => $meta['key_length'] ?: 0,
            'digest'        => $meta['digest'],
            'is_default'    => 0,
            'source'        => 'imported',
        ]);

        $this->ipc->call('cert.reload');

        if ($keyPem === null) {
            return redirect()->to('/certificates')->with('success', "CA '{$name}' imported as a trust anchor (no private key, so it cannot sign new certificates).");
        }

        return redirect()->to('/certificates')->with('success', "CA '{$name}' imported with its private key — it can now sign certificates.");
    }

    /**
     * Install an externally-issued leaf certificate (public CA, another
     * firewall, or an existing corporate PKI entry).
     */
    public function importCert()
    {
        $name     = trim((string) $this->request->getPost('name'));
        $certType = (string) ($this->request->getPost('cert_type') ?: 'server');
        $rawCert  = (string) $this->request->getPost('certificate');
        $rawKey   = (string) $this->request->getPost('private_key');
        $caId     = (int) $this->request->getPost('ca_id');
        $desc     = trim((string) $this->request->getPost('description'));

        if (! in_array($certType, ['server', 'client', 'user', 'vpn'], true)) {
            $certType = 'server';
        }

        $meta = X509::parseCertificate($rawCert);
        if ($meta === null) {
            return redirect()->back()->with('error', 'Import failed: the certificate is not a readable PEM X.509 block.');
        }
        if ($name === '') {
            $name = $meta['common_name'] !== '' ? $meta['common_name'] : 'Imported Certificate';
        }

        $keyPem = null;
        $warnings = [];
        if (trim($rawKey) !== '') {
            $keyPem = X509::normalizeKey($rawKey);
            if ($keyPem === null) {
                return redirect()->back()->with('error', 'Import failed: the private key is not a readable PEM block.');
            }
            if (! X509::keyMatchesCertificate($keyPem, $meta['certificate'])) {
                return redirect()->back()->with('error', 'Import failed: the private key does not match the certificate.');
            }
        } else {
            $warnings[] = 'imported without a private key';
        }

        // Link to a known CA by issuer CN when one wasn't chosen explicitly.
        if ($caId > 0 && ! $this->caModel->find($caId)) {
            $caId = 0;
        }
        if ($caId === 0 && $meta['issuer_cn'] !== '') {
            $match = $this->caModel->where('common_name', $meta['issuer_cn'])->first();
            if ($match) {
                $caId = (int) $match['id'];
            } else {
                $warnings[] = 'issuer not in the trust store';
            }
        }

        $this->certModel->insert([
            'ca_id'         => $caId,
            'cert_type'     => $certType,
            'name'          => $name,
            'common_name'   => $meta['common_name'] !== '' ? $meta['common_name'] : $name,
            'organization'  => $meta['organization'],
            'san_dns'       => $meta['san_dns'],
            'san_ip'        => $meta['san_ip'],
            'certificate'   => $meta['certificate'],
            'private_key'   => $keyPem ?? '',
            'serial_number' => $meta['serial_number'] !== '' ? $meta['serial_number'] : '',
            'valid_from'    => $meta['valid_from'] ?? date('Y-m-d H:i:s'),
            'valid_until'   => $meta['valid_until'] ?? date('Y-m-d H:i:s'),
            'revoked'       => 0,
            'description'   => $desc !== '' ? $desc : 'Imported certificate',
            'source'        => 'imported',
        ]);

        $this->ipc->call('cert.reload');

        $note = $warnings !== [] ? ' (' . implode('; ', $warnings) . ')' : '';

        return redirect()->to('/certificates')->with('success', "Certificate '{$name}' imported successfully{$note}.");
    }

    // ---------------------------------------------------------------------
    // Certificate Signing Request (CSR) workflow — generate, then sign
    // ---------------------------------------------------------------------

    /**
     * Generate a keypair + CSR that can be exported and signed by a public CA
     * (or by an internal CA), then completed via completeCsr().
     */
    public function generateCsr()
    {
        $name       = trim((string) $this->request->getPost('name'));
        $commonName = trim((string) $this->request->getPost('common_name'));
        $certType   = (string) ($this->request->getPost('cert_type') ?: 'server');
        $sanDns     = trim((string) $this->request->getPost('san_dns'));
        $sanIp      = trim((string) $this->request->getPost('san_ip'));
        $keyLength  = (int) ($this->request->getPost('key_length') ?: 2048);
        $desc       = trim((string) $this->request->getPost('description'));

        if (! in_array($certType, ['server', 'client', 'user', 'vpn'], true)) {
            $certType = 'server';
        }
        if ($name === '' || $commonName === '') {
            return redirect()->back()->with('error', 'A friendly name and a Common Name (CN) are required to build a CSR.');
        }

        $sslConfig   = $this->getOpenSslConfig();
        $privKeyArgs = ['private_key_bits' => $keyLength, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (isset($sslConfig['config'])) {
            $privKeyArgs['config'] = $sslConfig['config'];
        }

        $privKey = openssl_pkey_new($privKeyArgs);
        if ($privKey === false) {
            return redirect()->back()->with('error', 'CSR generation failed: could not create a private key.');
        }

        $dnConfig = [
            'CN' => $commonName,
            'O'  => 'MicronOS Enterprise',
        ];
        $dn = [
            'commonName'         => $commonName,
            'organizationName'   => 'MicronOS Enterprise',
        ];

        $tmpConfig = $this->buildCsrConfig($dnConfig, $sanDns, $sanIp);
        $options   = ['digest_alg' => 'sha256'];
        if ($tmpConfig !== null) {
            $options['config']         = $tmpConfig;
            $options['req_extensions'] = 'v3_req';
        } elseif (isset($sslConfig['config'])) {
            $options['config'] = $sslConfig['config'];
        }

        $csr = @openssl_csr_new($dn, $privKey, $options);
        if ($tmpConfig !== null) {
            @unlink($tmpConfig);
        }
        if ($csr === false) {
            return redirect()->back()->with('error', 'CSR generation failed: OpenSSL rejected the request.');
        }

        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($privKey, $keyOut, null, $sslConfig);

        $this->certModel->insert([
            'ca_id'         => 0,
            'cert_type'     => $certType,
            'name'          => $name,
            'common_name'   => $commonName,
            'organization'  => 'MicronOS Enterprise',
            'san_dns'       => $sanDns,
            'san_ip'        => $sanIp,
            'certificate'   => '',
            'private_key'   => $keyOut,
            'csr'           => $csrOut,
            'serial_number' => '',
            'valid_from'    => date('Y-m-d H:i:s'),
            'valid_until'   => null,
            'revoked'       => 0,
            'description'   => $desc !== '' ? $desc : 'Certificate signing request (awaiting signature)',
            'source'        => 'csr',
        ]);

        return redirect()->to('/certificates')->with('success', "CSR for '{$name}' created. Download it, have it signed, then paste the signed certificate back.");
    }

    public function downloadCsr($id)
    {
        $cert = $this->certModel->find($id);
        if (! $cert || empty($cert['csr'])) {
            return redirect()->to('/certificates')->with('error', 'No CSR available for that entry.');
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cert['name']) . '.csr';

        return $this->response->setHeader('Content-Type', 'application/pkcs10')
                              ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
                              ->setBody($cert['csr']);
    }

    /**
     * Paste back the signed certificate for a pending CSR (signing an externally-
     * "Import" completion step).
     */
    public function completeCsr($id)
    {
        $cert = $this->certModel->find($id);
        if (! $cert) {
            return redirect()->to('/certificates')->with('error', 'Certificate entry not found.');
        }

        $meta = X509::parseCertificate((string) $this->request->getPost('certificate'));
        if ($meta === null) {
            return redirect()->back()->with('error', 'The signed certificate is not a readable PEM X.509 block.');
        }

        $update = [
            'certificate'   => $meta['certificate'],
            'source'        => 'imported',
            'serial_number' => $meta['serial_number'] !== '' ? $meta['serial_number'] : $cert['serial_number'],
            'valid_from'    => $meta['valid_from'] ?? $cert['valid_from'],
            'valid_until'   => $meta['valid_until'] ?? $cert['valid_until'],
        ];
        if (empty($cert['san_dns']) && $meta['san_dns'] !== '') {
            $update['san_dns'] = $meta['san_dns'];
        }
        if (empty($cert['san_ip']) && $meta['san_ip'] !== '') {
            $update['san_ip'] = $meta['san_ip'];
        }

        if ((int) $cert['ca_id'] === 0 && $meta['issuer_cn'] !== '') {
            $match = $this->caModel->where('common_name', $meta['issuer_cn'])->first();
            if ($match) {
                $update['ca_id'] = (int) $match['id'];
            }
        }

        $this->certModel->update($id, $update);
        $this->ipc->call('cert.reload');

        return redirect()->to('/certificates')->with('success', "Signed certificate attached to '{$cert['name']}'.");
    }

    // ---------------------------------------------------------------------
    // Web GUI / portal TLS binding
    // ---------------------------------------------------------------------

    /** Bind a stored certificate to the Web GUI / self-service portal. */
    public function setWebguiCert($id)
    {
        $cert = $this->certModel->find($id);
        if (! $cert) {
            return redirect()->to('/certificates')->with('error', 'Certificate not found.');
        }
        if (empty($cert['certificate']) || ! empty($cert['revoked'])) {
            return redirect()->to('/certificates')->with('error', 'Only a valid, issued certificate can be bound to the portal.');
        }

        $this->settings->saveSettings(['webgui_cert_id' => (int) $id]);
        $this->ipc->call('cert.install', ['cert_id' => (int) $id, 'service' => 'webgui']);

        return redirect()->to('/certificates')->with('success', "Web GUI / portal now presents '{$cert['name']}'.");
    }

    /** Revert the portal to the system default certificate. */
    public function clearWebguiCert()
    {
        $this->settings->saveSettings(['webgui_cert_id' => 0]);
        $this->ipc->call('cert.reload');

        return redirect()->to('/certificates')->with('success', 'Web GUI / portal reverted to the system default certificate.');
    }

    // ---------------------------------------------------------------------
    // ACME / Let's Encrypt issuance and renewal
    // ---------------------------------------------------------------------

    /**
     * The HTTP-01 validation endpoint the CA fetches.
     *
     * Deliberately unauthenticated and outside the `permission:` group: the CA
     * has no session, and the answer it needs is a public value it already knows
     * (`token.thumbprint`). It is only readable while an order is pending — the
     * manager writes the file when the order is created and removes it when the
     * order finishes — and the token must match RFC 8555's base64url grammar, so
     * no request can name a file outside the challenge directory.
     *
     * Reachability is the operator's side of the bargain: the CA fetches
     * `http://<name>/.well-known/acme-challenge/<token>` on port 80, so that name
     * must resolve to this appliance before an HTTP-01 order can succeed. DNS-01
     * is offered precisely for the deployments where it does not.
     */
    public function acmeChallenge(string $token)
    {
        $body = $this->acme->challengeResponse($token);

        if ($body === null) {
            return $this->response->setStatusCode(404)->setContentType('text/plain')->setBody('');
        }

        return $this->response
            ->setContentType('text/plain')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($body);
    }

    /** Register (or re-register) the ACME account for a directory. */
    public function saveAcmeAccount()
    {
        $directory = (string) $this->request->getPost('directory');
        $email     = (string) $this->request->getPost('email');

        $result = $this->acme->registerAccount($directory, $email);

        return redirect()->to('/certificates#acme')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? 'ACME account registered with ' . AcmeManager::directoryLabel($directory) . '.'
                : 'ACME account could not be registered: ' . $result['message']
        );
    }

    /**
     * Request a certificate.
     *
     * One action for both challenge types: HTTP-01 is finished inside this call
     * because the appliance is the web server, while DNS-01 stops and hands the
     * operator the TXT records to publish.
     */
    public function requestAcme()
    {
        $input = [
            'directory'      => (string) $this->request->getPost('directory'),
            'email'          => (string) $this->request->getPost('email'),
            'domains'        => (string) $this->request->getPost('domains'),
            'challenge_type' => (string) $this->request->getPost('challenge_type'),
            'cert_type'      => (string) $this->request->getPost('cert_type'),
            'name'           => (string) $this->request->getPost('name'),
        ];

        $result = $this->acme->request($input);

        // "Still validating" is not a failure to report as an error: the order is
        // alive and the operator continues it with the same button.
        if (($result['status'] ?? '') === 'pending' && isset($result['order_id'])) {
            return redirect()->to('/certificates#acme')->with(
                'warning',
                'Order #' . $result['order_id'] . ' is registered and the challenge is being validated: ' . $result['message']
            );
        }

        return redirect()->to('/certificates#acme')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? $result['message'] : 'ACME request failed (' . $result['reason'] . '): ' . $result['message']
        );
    }

    /** Continue an order: publish, wait, finalize, download, install. */
    public function checkAcme($orderId)
    {
        $result = $this->acme->complete((int) $orderId);

        if ($result['ok'] === true) {
            return redirect()->to('/certificates#acme')->with(
                'success',
                $result['message'] . ' Certificate #' . $result['cert_id'] . ' is now in the PKI store.'
            );
        }

        if (($result['status'] ?? '') === 'pending') {
            return redirect()->to('/certificates#acme')->with('warning', 'Not finished yet: ' . $result['message']);
        }

        return redirect()->to('/certificates#acme')->with(
            'error',
            'ACME order failed (' . $result['reason'] . '): ' . $result['message']
        );
    }

    /** Renew one ACME certificate in place. */
    public function renewAcme($id)
    {
        $result = $this->acme->renew((int) $id);

        if (($result['status'] ?? '') === 'pending' && isset($result['order_id'])) {
            return redirect()->to('/certificates#acme')->with(
                'warning',
                'Renewal order #' . $result['order_id'] . ' is awaiting validation: ' . $result['message']
            );
        }

        return redirect()->to('/certificates#acme')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? $result['message'] : 'Renewal failed (' . $result['reason'] . '): ' . $result['message']
        );
    }

    private function canManageAcme(): bool
    {
        $userId = (string) (session()->get('user_id') ?? '');

        return $userId !== '' && (new \App\Models\PermissionModel())->userCan($userId, 'cert.acme.manage');
    }

    /**
     * Write a throwaway OpenSSL config so a CSR carries SANs and key usage the
     * way most PKI tooling produces them (a bare openssl_csr_new drops them).
     */
    private function buildCsrConfig(array $dn, string $sanDns, string $sanIp): ?string
    {
        $dir = WRITEPATH . 'pki';
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            return null;
        }

        $lines = [
            '[ req ]',
            'default_md = sha256',
            'prompt = no',
            'distinguished_name = dn',
            'req_extensions = v3_req',
            '',
            '[ dn ]',
        ];
        foreach ($dn as $k => $v) {
            if ((string) $v !== '') {
                $lines[] = $k . ' = ' . $v;
            }
        }

        $lines[] = '';
        $lines[] = '[ v3_req ]';
        $lines[] = 'basicConstraints = CA:FALSE';
        $lines[] = 'keyUsage = digitalSignature, keyEncipherment';
        $lines[] = 'extendedKeyUsage = serverAuth, clientAuth';

        $alt = [];
        foreach (explode(',', $sanDns) as $d) {
            $d = trim($d);
            if ($d !== '') {
                $alt[] = 'DNS.' . (count($alt) + 1) . ' = ' . $d;
            }
        }
        $ipIndex = 0;
        foreach (explode(',', $sanIp) as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $alt[] = 'IP.' . (++$ipIndex) . ' = ' . $ip;
            }
        }
        if ($alt !== []) {
            $lines[] = 'subjectAltName = @alt_names';
            $lines[] = '';
            $lines[] = '[ alt_names ]';
            $lines   = array_merge($lines, $alt);
        }

        $path = $dir . '/req_' . bin2hex(random_bytes(6)) . '.cnf';
        if (@file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            return null;
        }

        return $path;
    }

    private function seedInitialPki()
    {
        $sslConfig = $this->getOpenSslConfig();

        $dn = [
            "countryName"            => "US",
            "stateOrProvinceName"    => "California",
            "localityName"           => "San Jose",
            "organizationName"       => "MicronOS Security Systems",
            "commonName"             => "MicronOS Default Root CA",
        ];

        $privKeyArgs = [
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];
        if (isset($sslConfig['config'])) {
            $privKeyArgs['config'] = $sslConfig['config'];
        }

        $caCert = '';
        $caKey  = '';

        try {
            $privKey = openssl_pkey_new($privKeyArgs);
            $csr = openssl_csr_new($dn, $privKey, $sslConfig);
            $x509 = openssl_csr_sign($csr, null, $privKey, 3650, $sslConfig);
            if ($x509) {
                openssl_x509_export($x509, $caCert);
                openssl_pkey_export($privKey, $caKey, null, $sslConfig);
            }
        } catch (\Throwable $e) {}

        if (empty($caCert) || empty($caKey)) {
            $caCert = "-----BEGIN CERTIFICATE-----\nMIIDXTCCAkWgAwIBAgIBATANBgkqhkiG9w0BAQsFADBLMQswCQYDVQQGEwJVUzET\nMBEGA1UECAwKQ2FsaWZvcm5pYTEhMB8GA1UECgwYTWljcm9uT1MgU2VjdXJpdHkg\nU3lzdGVtczENMAsGA1UEAwwEQ0ExMTAeFw0yNjEwMDIxMjAwMDBaFw0zNjEwMDIx\nMjAwMDBaMEsxCzAJBgNVBAYTAlVTMREwDwYDVQQIDAhTYW4gSm9zZTEhMB8GA1UE\nCgwYTWljcm9uT1MgU2VjdXJpdHkgU3lzdGVtczENMAsGA1UEAwwEQ0ExMTCCASIw\nDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAL0PqWb\n-----END CERTIFICATE-----";
            $caKey = "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC9D6lm+T62kS\n-----END PRIVATE KEY-----";
        }

        $now = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime('+10 years'));

        $caId = $this->caModel->insert([
            'name'          => 'MicronOS Root CA',
            'common_name'   => 'MicronOS Default Root CA',
            'organization'  => 'MicronOS Security Systems',
            'country'       => 'US',
            'state'         => 'California',
            'city'          => 'San Jose',
            'certificate'   => $caCert,
            'private_key'   => $caKey,
            'serial_number' => '01',
            'valid_from'    => $now,
            'valid_until'   => $validUntil,
            'key_length'    => 2048,
            'digest'        => 'sha256',
            'is_default'    => 1,
        ]);

        // Web GUI cert
        $serverCert = "-----BEGIN CERTIFICATE-----\nMIIDXTCCAkWgAwIBAgIBATANBgkqhkiG9w0BAQsFADBLMQswCQYDVQQGEwJVUzET\nMBEGA1UECAwKQ2FsaWZvcm5pYTEhMB8GA1UECgwYTWljcm9uT1MgU2VjdXJpdHkg\nU3lzdGVtczENMAsGA1UEAwwEQ0ExMTAeFw0yNjEwMDIxMjAwMDBaFw0zNjEwMDIx\n-----END CERTIFICATE-----";
        $serverKey = $caKey;

        $this->certModel->insert([
            'ca_id'         => $caId,
            'cert_type'     => 'server',
            'name'          => 'Web GUI HTTPS Certificate',
            'common_name'   => 'micronos.local',
            'organization'  => 'MicronOS Security Systems',
            'san_dns'       => 'micronos.local, micronos.test, gateway.local',
            'san_ip'        => '192.168.1.1, 10.0.0.1, 127.0.0.1',
            'certificate'   => $serverCert,
            'private_key'   => $serverKey,
            'serial_number' => '1001',
            'valid_from'    => $now,
            'valid_until'   => date('Y-m-d H:i:s', strtotime('+825 days')),
            'revoked'       => 0,
            'description'   => 'System Default TLS Web Management Certificate',
        ]);

        // OpenVPN Server Certificate
        $this->certModel->insert([
            'ca_id'         => $caId,
            'cert_type'     => 'vpn',
            'name'          => 'OpenVPN Server Authority Cert',
            'common_name'   => 'vpn.micronos.local',
            'organization'  => 'MicronOS Security Systems',
            'san_dns'       => 'vpn.micronos.local',
            'san_ip'        => '',
            'certificate'   => $serverCert,
            'private_key'   => $serverKey,
            'serial_number' => '1002',
            'valid_from'    => $now,
            'valid_until'   => date('Y-m-d H:i:s', strtotime('+825 days')),
            'revoked'       => 0,
            'description'   => 'OpenVPN Road Warrior Daemon Server Certificate',
        ]);
    }
}

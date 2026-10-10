<?php

namespace App\Libraries;

use App\Models\PkiAuthorityModel;

/**
 * InterceptionCa — the certificate authority Squid SSL-Bump uses to mint
 * per-host certificates for decrypted HTTPS sessions.
 *
 * Before this existed the Web Filter served a hardcoded placeholder PEM, so any
 * client that installed the advertised "Root CA" could never validate an
 * intercepted certificate and HTTPS deep inspection silently failed.
 *
 * The interception CA is stored in the PKI subsystem (`pki_authorities`, the
 * same table managed on /certificates) rather than a private side-channel, so
 * it can be inspected, rotated and exported like any other CA. It is looked up
 * by common name and generated with OpenSSL on first use.
 */
class InterceptionCa
{
    /** Common name identifying the Squid SSL-Bump interception CA. */
    public const COMMON_NAME = 'Micron OS Proxy Inspection CA';
    public const LEGACY_COMMON_NAME = 'MicronOS Proxy Inspection CA';

    public const DISPLAY_NAME = 'Micron OS SSL-Bump Interception CA';
    public const DEFAULT_KEY_LENGTH = 4096;
    public const DEFAULT_VALID_DAYS = 3650;

    /**
     * Return the interception CA row, generating it on first use.
     *
     * @return array<string, mixed>|null  null when OpenSSL cannot be used
     */
    public static function ensure(): ?array
    {
        $model = new PkiAuthorityModel();

        try {
            $existing = $model->where('common_name', self::COMMON_NAME)
                              ->orWhere('common_name', self::LEGACY_COMMON_NAME)
                              ->orderBy('id', 'DESC')
                              ->first();
        } catch (\Throwable $e) {
            return null;
        }

        if ($existing && ! empty($existing['certificate'])) {
            return $existing;
        }

        return self::generate($model);
    }

    /**
     * The interception CA certificate in PEM form, or null when unavailable.
     */
    public static function certificate(): ?string
    {
        $ca = self::ensure();

        return $ca['certificate'] ?? null;
    }

    /**
     * Converts PEM certificate to DER binary format.
     */
    public static function derCertificate(): ?string
    {
        $pem = self::certificate();
        if (!$pem) return null;

        $lines = explode("\n", trim($pem));
        $data = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '-----BEGIN') || str_starts_with($trimmed, '-----END')) {
                continue;
            }
            $data .= $trimmed;
        }

        return base64_decode($data) ?: null;
    }

    /**
     * Generates an Apple iOS / macOS .mobileconfig profile payload for one-click Root CA trust.
     */
    public static function mobileConfig(): ?string
    {
        $der = self::derCertificate();
        if (!$der) return null;

        $b64Cert = base64_encode($der);
        $uuidPayload = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $uuidTop = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>PayloadCertificateFileName</key>
            <string>micron-os-inspection-ca.cer</string>
            <key>PayloadContent</key>
            <data>{$b64Cert}</data>
            <key>PayloadDescription</key>
            <string>Installs the Micron OS Root Certificate Authority for SSL/TLS Deep Packet Inspection.</string>
            <key>PayloadDisplayName</key>
            <string>Micron OS Proxy Inspection CA</string>
            <key>PayloadIdentifier</key>
            <string>org.micronos.ca.{$uuidPayload}</string>
            <key>PayloadType</key>
            <string>c fatal.root</string>
            <key>PayloadUUID</key>
            <string>{$uuidPayload}</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
        </dict>
    </array>
    <key>PayloadDisplayName</key>
    <string>Micron OS SSL Inspection CA</string>
    <key>PayloadIdentifier</key>
    <string>org.micronos.profile.{$uuidTop}</string>
    <key>PayloadRemovalDisallowed</key>
    <false/>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>{$uuidTop}</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
</dict>
</plist>
XML;
    }

    /**
     * Forges a dynamic per-host synthetic certificate on the fly, simulating
     * the exact operation performed by Squid / micrond during an active HTTPS MITM session.
     */
    public static function forgeCertificate(string $domain, int $validDays = 90): ?array
    {
        $ca = self::ensure();
        if (!$ca || empty($ca['certificate']) || empty($ca['private_key'])) {
            return null;
        }

        $config = self::opensslConfig();
        $domain = strtolower(trim($domain));

        $dn = [
            'countryName'         => 'US',
            'stateOrProvinceName' => 'California',
            'localityName'        => 'San Jose',
            'organizationName'    => 'Micron OS Intercepted Host',
            'commonName'          => $domain,
        ];

        $keyArgs = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        if (isset($config['config'])) {
            $keyArgs['config'] = $config['config'];
        }

        $leafKey = openssl_pkey_new($keyArgs);
        if (!$leafKey) return null;

        $csr = openssl_csr_new($dn, $leafKey, $config);
        if (!$csr) return null;

        $caCertRes = openssl_x509_read($ca['certificate']);
        $caKeyRes  = openssl_pkey_get_private($ca['private_key']);
        if (!$caCertRes || !$caKeyRes) return null;

        $serial = random_int(10000000, 99999999);
        $signConfig = $config;

        $forgedX509 = openssl_csr_sign($csr, $caCertRes, $caKeyRes, $validDays, $signConfig, $serial);
        if (!$forgedX509) return null;

        openssl_x509_export($forgedX509, $certOut);
        openssl_pkey_export($leafKey, $keyOut, null, $config);

        $parsed = openssl_x509_parse($forgedX509);
        $fingerprint = openssl_x509_fingerprint($forgedX509, 'sha256');

        return [
            'domain'             => $domain,
            'serial'             => dechex($serial),
            'issuer'             => self::COMMON_NAME,
            'subject'            => "CN={$domain}",
            'valid_from'         => date('Y-m-d H:i:s', $parsed['validFrom_time_t'] ?? time()),
            'valid_until'        => date('Y-m-d H:i:s', $parsed['validTo_time_t'] ?? (time() + ($validDays * 86400))),
            'fingerprint_sha256' => strtoupper(implode(':', str_split($fingerprint, 2))),
            'certificate_pem'    => $certOut,
            'private_key_pem'    => $keyOut,
            'sans'               => [$domain, "*." . $domain],
        ];
    }

    /**
     * Writes the CA certificate and key to the configured Squid paths.
     *
     * On the appliance this is normally micrond's job (the web tier is
     * unprivileged); when the target directory is not writable this reports the
     * failure instead of pretending the files were installed.
     *
     * @return array{cert_written:bool, key_written:bool, error:?string}
     */
    public static function provision(string $certPath, string $keyPath): array
    {
        $ca = self::ensure();
        if (! $ca || empty($ca['certificate'])) {
            return ['cert_written' => false, 'key_written' => false, 'error' => 'The interception CA could not be generated.'];
        }

        $failure = null;

        $certWritten = self::writeFile($certPath, (string) $ca['certificate'], $failure);
        $keyWritten  = false;
        if (! empty($ca['private_key'])) {
            $keyWritten = self::writeFile($keyPath, (string) $ca['private_key'], $failure);
            if ($keyWritten) {
                @chmod($keyPath, 0600);
            }
        }

        return ['cert_written' => $certWritten, 'key_written' => $keyWritten, 'error' => $failure];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param PkiAuthorityModel $model
     * @return array<string, mixed>|null
     */
    private static function generate(PkiAuthorityModel $model): ?array
    {
        if (! function_exists('openssl_pkey_new')) {
            return null;
        }

        $config    = self::opensslConfig();
        $keyLength = self::DEFAULT_KEY_LENGTH;
        $validDays = self::DEFAULT_VALID_DAYS;
        $org       = 'MicronOS Enterprise';

        $dn = [
            'countryName'         => 'US',
            'stateOrProvinceName' => 'California',
            'localityName'        => 'San Jose',
            'organizationName'    => $org,
            'commonName'          => self::COMMON_NAME,
        ];

        $keyArgs = [
            'private_key_bits' => $keyLength,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        if (isset($config['config'])) {
            $keyArgs['config'] = $config['config'];
        }

        try {
            $privKey = openssl_pkey_new($keyArgs);
            if ($privKey === false) {
                return null;
            }

            $csr = openssl_csr_new($dn, $privKey, $config);
            if ($csr === false) {
                return null;
            }

            // Self-signed true root CA. The `v3_ca` extensions (basicConstraints
            // CA:TRUE) matter: without them the certificate cannot be used to
            // sign the per-host certificates Squid generates for each site.
            $signConfig = $config;
            if (isset($config['config'])) {
                $signConfig['x509_extensions'] = 'v3_ca';
            }

            $x509 = openssl_csr_sign($csr, null, $privKey, $validDays, $signConfig, random_int(1, 2147483647));
            if ($x509 === false) {
                return null;
            }

            openssl_x509_export($x509, $certOut);
            openssl_pkey_export($privKey, $keyOut, null, $config);

            $now        = date('Y-m-d H:i:s');
            $validUntil = date('Y-m-d H:i:s', strtotime("+{$validDays} days"));

            $id = $model->insert([
                'name'          => self::DISPLAY_NAME,
                'common_name'   => self::COMMON_NAME,
                'organization'  => $org,
                'country'       => 'US',
                'state'         => 'California',
                'city'          => 'San Jose',
                'certificate'   => $certOut,
                'private_key'   => $keyOut,
                'serial_number' => '01',
                'valid_from'    => $now,
                'valid_until'   => $validUntil,
                'key_length'    => $keyLength,
                'digest'        => 'sha256',
                'is_default'    => 0,
                'source'        => 'webfilter',
            ], true);

            return $model->find($id) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function writeFile(string $path, string $contents, ?string &$failure): bool
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            $failure ??= "Directory {$dir} does not exist on this host.";

            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            $failure ??= "Cannot write {$path} (the web tier is unprivileged; micrond must install it).";

            return false;
        }

        return true;
    }

    /**
     * OpenSSL configuration, needed because a bare `openssl_csr_new()` has no
     * default `openssl.cnf` on some builds (notably Windows development hosts).
     *
     * @return array<string, mixed>
     */
    private static function opensslConfig(): array
    {
        $args = ['digest_alg' => 'sha256'];

        $candidates = [
            getenv('OPENSSL_CONF') ?: '',
            '/etc/ssl/openssl.cnf',
            'D:/laragon/bin/apache/httpd-2.4.68-260617-Win64-VS18/conf/openssl.cnf',
            'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/extras/ssl/openssl.cnf',
            'D:/laragon/bin/git/mingw64/etc/ssl/openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                $args['config'] = $candidate;
                putenv("OPENSSL_CONF={$candidate}");
                break;
            }
        }

        return $args;
    }
}

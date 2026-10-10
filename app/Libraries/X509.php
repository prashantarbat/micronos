<?php

namespace App\Libraries;

/**
 * X509 — small PEM toolkit for importing and inspecting certificate material.
 *
 * The Certificate Manager supports pasting or uploading material that came from
 * somewhere else (a public CA, another firewall, a corporate PKI). That text is
 * often wrapped in "Bag Attributes" / "subject=" / "issuer=" chatter, may be a
 * full chain, and may use PKCS#8 ("PRIVATE KEY") or PKCS#1 ("RSA PRIVATE KEY").
 *
 * These helpers pull out the first block of the requested type and expose the
 * fields we persist (CN, O/OU, validity, serial, SANs, key size, CA flag) so an
 * imported certificate lands in the store with the same metadata a generated one
 * would have.
 */
class X509
{
    /** Key labels we will accept for a private key. */
    private const KEY_LABELS = ['PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY', 'ENCRYPTED PRIVATE KEY'];

    /**
     * Extract the first PEM block of $label from arbitrary pasted text.
     * Returns a normalised block terminated by a trailing newline, or null.
     */
    public static function normalizePem(string $text, string $label): ?string
    {
        $text = str_replace("\r\n", "\n", trim($text));
        if ($text === '') {
            return null;
        }

        $pattern = '/-----BEGIN ' . preg_quote($label, '/') . '-----(.*?)-----END ' . preg_quote($label, '/') . '-----/s';
        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $body = preg_replace('/\s+/', '', $m[1]);

        return "-----BEGIN {$label}-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END {$label}-----\n";
    }

    /**
     * Extract the first private key found, whatever its encoding label.
     */
    public static function normalizeKey(string $text): ?string
    {
        foreach (self::KEY_LABELS as $label) {
            $pem = self::normalizePem($text, $label);
            if ($pem !== null) {
                return $pem;
            }
        }

        return null;
    }

    /** True if the text contains at least one PEM certificate block. */
    public static function looksLikeCertificate(string $text): bool
    {
        return self::normalizePem($text, 'CERTIFICATE') !== null;
    }

    /**
     * Parse an X.509 certificate into the flat shape the PKI store uses.
     *
     * @return array|null null when the input is not a readable certificate.
     */
    public static function parseCertificate(string $text): ?array
    {
        $pem = self::normalizePem($text, 'CERTIFICATE');
        if ($pem === null) {
            return null;
        }

        // Parse with short DNs (`CN`, `O`, `C`, …). With long names this array
        // is keyed `commonName`/`organizationName`, so every `$subject['CN']`
        // lookup below silently returned '' and certificates reported a blank
        // common name and organization. Extension keys are the same either way.
        $data = @openssl_x509_parse($pem, true);
        if (! is_array($data)) {
            return null;
        }

        $subject = $data['subject'] ?? [];
        $issuer  = $data['issuer'] ?? [];
        $ext     = $data['extensions'] ?? [];

        [$sanDns, $sanIp] = self::splitSubjectAltName($ext['subjectAltName'] ?? '');

        $isCa = false;
        if (isset($ext['basicConstraints']) && stripos($ext['basicConstraints'], 'CA:TRUE') !== false) {
            $isCa = true;
        }

        // A self-signed cert has identical subject and issuer DNs.
        $selfSigned = (($subject['CN'] ?? null) !== null && ($issuer['CN'] ?? null) !== null)
            && (($data['subject'] ?? '') === ($data['issuer'] ?? ''));

        return [
            'certificate'    => $pem,
            'common_name'    => (string) ($subject['CN'] ?? ''),
            'organization'   => (string) ($subject['O'] ?? ''),
            'organizational_unit' => (string) ($subject['OU'] ?? ''),
            'country'        => (string) ($subject['C'] ?? ''),
            'state'          => (string) ($subject['ST'] ?? ''),
            'city'           => (string) ($subject['L'] ?? ''),
            'issuer_cn'      => (string) ($issuer['CN'] ?? ''),
            'issuer'         => is_array($data['issuer'] ?? null) ? $data['issuer'] : [],
            'serial_number'  => strtoupper((string) ($data['serialNumberHex'] ?? '')),
            'valid_from'     => isset($data['validFrom_time_t']) ? date('Y-m-d H:i:s', $data['validFrom_time_t']) : null,
            'valid_until'    => isset($data['validTo_time_t']) ? date('Y-m-d H:i:s', $data['validTo_time_t']) : null,
            'san_dns'        => $sanDns,
            'san_ip'         => $sanIp,
            'is_ca'          => $isCa,
            'self_signed'    => $selfSigned,
            'key_length'     => self::keyBits($pem),
            'digest'         => self::digest($data['signatureTypeSN'] ?? ''),
        ];
    }

    /** Key size in bits for a certificate, or 0 when it can't be determined. */
    public static function keyBits(string $pem): int
    {
        $pub = @openssl_pkey_get_public($pem);

        return $pub === false ? 0 : (int) (@openssl_pkey_get_details($pub)['bits'] ?? 0);
    }

    /**
     * Read the certificate a private key belongs to — used only to sanity check
     * that a pasted key actually matches the pasted certificate.
     */
    public static function keyMatchesCertificate(string $keyPem, string $certPem): bool
    {
        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            return false;
        }

        $keyDetails  = @openssl_pkey_get_details($key);
        $certDetails = @openssl_pkey_get_details(@openssl_pkey_get_public($certPem));

        if (! $keyDetails || ! $certDetails) {
            return false;
        }

        return ($keyDetails['key'] ?? '') === ($certDetails['key'] ?? '');
    }

    /** Common Name from a PEM CSR, or null. */
    public static function csrCommonName(string $text): ?string
    {
        $pem = self::normalizePem($text, 'CERTIFICATE REQUEST');
        if ($pem === null) {
            return null;
        }

        $data = @openssl_csr_get_subject($pem, false);

        return is_array($data) ? (string) ($data['CN'] ?? '') : null;
    }

    /** True when the text parses as a CSR. */
    public static function isValidCsr(string $text): bool
    {
        return self::normalizePem($text, 'CERTIFICATE REQUEST') !== null;
    }

    /**
     * Split an OpenSSL subjectAltName extension string into DNS and IP lists.
     *
     * @return array{0: string, 1: string} comma-separated DNS names, comma-separated IPs
     */
    private static function splitSubjectAltName(string $san): array
    {
        if ($san === '') {
            return ['', ''];
        }

        $dns = [];
        $ips = [];

        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (stripos($entry, 'DNS:') === 0) {
                $dns[] = trim(substr($entry, 4));
            } elseif (stripos($entry, 'IP Address:') === 0) {
                $ips[] = trim(substr($entry, 11));
            } elseif (stripos($entry, 'IP:') === 0) {
                $ips[] = trim(substr($entry, 3));
            }
        }

        return [implode(', ', $dns), implode(', ', $ips)];
    }

    /** Normalise the OpenSSL signature-type label to a short digest name. */
    private static function digest(string $signatureType): string
    {
        $type = strtolower($signatureType);
        foreach (['sha512', 'sha384', 'sha256', 'sha1', 'md5'] as $known) {
            if (str_contains($type, $known)) {
                return $known;
            }
        }

        return 'sha256';
    }
}

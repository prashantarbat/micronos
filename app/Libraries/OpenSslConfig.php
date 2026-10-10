<?php

namespace App\Libraries;

/**
 * The OpenSSL configuration file this appliance's PHP should use.
 *
 * PHP's OpenSSL calls (`openssl_pkey_new`, `openssl_csr_new`, `openssl_sign` …)
 * are compiled against a config file that is not always discoverable: on a
 * minimal Linux PHP it is found, but on a Windows PHP build with no
 * `openssl.cnf` next to it every call fails with
 * `error:80000002:system library::No such file or directory`, which reads like a
 * missing *file* rather than a missing OpenSSL config.
 *
 * The PKI module has resolved this since it was written; ACME needs it for the
 * same reason (a P-256 account key and a CSR), so it lives here rather than being
 * written out twice and drifting.
 */
final class OpenSslConfig
{
    /**
     * Config files to fall back to, in order. These are the paths the PKI module
     * already used — kept verbatim so extracting this class changes nothing.
     *
     * @var list<string>
     */
    private const CANDIDATES = [
        'D:/laragon/bin/apache/httpd-2.4.68-260617-Win64-VS18/conf/openssl.cnf',
        'D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/extras/ssl/openssl.cnf',
        'D:/laragon/bin/git/mingw64/etc/ssl/openssl.cnf',
    ];

    private static ?string $resolved = null;

    private static bool $looked = false;

    /**
     * The config file to use, or null when the environment already provides one.
     *
     * `OPENSSL_CONF` wins: on the appliance the distribution's PHP has its own
     * config, and overriding it with a Windows path that happens to exist would
     * be worse than using nothing at all.
     */
    public static function path(): ?string
    {
        if (self::$looked) {
            return self::$resolved;
        }

        self::$looked = true;

        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '' && is_file($env)) {
            self::$resolved = $env;

            return self::$resolved;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                self::$resolved = $candidate;

                return self::$resolved;
            }
        }

        return self::$resolved = null;
    }

    /**
     * Point the process at the resolved config and return it.
     *
     * `putenv` matters as much as the returned path: some of these calls read the
     * environment rather than the `config` argument, so setting only one of the
     * two leaves half the PKI code working.
     */
    public static function apply(): ?string
    {
        $path = self::path();

        if ($path !== null) {
            putenv('OPENSSL_CONF=' . $path);
        }

        return $path;
    }

    /**
     * The option array the PKI module passes to `openssl_csr_new` /
     * `openssl_csr_sign`.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $path = self::apply();

        return $path === null ? ['digest_alg' => 'sha256'] : ['digest_alg' => 'sha256', 'config' => $path];
    }
}

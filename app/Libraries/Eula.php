<?php

namespace App\Libraries;

/**
 * The Micron OS licence agreement (EULA.txt at the project root).
 *
 * The installer shows it before anything else and stops on Decline. The web
 * interface shows it after sign-in, before any other page, until an
 * administrator accepts the current VERSION; the acceptance is stored in
 * system_settings, so it is asked once per appliance and again only when a
 * release ships a new version of the text.
 */
final class Eula
{
    /** Bump together with the "Version" line of EULA.txt. */
    public const VERSION = '1.0';

    /** Session marker set once this sign-in has seen an accepted agreement. */
    public const SESSION_FLAG = 'eula_ok';

    /** Pages that stay open while the agreement is not yet accepted. */
    private const OPEN_PATHS = ['eula', 'eula/accept', 'eula/decline', 'auth/logout', 'logout'];

    public static function path(): string
    {
        return ROOTPATH . 'EULA.txt';
    }

    public static function text(): string
    {
        $text = is_file(self::path()) ? (string) file_get_contents(self::path()) : '';

        return $text !== '' ? $text : 'The licence agreement file (EULA.txt) is missing from this installation.';
    }

    /**
     * Whether sign-ins must pass the agreement page. The test suite sets
     * MICRON_EULA_ASSUME_ACCEPTED=1 (phpunit.dist.xml) because its feature
     * tests open pages directly; EulaTest turns it back on.
     */
    public static function enforced(): bool
    {
        return getenv('MICRON_EULA_ASSUME_ACCEPTED') !== '1';
    }

    /** True when the stored acceptance covers the current version. */
    public static function isAccepted(array $settings): bool
    {
        return (string) ($settings['eula_version'] ?? '') === self::VERSION;
    }

    public static function isOpenWhilePending(string $path): bool
    {
        return in_array(trim($path, '/'), self::OPEN_PATHS, true);
    }
}

<?php

namespace App\Libraries;

/**
 * DefaultCredentials — the factory password and the rule that it must go.
 *
 * Only the live image's seeded database still opens with the factory password:
 * the installer, kickstart, the console menu and `user:reset-admin` all refuse
 * it. As a second line of defence, sign-in compares the password the user just
 * typed: an account that still opens with it is sent to change it before
 * anything else, and it can never be chosen again.
 */
final class DefaultCredentials
{
    /** Session key set while the signed-in user still has the factory password. */
    public const SESSION_FLAG = 'must_change_password';

    /** The factory password the installer, seeder and console reset fall back to. */
    private const FACTORY_PASSWORD = 'M1Cr0N';

    /** Minimum length for a new administrator password. */
    public const MIN_LENGTH = 8;

    public static function isFactoryPassword(string $password): bool
    {
        return hash_equals(self::FACTORY_PASSWORD, $password);
    }

    /**
     * Pages a user with the factory password may still reach: the profile page
     * that changes it, the change itself, and signing out.
     */
    /**
     * Why a new administrator password is not acceptable, or null when it is.
     */
    public static function problemWith(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'must be at least ' . self::MIN_LENGTH . ' characters';
        }
        if (self::isFactoryPassword($password)) {
            return 'must not be the published factory password';
        }

        return null;
    }

    /**
     * A random password for unattended resets (16 characters, letters and digits).
     */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public static function isAllowedWhileForced(string $path): bool
    {
        $path = trim($path, '/');

        return in_array($path, ['profile', 'profile/password', 'auth/logout', 'logout', 'eula', 'eula/accept', 'eula/decline'], true);
    }
}

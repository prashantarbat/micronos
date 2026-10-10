<?php

namespace App\Libraries;

/**
 * AppVersion — Canonical Versioning & Release Channel Provider for Micron OS.
 *
 * Scheme: MAJOR.MINOR.PATCH (e.g. 0.1.23, 2.4.56)
 * - Minor (2nd digit):
 *   - Odd number (1, 3, 5, ...) => Development version (e.g., 0.1.23, 1.1.24, 2.3.56)
 *   - Even number (0, 2, 4, ...) => Stable version (e.g., 0.2.0, 2.2.11, 2.4.12)
 * - Patch (3rd digit):
 *   - Increments with each project edit, configuration revision, or build (.23, .24, .25).
 * - Project Name: "Micron OS"
 */
final class AppVersion
{
    public const PROJECT_NAME    = 'Micron OS';
    public const DEFAULT_VERSION = '0.1.23';

    private static ?string $cachedVersion = null;

    /**
     * Get the version file path.
     */
    public static function getVersionFilePath(): string
    {
        return defined('ROOTPATH') ? ROOTPATH . 'VERSION' : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'VERSION';
    }

    /**
     * Get the active semantic version string (e.g. "0.1.23").
     */
    public static function getVersion(): string
    {
        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        $path = self::getVersionFilePath();
        if (is_file($path)) {
            $content = trim((string) @file_get_contents($path));
            if ($content !== '' && preg_match('/^\d+\.\d+\.\d+$/', $content)) {
                self::$cachedVersion = $content;
                return self::$cachedVersion;
            }
        }

        self::$cachedVersion = self::DEFAULT_VERSION;
        return self::$cachedVersion;
    }

    /**
     * Reset cached version (useful for tests or after updates).
     */
    public static function clearCache(): void
    {
        self::$cachedVersion = null;
    }

    /**
     * Parse a version string into constituent parts and release channel info.
     *
     * @return array{major: int, minor: int, patch: int, is_dev: bool, is_stable: bool, channel: string}
     */
    public static function parse(?string $version = null): array
    {
        $v = $version ?? self::getVersion();
        $parts = explode('.', $v);

        $major = isset($parts[0]) ? (int) $parts[0] : 0;
        $minor = isset($parts[1]) ? (int) $parts[1] : 1;
        $patch = isset($parts[2]) ? (int) $parts[2] : 23;

        // Odd minor is Development, Even minor is Stable
        $isDev    = ($minor % 2 !== 0);
        $isStable = ! $isDev;
        $channel  = $isDev ? 'Development' : 'Stable';

        return [
            'major'     => $major,
            'minor'     => $minor,
            'patch'     => $patch,
            'is_dev'    => $isDev,
            'is_stable' => $isStable,
            'channel'   => $channel,
        ];
    }

    public static function getMajor(): int
    {
        return self::parse()['major'];
    }

    public static function getMinor(): int
    {
        return self::parse()['minor'];
    }

    public static function getPatch(): int
    {
        return self::parse()['patch'];
    }

    /**
     * Check if current version is in Development channel (odd minor digit).
     */
    public static function isDevelopment(): bool
    {
        return self::parse()['is_dev'];
    }

    /**
     * Check if current version is in Stable channel (even minor digit).
     */
    public static function isStable(): bool
    {
        return self::parse()['is_stable'];
    }

    /**
     * Get release channel name: 'Development' or 'Stable'.
     */
    public static function getChannel(): string
    {
        return self::parse()['channel'];
    }

    /**
     * Get project name: strictly "Micron OS".
     */
    public static function getProjectName(): string
    {
        return self::PROJECT_NAME;
    }

    /**
     * Format full branded display string, e.g. "Micron OS v0.1.23 (Development)".
     */
    public static function getFullVersionString(): string
    {
        return sprintf('%s v%s (%s)', self::PROJECT_NAME, self::getVersion(), self::getChannel());
    }

    /**
     * Return styled badge HTML for views.
     */
    public static function getChannelBadge(): string
    {
        $info = self::parse();
        if ($info['is_dev']) {
            return '<span class="badge badge-warning" style="font-size:0.72rem; letter-spacing:0.04em; text-transform:uppercase; font-weight:700;">Development</span>';
        }
        return '<span class="badge badge-success" style="font-size:0.72rem; letter-spacing:0.04em; text-transform:uppercase; font-weight:700;">Stable</span>';
    }

    /**
     * Increment the patch number for project edits, and save to VERSION.
     *
     * Example: 0.1.23 -> 0.1.24
     */
    public static function incrementPatch(): string
    {
        $info = self::parse();
        $newPatch = $info['patch'] + 1;
        $newVersion = sprintf('%d.%d.%d', $info['major'], $info['minor'], $newPatch);

        self::saveVersion($newVersion);
        return $newVersion;
    }

    /**
     * Set explicit version and save to file.
     */
    public static function setVersion(int $major, int $minor, int $patch): string
    {
        $newVersion = sprintf('%d.%d.%d', $major, $minor, $patch);
        self::saveVersion($newVersion);
        return $newVersion;
    }

    /**
     * Write version string to storage file.
     */
    private static function saveVersion(string $version): void
    {
        $path = self::getVersionFilePath();
        @file_put_contents($path, $version . "\n");
        self::$cachedVersion = $version;
    }
}

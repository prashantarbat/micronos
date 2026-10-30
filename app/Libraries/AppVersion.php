<?php

namespace App\Libraries;

/**
 * AppVersion — Canonical Versioning & Release Channel Provider for Micron OS.
 *
 * Scheme: MAJOR.MINOR.PATCH[-PRERELEASE] (e.g. 0.1.23, 2.4.56, 1.0.0-rc.1)
 * - Minor (2nd digit):
 *   - Odd number (1, 3, 5, ...) => Development version (e.g., 0.1.23, 1.1.24, 2.3.56)
 *   - Even number (0, 2, 4, ...) => Stable version (e.g., 0.2.0, 2.2.11, 2.4.12)
 * - Patch (3rd digit):
 *   - Increments with each project edit, configuration revision, or build (.23, .24, .25).
 * - Pre-release suffix (e.g. "-rc.1") => Release Candidate of that version.
 * - Project Name: "Micron OS"
 */
final class AppVersion
{
    public const PROJECT_NAME    = 'Micron OS';
    public const DEFAULT_VERSION = '0.1.42';

    /** MAJOR.MINOR.PATCH with an optional SemVer pre-release such as "-rc.1". */
    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/';

    private static ?string $cachedVersion = null;

    /**
     * Get the version file path.
     */
    public static function getVersionFilePath(): string
    {
        return defined('ROOTPATH') ? ROOTPATH . 'VERSION' : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'VERSION';
    }

    /**
     * Get the active semantic version string (e.g. "0.0.41").
     */
    public static function getVersion(): string
    {
        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        $path = self::getVersionFilePath();
        if (is_file($path)) {
            $content = trim((string) @file_get_contents($path));
            if ($content !== '' && preg_match(self::VERSION_PATTERN, $content)) {
                self::$cachedVersion = $content;
                return self::$cachedVersion;
            }
        }

        self::$cachedVersion = self::DEFAULT_VERSION;
        return self::$cachedVersion;
    }

    /**
     * Alias for getVersion().
     */
    public static function get(): string
    {
        return self::getVersion();
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
     * @return array{major: int, minor: int, patch: int, prerelease: string, is_dev: bool, is_rc: bool, is_stable: bool, channel: string}
     */
    public static function parse(?string $version = null): array
    {
        $v = $version ?? self::getVersion();
        [$core, $prerelease] = array_pad(explode('-', $v, 2), 2, '');
        $parts = explode('.', $core);

        $major = isset($parts[0]) ? (int) $parts[0] : 0;
        $minor = isset($parts[1]) ? (int) $parts[1] : 0;
        $patch = isset($parts[2]) ? (int) $parts[2] : 41;

        // Odd minor is Development, Even minor is Stable; a pre-release of an
        // even minor is a Release Candidate.
        $isDev    = ($minor % 2 !== 0);
        $isRc     = ! $isDev && $prerelease !== '';
        $isStable = ! $isDev && ! $isRc;
        $channel  = $isDev ? 'Development' : ($isRc ? 'Release Candidate' : 'Stable');

        return [
            'major'      => $major,
            'minor'      => $minor,
            'patch'      => $patch,
            'prerelease' => $prerelease,
            'is_dev'     => $isDev,
            'is_rc'      => $isRc,
            'is_stable'  => $isStable,
            'channel'    => $channel,
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
     * Check if current version is a Release Candidate (pre-release of a stable minor).
     */
    public static function isReleaseCandidate(): bool
    {
        return self::parse()['is_rc'];
    }

    /**
     * Format full branded display string. Releases show the plain version
     * ("Micron OS v1.0.0", "Micron OS v1.0.0-rc.1"); only development builds
     * are labelled ("Micron OS v0.1.23 (Development)").
     */
    public static function getFullVersionString(): string
    {
        if (self::isDevelopment()) {
            return sprintf('%s v%s (%s)', self::PROJECT_NAME, self::getVersion(), self::getChannel());
        }

        return sprintf('%s v%s', self::PROJECT_NAME, self::getVersion());
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
        if ($info['is_rc']) {
            return '<span class="badge badge-info" style="font-size:0.72rem; letter-spacing:0.04em; text-transform:uppercase; font-weight:700;">Release Candidate</span>';
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

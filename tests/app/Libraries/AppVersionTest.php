<?php

namespace Tests\App\Libraries;

use App\Libraries\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    private ?string $savedVersionFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $path = AppVersion::getVersionFilePath();
        $this->savedVersionFile = is_file($path) ? (string) file_get_contents($path) : null;
        AppVersion::setVersion(0, 1, 42);
        AppVersion::clearCache();
    }

    protected function tearDown(): void
    {
        // Restore the VERSION file byte for byte (it may carry "-rc.N").
        if ($this->savedVersionFile !== null) {
            file_put_contents(AppVersion::getVersionFilePath(), $this->savedVersionFile);
        }
        AppVersion::clearCache();
        parent::tearDown();
    }

    public function testDefaultStartingVersion(): void
    {
        $this->assertSame('Micron OS', AppVersion::getProjectName());
        $this->assertSame('0.1.42', AppVersion::getVersion());
        $this->assertSame(0, AppVersion::getMajor());
        $this->assertSame(1, AppVersion::getMinor());
        $this->assertSame(42, AppVersion::getPatch());
    }

    public function testOddMinorIsDevelopment(): void
    {
        $parsed0123 = AppVersion::parse('0.1.23');
        $this->assertTrue($parsed0123['is_dev']);
        $this->assertFalse($parsed0123['is_stable']);
        $this->assertSame('Development', $parsed0123['channel']);

        $parsed2356 = AppVersion::parse('2.3.56');
        $this->assertTrue($parsed2356['is_dev']);
        $this->assertSame('Development', $parsed2356['channel']);

        $parsed1124 = AppVersion::parse('1.1.24');
        $this->assertTrue($parsed1124['is_dev']);
        $this->assertSame('Development', $parsed1124['channel']);
    }

    public function testEvenMinorIsStable(): void
    {
        $parsed0041 = AppVersion::parse('0.0.41');
        $this->assertFalse($parsed0041['is_dev']);
        $this->assertTrue($parsed0041['is_stable']);
        $this->assertSame('Stable', $parsed0041['channel']);

        $parsed2412 = AppVersion::parse('2.4.12');
        $this->assertFalse($parsed2412['is_dev']);
        $this->assertTrue($parsed2412['is_stable']);
        $this->assertSame('Stable', $parsed2412['channel']);

        $parsed2211 = AppVersion::parse('2.2.11');
        $this->assertTrue($parsed2211['is_stable']);
        $this->assertSame('Stable', $parsed2211['channel']);

        $parsed020 = AppVersion::parse('0.2.0');
        $this->assertTrue($parsed020['is_stable']);
        $this->assertSame('Stable', $parsed020['channel']);
    }

    public function testReleaseCandidateIsItsOwnChannel(): void
    {
        $rc = AppVersion::parse('1.0.0-rc.1');
        $this->assertSame([1, 0, 0], [$rc['major'], $rc['minor'], $rc['patch']]);
        $this->assertSame('rc.1', $rc['prerelease']);
        $this->assertTrue($rc['is_rc']);
        $this->assertFalse($rc['is_stable']);
        $this->assertFalse($rc['is_dev']);
        $this->assertSame('Release Candidate', $rc['channel']);

        $final = AppVersion::parse('1.0.0');
        $this->assertFalse($final['is_rc']);
        $this->assertTrue($final['is_stable']);
    }

    public function testReleaseCandidateIsReadFromTheVersionFile(): void
    {
        file_put_contents(AppVersion::getVersionFilePath(), "1.0.0-rc.1\n");
        AppVersion::clearCache();

        $this->assertSame('1.0.0-rc.1', AppVersion::getVersion());
        $this->assertTrue(AppVersion::isReleaseCandidate());
    }

    public function testDisplayStringLabelsOnlyDevelopmentBuilds(): void
    {
        $this->assertSame('Micron OS v0.1.42 (Development)', AppVersion::getFullVersionString());

        AppVersion::setVersion(1, 0, 0);
        $this->assertSame('Micron OS v1.0.0', AppVersion::getFullVersionString());

        file_put_contents(AppVersion::getVersionFilePath(), "1.0.0-rc.1\n");
        AppVersion::clearCache();
        $this->assertSame('Micron OS v1.0.0-rc.1', AppVersion::getFullVersionString());
    }

    public function testIncrementPatch(): void
    {
        $original = AppVersion::getVersion();
        $this->assertSame('0.1.42', $original);

        $next = AppVersion::incrementPatch();
        $this->assertSame('0.1.43', $next);
        $this->assertSame('0.1.43', AppVersion::getVersion());

        // Restore back to 0.1.42
        AppVersion::setVersion(0, 1, 42);
        $this->assertSame('0.1.42', AppVersion::getVersion());
    }
}

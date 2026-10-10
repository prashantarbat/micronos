<?php

namespace Tests\App\Libraries;

use App\Libraries\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AppVersion::setVersion(0, 1, 23);
        AppVersion::clearCache();
    }

    public function testDefaultStartingVersion(): void
    {
        $this->assertSame('Micron OS', AppVersion::getProjectName());
        $this->assertSame('0.1.23', AppVersion::getVersion());
        $this->assertSame(0, AppVersion::getMajor());
        $this->assertSame(1, AppVersion::getMinor());
        $this->assertSame(23, AppVersion::getPatch());
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

    public function testIncrementPatch(): void
    {
        $original = AppVersion::getVersion();
        $this->assertSame('0.1.23', $original);

        $next = AppVersion::incrementPatch();
        $this->assertSame('0.1.24', $next);
        $this->assertSame('0.1.24', AppVersion::getVersion());

        // Restore back to 0.1.23 for clean test state
        AppVersion::setVersion(0, 1, 23);
        $this->assertSame('0.1.23', AppVersion::getVersion());
    }
}

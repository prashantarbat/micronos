<?php

namespace Tests\Unit;

use App\Libraries\VoucherEngine;
use App\Models\CaptiveSessionModel;
use App\Models\CaptiveVoucherModel;
use App\Models\CaptiveZoneModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for VoucherEngine — covers code format, charset validity,
 * uniqueness within a roll, validation pipeline, and session provisioning.
 *
 * @internal
 */
final class VoucherEngineTest extends CIUnitTestCase
{
    // -----------------------------------------------------------------------
    // Code format / charset
    // -----------------------------------------------------------------------

    public function testGeneratedCodeHasCorrectLength(): void
    {
        $engine = $this->makeEngine();
        $code   = $this->invokeGenerateCode($engine);
        // Format: XXXX-XXXX → 9 chars including dash
        $this->assertSame(9, strlen($code));
    }

    public function testGeneratedCodeContainsDashInPosition4(): void
    {
        $engine = $this->makeEngine();
        $code   = $this->invokeGenerateCode($engine);
        $this->assertSame('-', $code[4]);
    }

    public function testGeneratedCodeUsesOnlyAllowedCharset(): void
    {
        $allowed = '23456789ABCDEFGHJKMNPQRSTUVWXYZ-';
        $engine  = $this->makeEngine();

        for ($i = 0; $i < 100; $i++) {
            $code = $this->invokeGenerateCode($engine);
            foreach (str_split($code) as $char) {
                $this->assertStringContainsString($char, $allowed,
                    "Char '{$char}' is not in allowed CHARSET");
            }
        }
    }

    public function testGeneratedCodeDoesNotContainAmbiguousChars(): void
    {
        $ambiguous = ['0', 'O', '1', 'I', 'L'];
        $engine    = $this->makeEngine();

        for ($i = 0; $i < 100; $i++) {
            $code = $this->invokeGenerateCode($engine);
            foreach ($ambiguous as $bad) {
                $this->assertStringNotContainsString($bad, $code,
                    "Ambiguous char '{$bad}' found in code '{$code}'");
            }
        }
    }

    // -----------------------------------------------------------------------
    // generateRoll
    // -----------------------------------------------------------------------

    public function testGenerateRollReturnsRollId(): void
    {
        $voucherModel = $this->makeVoucherModelStub();
        $engine       = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());

        $result = $engine->generateRoll('z1', 5, 60);
        $this->assertArrayHasKey('roll_id', $result);
        $this->assertNotEmpty($result['roll_id']);
    }

    public function testGenerateRollRespectsBatchCount(): void
    {
        $captured     = [];
        $voucherModel = $this->makeVoucherModelStub(function ($rows) use (&$captured) {
            $captured = $rows;
        });

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $engine->generateRoll('z1', 10, 60);

        $this->assertCount(10, $captured);
    }

    public function testGenerateRollClampsCountBelow1To1(): void
    {
        $captured     = [];
        $voucherModel = $this->makeVoucherModelStub(function ($rows) use (&$captured) {
            $captured = $rows;
        });

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $engine->generateRoll('z1', 0, 60);

        $this->assertCount(1, $captured);
    }

    public function testGenerateRollClampsCountAbove1000To1000(): void
    {
        $captured     = [];
        $voucherModel = $this->makeVoucherModelStub(function ($rows) use (&$captured) {
            $captured = $rows;
        });

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $engine->generateRoll('z1', 99999, 60);

        $this->assertCount(1000, $captured);
    }

    public function testGenerateRollReturnsCorrectCount(): void
    {
        $engine = new VoucherEngine($this->makeVoucherModelStub(), $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $result = $engine->generateRoll('z1', 7, 60);

        $this->assertSame(7, $result['count']);
    }

    public function testGenerateRollReturnsSampleCode(): void
    {
        $engine = new VoucherEngine($this->makeVoucherModelStub(), $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $result = $engine->generateRoll('z1', 3, 60);

        $this->assertArrayHasKey('sample_code', $result);
        $this->assertMatchesRegularExpression(
            '/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/',
            $result['sample_code']
        );
    }

    public function testGenerateRollAllCodesAreUnique(): void
    {
        $captured     = [];
        $voucherModel = $this->makeVoucherModelStub(function ($rows) use (&$captured) {
            $captured = $rows;
        });

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $engine->generateRoll('z1', 200, 60);

        $codes = array_column($captured, 'code');
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testGenerateRollUsesCustomRollPrefix(): void
    {
        $engine = new VoucherEngine($this->makeVoucherModelStub(), $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $result = $engine->generateRoll('z1', 2, 60, 0, 0, 0, 1, 'HOTEL');

        $this->assertStringStartsWith('HOTEL-', $result['roll_id']);
    }

    // -----------------------------------------------------------------------
    // validateAndActivate
    // -----------------------------------------------------------------------

    public function testValidateRejectsNonExistentCode(): void
    {
        $voucherModel = new class extends CaptiveVoucherModel {
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return null; }
        };

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $result = $engine->validateAndActivate('XXXX-XXXX', '192.168.1.10', 'AA:BB:CC:DD:EE:FF', 'z1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid', $result['message']);
    }

    public function testValidateRejectsRevokedVoucher(): void
    {
        $voucher = $this->makeVoucher(['status' => 'revoked']);

        $voucherModel = new class($voucher) extends CaptiveVoucherModel {
            private array $v;
            public function __construct(array $v) { $this->v = $v; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return $this->v; }
            public function update($id = null, $data = null): bool { return true; }
        };

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $result = $engine->validateAndActivate('ABCD-EFGH', '192.168.1.10', 'AA:BB:CC:DD:EE:FF', 'z1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('revoked', $result['message']);
    }

    public function testValidateRejectsExpiredVoucher(): void
    {
        $voucher = $this->makeVoucher([
            'status'     => 'active',
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $voucherModel = new class($voucher) extends CaptiveVoucherModel {
            private array $v;
            public function __construct(array $v) { $this->v = $v; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return $this->v; }
            public function update($id = null, $data = null): bool { return true; }
        };

        $engine = new VoucherEngine($voucherModel, $this->makeSessionModelStub(), $this->makeZoneModelStub());
        $result = $engine->validateAndActivate('ABCD-EFGH', '192.168.1.10', 'AA:BB:CC:DD:EE:FF', 'z1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('expired', $result['message']);
    }

    public function testValidateRejectsWhenDeviceLimitExceeded(): void
    {
        $voucher = $this->makeVoucher(['max_devices' => 1, 'expires_at' => null]);

        $voucherModel = new class($voucher) extends CaptiveVoucherModel {
            private array $v;
            public function __construct(array $v) { $this->v = $v; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return $this->v; }
        };

        // Session model returns active count = 1 (equals max_devices)
        $sessionModel = new class extends CaptiveSessionModel {
            public function where($key, $val = null, $escape = null) { return $this; }
            public function countAllResults(bool $reset = true, bool $test = false): int { return 1; }
        };

        $engine = new VoucherEngine($voucherModel, $sessionModel, $this->makeZoneModelStub());
        $result = $engine->validateAndActivate('ABCD-EFGH', '192.168.1.20', 'BB:CC:DD:EE:FF:00', 'z1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Maximum device limit', $result['message']);
    }

    public function testValidateSucceedsForValidVoucher(): void
    {
        $voucher = $this->makeVoucher(['max_devices' => 2, 'expires_at' => null, 'first_used_at' => null]);

        $voucherModel = new class($voucher) extends CaptiveVoucherModel {
            private array $v;
            public function __construct(array $v) { $this->v = $v; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return $this->v; }
            public function update($id = null, $data = null): bool { return true; }
        };

        $sessionModel = new class extends CaptiveSessionModel {
            public function where($key, $val = null, $escape = null) { return $this; }
            public function countAllResults(bool $reset = true, bool $test = false): int { return 0; }
            public function insert($data = null, bool $returnID = true): bool|int|string { return true; }
        };

        $engine = new VoucherEngine($voucherModel, $sessionModel, $this->makeZoneModelStub());
        $result = $engine->validateAndActivate('ABCD-EFGH', '10.0.0.5', 'AA:BB:CC:DD:EE:FF', 'z1');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('session', $result);
        $this->assertSame('10.0.0.5', $result['session']['ip_address']);
    }

    public function testValidateCreatesSessionWithExpiresAt(): void
    {
        $voucher = $this->makeVoucher([
            'max_devices'      => 2,
            'duration_minutes' => 60,
            'expires_at'       => null,
            'first_used_at'    => null,
        ]);

        $voucherModel = new class($voucher) extends CaptiveVoucherModel {
            private array $v;
            public function __construct(array $v) { $this->v = $v; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return $this->v; }
            public function update($id = null, $data = null): bool { return true; }
        };

        $sessionModel = new class extends CaptiveSessionModel {
            public function where($key, $val = null, $escape = null) { return $this; }
            public function countAllResults(bool $reset = true, bool $test = false): int { return 0; }
            public function insert($data = null, bool $returnID = true): bool|int|string { return true; }
        };

        $engine = new VoucherEngine($voucherModel, $sessionModel, $this->makeZoneModelStub());
        $result = $engine->validateAndActivate('ABCD-EFGH', '10.0.0.5', 'AA:BB:CC:DD:EE:FF', 'z1');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['session']['expires_at']);

        $expiresTs = strtotime($result['session']['expires_at']);
        $diffMins  = round(($expiresTs - time()) / 60);
        $this->assertGreaterThanOrEqual(59, $diffMins);
        $this->assertLessThanOrEqual(61, $diffMins);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEngine(): VoucherEngine
    {
        return new VoucherEngine(
            $this->makeVoucherModelStub(),
            $this->makeSessionModelStub(),
            $this->makeZoneModelStub()
        );
    }

    private function makeVoucher(array $overrides = []): array
    {
        return array_merge([
            'id'               => 'v1',
            'zone_id'          => 'z1',
            'code'             => 'ABCDEFGH',
            'status'           => 'active',
            'duration_minutes' => 60,
            'quota_bytes'      => 0,
            'max_devices'      => 1,
            'used_count'       => 0,
            'first_used_at'    => null,
            'expires_at'       => null,
        ], $overrides);
    }

    private function makeVoucherModelStub(?callable $onInsertBatch = null): CaptiveVoucherModel
    {
        return new class($onInsertBatch) extends CaptiveVoucherModel {
            private $cb;
            public function __construct(?callable $cb) { $this->cb = $cb; }
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return null; }
            public function insertBatch(?array $set = null, ?bool $escape = null, int $batchSize = 100, bool $testing = false): int|bool {
                if ($this->cb) { ($this->cb)($set); }
                return count($set ?? []);
            }
            public function update($id = null, $data = null): bool { return true; }
        };
    }

    private function makeSessionModelStub(): CaptiveSessionModel
    {
        return new class extends CaptiveSessionModel {
            public function where($key, $val = null, $escape = null) { return $this; }
            public function countAllResults(bool $reset = true, bool $test = false): int { return 0; }
            public function insert($data = null, bool $returnID = true): bool|int|string { return true; }
        };
    }

    private function makeZoneModelStub(): CaptiveZoneModel
    {
        return new class extends CaptiveZoneModel {
            public function where($key, $val = null, $escape = null) { return $this; }
            public function first() { return null; }
        };
    }

    /**
     * Invoke the protected generateUniqueCode method via reflection.
     */
    private function invokeGenerateCode(VoucherEngine $engine): string
    {
        $ref    = new \ReflectionClass($engine);
        $method = $ref->getMethod('generateUniqueCode');
        $method->setAccessible(true);
        return $method->invoke($engine);
    }
}

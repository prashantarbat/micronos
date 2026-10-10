<?php

namespace Tests\Support;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Base class for application tests.
 *
 * CodeIgniter keeps resolved services and any `Services::injectMock()`
 * overrides in static caches that are **not** cleared between tests. A mock
 * injected for one test therefore leaks into every test that runs after it,
 * making results depend on execution order — a classic source of flaky,
 * hard-to-reproduce failures when exercising daemon-failure paths.
 *
 * Extending this class guarantees each test starts from the framework
 * defaults, so `Services::injectMock(...)` can be used freely (for example to
 * inject a FakeIpcClient) without any cleanup or ordering concerns.
 *
 *     final class MyControllerTest extends BaseTestCase
 *     {
 *         public function testFailure(): void
 *         {
 *             Services::injectMock('ipc', new FakeIpcClient([...]));
 *             // ... no restore needed; the mock is discarded after the test.
 *         }
 *     }
 */
abstract class BaseTestCase extends CIUnitTestCase
{
    /**
     * Discards service instances and mocks injected during the test so the
     * next test resolves the real services again.
     *
     * Passes `false` so the autoloader is not re-initialised on every test.
     */
    protected function resetInjectedMocks(): void
    {
        Services::reset(false);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->resetInjectedMocks();
    }
}

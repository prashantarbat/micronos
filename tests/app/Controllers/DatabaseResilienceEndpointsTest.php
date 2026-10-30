<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * @internal
 */
final class DatabaseResilienceEndpointsTest extends BaseTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
        Services::injectMock('ipc', new FakeIpcClient());
    }

    private function postWithCsrf(string $uri, array $data = []): \CodeIgniter\Test\TestResponse
    {
        $perm = ['system.info.view', 'system.settings.edit', 'dashboard.view'];
        $payload = array_merge($data, [
            config(SecurityConfig::class)->tokenName => service('security')->getHash(),
        ]);

        return $this->withSession([
            'logged_in'     => true,
            'user_id'       => 'u1',
            'username'      => 'admin',
            'is_superadmin' => 1,
            'permissions'   => $perm,
            'user_permissions' => $perm,
        ])->post($uri, $payload);
    }

    public function testDatabaseCheckEndpoint(): void
    {
        $result = $this->postWithCsrf('system/db/check');
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('success', $json);
        $this->assertArrayHasKey('healthy', $json);
        $this->assertArrayHasKey('result', $json);
    }

    public function testDatabaseCheckpointEndpoint(): void
    {
        $result = $this->postWithCsrf('system/db/checkpoint');
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('success', $json);
    }

    public function testDatabaseSnapshotEndpoint(): void
    {
        $result = $this->postWithCsrf('system/db/snapshot');
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('success', $json);
    }
}

<?php

namespace Tests\App\Controllers;

use App\Libraries\AppVersion;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

final class SsoTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private const PERMISSIONS = [
        'dashboard.view',
        'system.info.view',
        'system.settings.edit',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        AppVersion::clearCache();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function sessionData(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    public function testIndexPageLoads(): void
    {
        $result = $this->withSession($this->sessionData())->get('system/sso');

        $result->assertStatus(200);
        $result->assertSee('Centralized Single Sign-On');
        $result->assertSee('Identity Providers');
        $result->assertSee('Microsoft Entra ID');
        $result->assertSee('Okta Workforce Identity');
    }

    public function testSaveProviderCreatesAndUpdates(): void
    {
        // 1. Create
        $slug = 'test-idp-' . bin2hex(random_bytes(4));
        $postData = [
            'name'                 => 'Test IdP Realm',
            'slug'                 => $slug,
            'protocol'             => 'oidc',
            'enabled'              => '1',
            'client_id'            => 'client-abc',
            'client_secret'        => 'secret-xyz',
            'auth_url'             => 'https://idp.example.com/auth',
            'token_url'            => 'https://idp.example.com/token',
            'scopes'               => 'openid email',
            'default_role'         => 'Operator',
            'auto_provision'       => '1',
            'csrf_test_name'       => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('system/sso/save-provider', $postData);

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('system/sso'));

        $this->seeInDatabase('sso_providers', [
            'slug' => $slug,
            'name' => 'Test IdP Realm',
        ]);
    }

    public function testToggleProvider(): void
    {
        $db = \Config\Database::connect();
        $provider = $db->table('sso_providers')->where('slug', 'azure-ad')->get()->getRowArray();
        $this->assertNotNull($provider);

        $targetState = $provider['enabled'] ? 0 : 1;

        $result = $this->withSession($this->sessionData())
                       ->post('system/sso/toggle/' . $provider['id'], [
                           'csrf_test_name' => $this->csrfToken,
                       ]);

        $result->assertStatus(302);
        $this->seeInDatabase('sso_providers', [
            'id'      => $provider['id'],
            'enabled' => $targetState,
        ]);
    }

    public function testSaveSettings(): void
    {
        $postData = [
            'sso_enforced'         => '1',
            'allow_local_fallback' => '1',
            'default_provider_id'  => 'prov_entra',
            'csrf_test_name'       => $this->csrfToken,
        ];

        $result = $this->withSession($this->sessionData())
                       ->post('system/sso/save-settings', $postData);

        $result->assertStatus(302);
        $this->seeInDatabase('sso_settings', [
            'sso_enforced'        => 1,
            'default_provider_id' => 'prov_entra',
        ]);
    }

    public function testPublicSamlMetadata(): void
    {
        $result = $this->get('auth/sso/metadata/okta');

        $result->assertStatus(200);
        $body = strtolower($result->getBody());
        $this->assertStringContainsString('entitydescriptor', $body);
        $this->assertStringContainsString('assertionconsumerservice', $body);
    }

    public function testPublicLoginRedirectForOidc(): void
    {
        $db = \Config\Database::connect();
        $db->table('sso_providers')->where('slug', 'azure-ad')->update(['enabled' => 1]);

        $result = $this->get('auth/sso/login/azure-ad');

        $result->assertStatus(302);
        $location = $result->response()->getHeaderLine('Location');
        $this->assertStringContainsString('https://login.microsoftonline.com', $location);
        $this->assertStringContainsString('response_type=code', $location);
    }

    public function testPublicSamlAcsPost(): void
    {
        $db = \Config\Database::connect();
        $db->table('sso_providers')->where('slug', 'okta')->update(['enabled' => 1]);

        $xml = '<?xml version="1.0"?>' .
            '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">' .
            '  <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>' .
            '  <saml:Assertion>' .
            '    <saml:Issuer>http://www.okta.com/exk1exampleEntityId</saml:Issuer>' .
            '    <saml:Subject>' .
            '      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">saml.user@micronos.test</saml:NameID>' .
            '    </saml:Subject>' .
            '    <saml:Conditions NotBefore="' . gmdate('Y-m-d\TH:i:s\Z', time() - 30) . '" NotOnOrAfter="' . gmdate('Y-m-d\TH:i:s\Z', time() + 300) . '"/>' .
            '    <saml:AttributeStatement>' .
            '      <saml:Attribute Name="displayName"><saml:AttributeValue>SAML Test Engineer</saml:AttributeValue></saml:Attribute>' .
            '      <saml:Attribute Name="Role"><saml:AttributeValue>Okta-SuperAdmins</saml:AttributeValue></saml:Attribute>' .
            '    </saml:AttributeStatement>' .
            '  </saml:Assertion>' .
            '</samlp:Response>';

        $postData = [
            'SAMLResponse' => base64_encode($xml),
        ];

        $result = $this->post('auth/sso/saml/acs/okta', $postData);

        $result->assertStatus(302);
        $result->assertRedirectTo(base_url('dashboard'));

        // Confirm user was JIT provisioned in users table
        $this->seeInDatabase('users', [
            'email' => 'saml.user@micronos.test',
            'role'  => 'SuperAdmin',
        ]);
    }
}

<?php

namespace Tests\App\Libraries;

use App\Libraries\SsoClient;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class SsoClientTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    private SsoClient $client;
    private UserModel $userModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userModel = new UserModel();
        $this->client = new SsoClient(null, $this->userModel);
    }

    public function testPkceGeneration(): void
    {
        $pkce = $this->client->generatePkce();
        $this->assertNotEmpty($pkce['verifier']);
        $this->assertNotEmpty($pkce['challenge']);

        // Verify SHA256 relationship
        $computedChallenge = $this->client->base64UrlEncode(hash('sha256', $pkce['verifier'], true));
        $this->assertSame($computedChallenge, $pkce['challenge']);
    }

    public function testBuildAuthorizationUrl(): void
    {
        $provider = [
            'name'      => 'Test Azure',
            'auth_url'  => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'client_id' => 'client-123',
            'scopes'    => 'openid profile email',
        ];

        $url = $this->client->buildAuthorizationUrl(
            $provider,
            'https://firewall.local/auth/sso/callback/azure',
            'state-xyz',
            'nonce-abc',
            'challenge-123'
        );

        $this->assertStringContainsString('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        $this->assertStringContainsString('client_id=client-123', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=state-xyz', $url);
        $this->assertStringContainsString('nonce=nonce-abc', $url);
        $this->assertStringContainsString('code_challenge=challenge-123', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function testParseJwtClaims(): void
    {
        $header = $this->client->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->client->base64UrlEncode(json_encode([
            'sub'               => 'oidc-user-999',
            'preferred_username'=> 'admin.corp',
            'email'             => 'admin@corp.example',
            'name'              => 'Alice Admin',
            'roles'             => ['Global Administrator'],
        ]));
        $fakeJwt = "{$header}.{$payload}.sig";

        $claims = $this->client->parseJwtClaims($fakeJwt);

        $this->assertSame('oidc-user-999', $claims['sub']);
        $this->assertSame('admin.corp', $claims['preferred_username']);
        $this->assertSame('admin@corp.example', $claims['email']);
        $this->assertSame(['Global Administrator'], $claims['roles']);
    }

    public function testGenerateSpMetadataXml(): void
    {
        $provider = [
            'slug'         => 'okta-saml',
            'sp_entity_id' => 'micronos-sp-entity',
        ];

        $xml = $this->client->generateSpMetadataXml($provider, 'https://firewall.local/auth/sso/saml/acs/okta-saml');

        $this->assertStringContainsString('md:EntityDescriptor', $xml);
        $this->assertStringContainsString('entityID="micronos-sp-entity"', $xml);
        $this->assertStringContainsString('Location="https://firewall.local/auth/sso/saml/acs/okta-saml"', $xml);
        $this->assertStringContainsString('Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"', $xml);
    }

    public function testBuildSamlAuthnRequestUrl(): void
    {
        $provider = [
            'name'         => 'Corporate Okta',
            'slug'         => 'okta-saml',
            'idp_sso_url'  => 'https://company.okta.com/app/sso/saml',
            'sp_entity_id' => 'micronos-sp',
        ];

        $url = $this->client->buildSamlAuthnRequestUrl($provider, 'https://firewall.local/auth/sso/saml/acs/okta-saml', 'relay-state-123');

        $this->assertStringContainsString('https://company.okta.com/app/sso/saml?', $url);
        $this->assertStringContainsString('SAMLRequest=', $url);
        $this->assertStringContainsString('RelayState=relay-state-123', $url);
    }

    public function testParseSamlResponseXml(): void
    {
        $xml = '<?xml version="1.0"?>' .
            '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">' .
            '  <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>' .
            '  <saml:Assertion>' .
            '    <saml:Issuer>http://idp.example.com</saml:Issuer>' .
            '    <saml:Subject>' .
            '      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">bob@example.com</saml:NameID>' .
            '    </saml:Subject>' .
            '    <saml:Conditions NotBefore="' . gmdate('Y-m-d\TH:i:s\Z', time() - 30) . '" NotOnOrAfter="' . gmdate('Y-m-d\TH:i:s\Z', time() + 300) . '"/>' .
            '    <saml:AttributeStatement>' .
            '      <saml:Attribute Name="displayName"><saml:AttributeValue>Bob Security</saml:AttributeValue></saml:Attribute>' .
            '      <saml:Attribute Name="Role"><saml:AttributeValue>Network Engineers</saml:AttributeValue></saml:Attribute>' .
            '    </saml:AttributeStatement>' .
            '  </saml:Assertion>' .
            '</samlp:Response>';

        $base64 = base64_encode($xml);
        $parsed = $this->client->parseSamlResponse($base64);

        $this->assertSame('bob@example.com', $parsed['name_id']);
        $this->assertSame('http://idp.example.com', $parsed['issuer']);
        $this->assertSame(['Bob Security'], $parsed['attributes']['displayName']);
        $this->assertSame(['Network Engineers'], $parsed['attributes']['Role']);
    }

    public function testResolveProfileAndRoleMapping(): void
    {
        $provider = [
            'default_role'      => 'Operator',
            'role_mapping_json' => json_encode([
                'SecOps Team'       => 'SuperAdmin',
                'Network Engineers' => 'Administrator',
            ]),
        ];

        $claims = [
            'sub'      => 'sub-101',
            'email'    => 'alice@enterprise.com',
            'name'     => 'Alice Smith',
            'roles'    => ['SecOps Team'],
        ];

        $profile = $this->client->resolveProfileAndRole($provider, $claims);

        $this->assertSame('sub-101', $profile['sub']);
        $this->assertSame('alice', $profile['username']);
        $this->assertSame('alice@enterprise.com', $profile['email']);
        $this->assertSame('Alice Smith', $profile['full_name']);
        $this->assertSame('SuperAdmin', $profile['role']);
    }

    public function testProvisionOrUpdateUser(): void
    {
        $provider = [
            'slug'           => 'unit-test-idp',
            'auto_provision' => 1,
            'default_role'   => 'Operator',
        ];

        $profile = [
            'sub'       => 'test-sub-' . bin2hex(random_bytes(4)),
            'username'  => 'sso_unit_' . bin2hex(random_bytes(3)),
            'email'     => 'unit_' . bin2hex(random_bytes(3)) . '@example.com',
            'full_name' => 'Unit Test User',
            'role'      => 'Operator',
            'groups'    => [],
        ];

        $user = $this->client->provisionOrUpdateUser($provider, $profile);

        $this->assertNotEmpty($user['id']);
        $this->assertSame($profile['username'], $user['username']);
        $this->assertSame($profile['email'], $user['email']);
        $this->assertSame('sso:unit-test-idp', $user['auth_source']);
        $this->assertSame($profile['sub'], $user['sso_sub']);

        // Second call should update, not re-insert duplicate
        $updatedUser = $this->client->provisionOrUpdateUser($provider, array_merge($profile, [
            'full_name' => 'Unit Test User Updated',
        ]));

        $this->assertSame($user['id'], $updatedUser['id']);
    }
}

<?php

namespace Tests\App\Controllers;

use App\Controllers\Terminal;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeTerminalClient;

/**
 * Web Terminal authorisation (Controllers/Terminal.php): the server decides who
 * gets which shell; the browser only ever holds a one-time ticket.
 *
 * @internal
 */
final class TerminalControllerTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private FakeTerminalClient $term;
    private string $userId = 'term_test_user';

    protected function setUp(): void
    {
        parent::setUp();
        $this->term = new FakeTerminalClient();
        Services::injectMock('terminalClient', $this->term);
        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        $this->db->table('users')->where('id', $this->userId)->delete();
        $this->db->table('users')->insert([
            'id' => $this->userId, 'username' => 'termtester', 'email' => 'termtester@example.invalid',
            'password_hash' => password_hash('Correct-Horse-1', PASSWORD_BCRYPT), 'auth_source' => 'local',
            'role' => 'admin', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        putenv('TERMINAL_REQUIRE_TLS');
        // A browser on the management LAN.
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
        service('superglobals')->setServer('REMOTE_ADDR', '192.168.1.50');
    }

    protected function tearDown(): void
    {
        $this->db->table('users')->where('id', $this->userId)->delete();
        putenv('TERMINAL_REQUIRE_TLS');
        parent::tearDown();
    }

    /** @param list<string> $perms */
    private function as(array $perms, bool $super = false, array $extra = []): static
    {
        return $this->withSession(array_merge([
            'logged_in' => true, 'user_id' => $this->userId, 'username' => 'termtester', 'role' => 'admin',
            'is_superadmin' => $super ? 1 : 0, 'permissions' => $perms, 'user_permissions' => $perms,
        ], $extra));
    }

    private function token(): array
    {
        return [config(SecurityConfig::class)->tokenName => service('security')->getHash()];
    }

    public function testThePageRendersTheTerminalWithLocalAssets(): void
    {
        $body = $this->as(['terminal.access'])->get('terminal')->getBody();

        $this->assertStringContainsString('assets/vendor/xterm/xterm.js', $body);
        $this->assertStringContainsString('assets/vendor/xterm/addon-fit.js', $body);
        $this->assertStringContainsString('id="termNewStd"', $body);
        $this->assertStringNotContainsString('id="termNewAdmin"', $body, 'no root button without terminal.admin');
        $this->assertStringNotContainsString('cdn.jsdelivr', $body);
        $this->assertFileExists(FCPATH . 'assets/vendor/xterm/xterm.js');
    }

    public function testThePageSaysSoWhenTheServiceIsDown(): void
    {
        $this->term->running = false;
        $body = $this->as(['terminal.access'])->get('terminal')->getBody();

        $this->assertStringContainsString('micron-terminald', $body);
        $this->assertStringContainsString('is not running', $body);
        $this->assertMatchesRegularExpression('/id="termNewStd"[^>]*disabled/', $body);
    }

    public function testWithoutTerminalAccessNothingIsIssued(): void
    {
        $this->as(['dashboard.view'])->get('terminal')->assertRedirect();
        $this->as(['dashboard.view'])->post('terminal/session', $this->token() + ['level' => 'standard']);
        $this->assertNotContains('terminal.issue', $this->term->methods());
    }

    public function testAStandardTicketIsBoundToUserOriginAndClient(): void
    {
        $r = $this->as(['terminal.access'])->post('terminal/session', $this->token() + ['level' => 'standard']);
        $r->assertOK();
        $json = json_decode($r->getJSON(), true);

        $this->assertTrue($json['ok']);
        $this->assertStringStartsWith('tkt-', $json['ticket']);
        $this->assertMatchesRegularExpression('#^wss?://[^/]+/terminal/ws$#', $json['ws_url']);
        $issued = $this->term->calls[array_search('terminal.issue', $this->term->methods(), true)]['params'];
        $this->assertSame($this->userId, $issued['user_id']);
        $this->assertSame('standard', $issued['level']);
        $this->assertMatchesRegularExpression('#^https?://#', $issued['origin']);
        $this->assertNotEmpty($issued['client_ip']);
    }

    public function testRootNeedsThePermission(): void
    {
        $r = $this->as(['terminal.access'])->post('terminal/session', $this->token() + ['level' => 'admin']);
        $r->assertStatus(403);
        $this->assertNotContains('terminal.issue', $this->term->methods());
    }

    public function testRootNeedsThePasswordAgainAndAWrongOneIsRefused(): void
    {
        $perms = ['terminal.access', 'terminal.admin'];
        $r = $this->as($perms)->post('terminal/session', $this->token() + ['level' => 'admin']);
        $r->assertStatus(401);
        $this->assertTrue(json_decode($r->getJSON(), true)['reauth']);

        $this->as($perms)->post('terminal/elevate', $this->token() + ['password' => 'wrong'])->assertStatus(401);
        $this->assertNotContains('terminal.issue', $this->term->methods());
    }

    public function testRootTicketAfterReauthentication(): void
    {
        $perms = ['terminal.access', 'terminal.admin'];
        $this->as($perms)->post('terminal/elevate', $this->token() + ['password' => 'Correct-Horse-1'])->assertOK();
        $until = session('terminal_admin_until');
        $this->assertGreaterThan(time(), (int) $until);

        $r = $this->as($perms, false, ['terminal_admin_until' => $until])->post('terminal/session', $this->token() + ['level' => 'admin']);
        $r->assertOK();
        $issued = $this->term->calls[array_search('terminal.issue', $this->term->methods(), true)]['params'];
        $this->assertSame('admin', $issued['level']);
    }

    public function testRepeatedWrongPasswordsLockElevation(): void
    {
        $perms = ['terminal.access', 'terminal.admin'];
        for ($i = 0; $i < 5; $i++) {
            $this->as($perms, false, ['terminal_reauth_failures' => $i])->post('terminal/elevate', $this->token() + ['password' => 'nope']);
        }
        $this->as($perms, false, ['terminal_reauth_locked_until' => time() + 300])
            ->post('terminal/elevate', $this->token() + ['password' => 'Correct-Horse-1'])->assertStatus(429);
    }

    public function testRequireTlsRefusesPlainHttp(): void
    {
        putenv('TERMINAL_REQUIRE_TLS=1');
        \CodeIgniter\Config\Factories::reset('config');
        $r = $this->as(['terminal.access'])->post('terminal/session', $this->token() + ['level' => 'standard']);
        $r->assertStatus(403);
        $this->assertNotContains('terminal.issue', $this->term->methods());
    }

    public function testPlainHttpFromAPublicAddressIsRefused(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        service('superglobals')->setServer('REMOTE_ADDR', '203.0.113.9');
        $this->as(['terminal.access'])->post('terminal/session', $this->token() + ['level' => 'standard'])->assertStatus(403);
        $this->assertNotContains('terminal.issue', $this->term->methods());
    }

    public function testPrivateAddressClassification(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.5.5', '192.168.1.10', '100.64.0.9', '169.254.1.1', '::1', 'fd00::1', 'fe80::1'] as $ip) {
            $this->assertTrue(Terminal::isPrivate($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '2001:4860::8888', 'not-an-ip'] as $ip) {
            $this->assertFalse(Terminal::isPrivate($ip), $ip);
        }
    }

    public function testInvalidAttachIdsAndLevelsAreRefused(): void
    {
        $this->as(['terminal.access'])->post('terminal/session', $this->token() + ['level' => 'standard', 'attach' => '../x'])->assertStatus(400);
        $this->as(['terminal.access'])->post('terminal/session', $this->token() + ['level' => 'root'])->assertStatus(400);
        $this->assertNotContains('terminal.issue', $this->term->methods());
    }

    public function testKillIsScopedToTheOwner(): void
    {
        $this->term->sessionList = [['id' => 'aaaaaaaaaaaaaaaa', 'user_id' => 'someone_else', 'level' => 'standard']];
        $this->as(['terminal.access'])->post('terminal/sessions/aaaaaaaaaaaaaaaa/kill', $this->token())->assertStatus(404);
        $call = $this->term->calls[array_search('terminal.kill', $this->term->methods(), true)]['params'];
        $this->assertSame($this->userId, $call['user_id'], 'the controller always passes the owner');
    }

    public function testSignOutEndsRootSessions(): void
    {
        $this->as(['terminal.access'])->get('auth/logout');
        $revoke = array_values(array_filter($this->term->calls, static fn ($c) => $c['method'] === 'terminal.revoke_user'));
        $this->assertNotEmpty($revoke);
        $this->assertSame('admin', $revoke[0]['params']['level']);
    }

    public function testTheTerminalAppearsInTheNavigationOnlyWithThePermission(): void
    {
        $with    = $this->as(['terminal.access', 'dashboard.view'])->get('terminal')->getBody();
        $without = $this->as(['dashboard.view', 'system.info.view'], false)->get('system')->getBody();

        $this->assertStringContainsString('href="' . base_url('terminal') . '"', $with);
        $this->assertStringNotContainsString('href="' . base_url('terminal') . '"', $without);
    }

    public function testTerminalCursorBlinkUsesSlowerAnimation(): void
    {
        $body = $this->as(['terminal.access'])->get('terminal')->getBody();

        $this->assertStringContainsString(
            '.term-pane .xterm-cursor.xterm-cursor-blink { animation-duration: 2s !important; }',
            $body
        );
    }

    public function testTerminalViewportHasAVisibleScrollbar(): void
    {
        $body = $this->as(['terminal.access'])->get('terminal')->getBody();

        $this->assertStringContainsString('.term-pane .xterm-viewport {', $body);
        $this->assertStringContainsString('overflow-y: scroll !important;', $body);
        $this->assertStringContainsString('.term-pane .xterm-viewport::-webkit-scrollbar { width: 10px; }', $body);
    }
}

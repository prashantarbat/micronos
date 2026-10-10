<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * Diagnostics: the Source Address selects, against pfSense.
 *
 * The two Diagnostics pages that send a probe from a chosen address — Ping and
 * Traceroute — each carry a Source Address select. Both draws their candidates
 * from the one list, but pfSense deliberately words them differently, and this
 * class pins that difference so it is never "tidied up" into a divergence from
 * pfSense:
 *
 *  - pfSense `diag_ping.php`:
 *      '*Source address' (lower-case "address"), default key `''` labelled
 *      "Automatically selected (default)", help "Select source address for the
 *      ping."
 *  - pfSense `diag_traceroute.php`:
 *      '*Source Address' (capital "Address"), default key `'any'` labelled
 *      "Any", help "Select source address for the trace."
 *
 * The wording is pfSense's own, quoted from its source; a field that reads
 * differently from the page it is copied from is the bug this guards against.
 *
 * @internal
 */
final class DiagnosticsSourceAddressTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'system.info.view',
        'diag.ping',
        'diag.packet_capture',
        'diag.logs.view',
        'diag.audit.view',
        'diag.arp.view',
        'diag.states.view',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    private function session(): array
    {
        return [
            'logged_in'        => true,
            'user_id'          => 'admin_user',
            'username'         => 'admin',
            'role'             => 'admin',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }

    /** The rendered page markup. */
    private function page(): string
    {
        Services::injectMock('ipc', new FakeIpcClient());

        return (string) $this->withSession($this->session())->get('diag')->getBody();
    }

    /** The markup of one select, so an assertion cannot drift into the other tab. */
    private function select(string $body, string $id): string
    {
        $this->assertMatchesRegularExpression('/<select id="' . preg_quote($id, '/') . '"/', $body);
        preg_match('#<select id="' . preg_quote($id, '/') . '".*?</select>#s', $body, $matches);

        return $matches[0] ?? '';
    }

    public function testThePingSourceSelectMatchesPfsenseExactly(): void
    {
        $body = $this->page();

        // pfSense's ping label keeps "address" in lower case.
        $this->assertStringContainsString('for="pingSource">Source address</label>', $body);
        $this->assertStringContainsString('Select source address for the ping.', $body);

        $select = $this->select($body, 'pingSource');
        // The default key is '' and its label is pfSense's "Automatically selected (default)".
        $this->assertStringContainsString('<option value="">Automatically selected (default)</option>', $select);
        // pfSense's ping page offers no "Any"; that spelling belongs to traceroute.
        $this->assertStringNotContainsString('>Any<', $select);
    }

    public function testTheTracerouteSourceSelectMatchesPfsenseExactly(): void
    {
        $body = $this->page();

        // pfSense's traceroute label capitalises "Address".
        $this->assertStringContainsString('for="traceSource">Source Address</label>', $body);
        $this->assertStringContainsString('Select source address for the trace.', $body);

        $select = $this->select($body, 'traceSource');
        // The default key is 'any' and its label is pfSense's "Any".
        $this->assertStringContainsString('<option value="any">Any</option>', $select);
        // pfSense's traceroute page does not use the ping page's default label.
        $this->assertStringNotContainsString('Automatically selected', $select);
    }

    /**
     * Both selects are one list: the candidates come from the same
     * `$probeSources` the controller resolves once per request.
     */
    public function testTheTwoSourceSelectsShareOneCandidateList(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/diag/index.php');

        $this->assertSame(
            2,
            substr_count($view, 'foreach ($probeSources as $source)'),
            'Both the ping and the traceroute Source Address selects must draw from the one list.'
        );
    }
}

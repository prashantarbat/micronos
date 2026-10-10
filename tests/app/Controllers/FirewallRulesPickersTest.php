<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;
use Tests\Support\BaseTestCase;

/**
 * The "New / Edit Firewall Policy" dialog on `firewall/rules`: each object
 * picker (sources, destinations, services) must offer a search box.
 *
 * The pickers are native `<select multiple>` list boxes holding every address,
 * group, service and group on the appliance — hundreds of rows on a real
 * install. Without a filter the only way to reach an object is Ctrl-click
 * hunting, so this test pins the search box, its "no matches" note, and the
 * wiring that binds each box to its picker.
 *
 * @internal
 */
final class FirewallRulesPickersTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private const PERMISSIONS = [
        'firewall.rules.view',
        'firewall.rules.edit',
        'firewall.rules.delete',
        'firewall.rules.apply',
    ];

    /** Picker ids, mirroring the PICK_* maps in the view's script. */
    private const PICKERS = ['polSources', 'polDestinations', 'polServices'];

    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE[config(SecurityConfig::class)->cookieName] = bin2hex(random_bytes(16));
        Services::injectMock('security', new Security(config(SecurityConfig::class)));
    }

    public function testEachObjectPickerHasASearchBoxAndANoMatchesNote(): void
    {
        $page = $this->withSession($this->session())->get('firewall/rules');

        $page->assertOK();

        foreach (self::PICKERS as $picker) {
            $page->assertSee('id="' . $picker . 'Search"');
            $page->assertSee('id="' . $picker . 'Empty"');
            // The select the box filters must still be there.
            $page->assertSee('id="' . $picker . '"');
        }

        // A search input without the wiring behind it would look present but do
        // nothing, so pin the binding too: the behaviour (filtering, the ↑ / ↓
        // highlight, Space, Enter) is the shared helper, pulled in once here.
        $this->assertSame(1, substr_count((string) $page->getBody(), 'window.MicronPickerSearch'));
        $page->assertSee('MicronPickerSearch.init(');
        $page->assertSee("select: 'polSources'");
        $page->assertSee("select: 'polDestinations'");
        $page->assertSee("select: 'polServices'");
        $page->assertSee('MicronPickerSearch.reset();');
    }

    public function testBothDialogEntryPointsResetTheFilters(): void
    {
        $page = $this->withSession($this->session())->get('firewall/rules');

        $page->assertOK();

        // One reset in openCreatePolicyModal(), one in editPolicy().
        $this->assertSame(2, substr_count((string) $page->getBody(), 'MicronPickerSearch.reset();'));
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => self::PERMISSIONS,
            'user_permissions' => self::PERMISSIONS,
        ];
    }
}

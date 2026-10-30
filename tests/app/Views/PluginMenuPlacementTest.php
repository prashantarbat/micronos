<?php

namespace Tests\App\Views;

use App\Libraries\NavGroups;
use App\Libraries\PluginNav;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Installed plugins are reachable from the functional menu they belong to
 * (docs/MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md §8.1), while the Plugins
 * group keeps its own management pages.
 *
 * @internal
 */
final class PluginMenuPlacementTest extends CIUnitTestCase
{
    public function testEveryFirstPartyPluginHasAFunctionalSection(): void
    {
        $definitions = array_map('basename', glob(APPPATH . 'Plugins/definitions/*', GLOB_ONLYDIR));
        sort($definitions);
        $mapped = array_keys(PluginNav::SECTIONS);
        sort($mapped);

        $this->assertSame($definitions, $mapped, 'every shipped plugin needs a functional menu placement');
        foreach (PluginNav::SECTIONS as $id => $section) {
            $this->assertContains($section, NavGroups::keys(), "{$id} maps to a section that is not a rail parent");
        }
    }

    public function testAPluginPageIsOwnedByItsFunctionalSection(): void
    {
        $this->assertTrue(NavGroups::owns('/plugins/lldpd', 'network'));
        $this->assertFalse(NavGroups::owns('/plugins/lldpd', 'plugins'));
        $this->assertTrue(NavGroups::owns('/plugins/zerotier/status', 'vpn'));
        $this->assertTrue(NavGroups::owns('/plugins/nat64', 'routing'));
    }

    public function testPluginManagementPagesStayUnderPlugins(): void
    {
        foreach (['/plugins', '/plugins/updates', '/plugins/repositories'] as $path) {
            $this->assertTrue(NavGroups::owns($path, 'plugins'), "{$path} must stay under Plugins");
        }
    }

    public function testEntriesForFiltersBySection(): void
    {
        $entries = [
            ['id' => 'lldpd', 'label' => 'LLDP Neighbours', 'path' => '/plugins/lldpd', 'permission' => 'plugins.view', 'name' => 'LLDP'],
            ['id' => 'zerotier', 'label' => 'ZeroTier', 'path' => '/plugins/zerotier', 'permission' => 'plugins.view', 'name' => 'ZT'],
            ['id' => 'unknown-third-party', 'label' => 'X', 'path' => '/plugins/x', 'permission' => '', 'name' => 'X'],
        ];

        $this->assertSame(['lldpd'], array_column(PluginNav::entriesFor('network', $entries), 'id'));
        $this->assertSame(['zerotier'], array_column(PluginNav::entriesFor('vpn', $entries), 'id'));
        $this->assertSame([], PluginNav::entriesFor('security', $entries), 'an unmapped plugin appears only under Plugins');
    }
}

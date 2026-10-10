<?php

declare(strict_types=1);

use App\Libraries\RepoPresets;
use App\Libraries\RepoRegistry;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

/**
 * Preset third-party repositories (Plugin Spec §5.2, P3).
 *
 * A preset is the one place where MicronOS ships trust data rather than asking
 * an operator to type it, so these tests are about the *contract* the shipped
 * data must satisfy: it is the vendor's published key, the fingerprint recorded
 * is the one that signs the vendor's metadata, and the whole thing still has to
 * pass the same validation a hand-entered repository does.
 *
 * The cryptographic half is checked by two harnesses: `tests/test_repo_presets.py`
 * recomputes each preset's fingerprint from the embedded key with no gpg and no
 * network (so a corrupted paste cannot ship), and the opt-in
 * `tests/test_repo_presets_live.py --live` fetches the vendor's `InRelease` and
 * proves the declared fingerprint really signs it.
 *
 * @internal
 */
final class RepoPresetsTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRepos();
    }

    protected function tearDown(): void
    {
        $this->clearRepos();

        parent::tearDown();
    }

    public function testNetbirdPresetIsTheVendorRepositoryWithItsVerifiedFingerprint(): void
    {
        $preset = (new RepoPresets())->get('netbird');

        $this->assertNotNull($preset);
        $this->assertSame('https://pkgs.netbird.io/debian', $preset['url']);
        // Suite `stable`, not a codename: the preset must stay correct whatever
        // Debian release the appliance runs.
        $this->assertSame('stable', $preset['suite']);
        $this->assertNotSame('./', $preset['suite']);
        $this->assertSame(['main'], $preset['components']);
        $this->assertSame('EFE37DF047DF7CCDF1FC54FA83F79AD029778355', $preset['fingerprint']);
        $this->assertSame('2026-10-06', $preset['verified_at']);
        $this->assertSame(['netbird'], $preset['plugins']);
    }

    public function testPresetKeyIsTheVendorsArmoredPublicKey(): void
    {
        $preset = (new RepoPresets())->get('netbird');

        $armor = (string) $preset['key_armor'];
        $this->assertStringStartsWith('-----BEGIN PGP PUBLIC KEY BLOCK-----', $armor);
        $this->assertStringContainsString('-----END PGP PUBLIC KEY BLOCK-----', $armor);

        // 5FA4DC0963D58E3AF4CC687CD3763BC13139C64F is the encryption subkey of the
        // same primary key; it is not what signs the metadata, so it must never
        // be the declared fingerprint.
        $this->assertStringNotContainsString('5FA4DC0963D58E3AF4CC687CD3763BC13139C64F', (string) $preset['fingerprint']);
        $this->assertSame('https://pkgs.netbird.io/debian/public.key', $preset['key_source_url']);
    }

    /**
     * The key travels inline, never by URL: a repository accepts one or the
     * other, and inline means the appliance never has to fetch key material from
     * the same host whose metadata it is being asked to trust.
     */
    public function testPresetFeedsTheKeyInlineAndLandsDisabled(): void
    {
        $input = RepoPresets::input((new RepoPresets())->get('netbird'));

        $this->assertSame('', $input['key_url']);
        $this->assertNotSame('', $input['key_armor']);
        $this->assertFalse($input['enabled']);
        $this->assertSame('EFE37DF047DF7CCDF1FC54FA83F79AD029778355', $input['key_fingerprint']);
    }

    public function testPresetPassesTheSameValidationAsAHandEnteredRepository(): void
    {
        $normalised = RepoRegistry::normalize(RepoPresets::input((new RepoPresets())->get('netbird')));

        $this->assertTrue($normalised['ok'], implode(' ', $normalised['errors']));
        $this->assertSame('netbird', $normalised['repo']['id']);
        $this->assertSame('pkgs.netbird.io', $normalised['repo']['origin']);
        $this->assertSame(['main'], $normalised['repo']['components']);
        $this->assertSame(['amd64', 'arm64'], $normalised['repo']['architectures']);
    }

    public function testZerotierPresetIsTheVendorRepositoryWithItsVerifiedFingerprint(): void
    {
        $preset = (new RepoPresets())->get('zerotier');

        $this->assertNotNull($preset);
        $this->assertSame('https://download.zerotier.com/debian/trixie', $preset['url']);
        // ZeroTier publishes one distribution *per Debian codename* and offers no
        // release-independent suite, so this preset is image-specific by
        // necessity: the image is Debian 13 "trixie".
        $this->assertSame('trixie', $preset['suite']);
        $this->assertNotSame('./', $preset['suite']);
        $this->assertSame(['main'], $preset['components']);
        $this->assertSame(['amd64', 'arm64'], $preset['architectures']);
        $this->assertSame('74A5E9C458E1A431F1DA57A71657198823E52A61', $preset['fingerprint']);
        $this->assertSame('2026-10-09', $preset['verified_at']);
        $this->assertSame(['zerotier'], $preset['plugins']);
    }

    public function testZerotierPresetKeyIsTheVendorsArmoredPublicKey(): void
    {
        $preset = (new RepoPresets())->get('zerotier');

        $armor = (string) $preset['key_armor'];
        $this->assertStringStartsWith('-----BEGIN PGP PUBLIC KEY BLOCK-----', $armor);
        $this->assertStringContainsString('-----END PGP PUBLIC KEY BLOCK-----', $armor);

        // 41CA9BC62B9FBDC669DB74225A4011580999FBE0 is the encryption subkey of the
        // same primary key; it is not what signs the metadata, so it must never
        // be the declared fingerprint.
        $this->assertStringNotContainsString('41CA9BC62B9FBDC669DB74225A4011580999FBE0', (string) $preset['fingerprint']);
        $this->assertSame('https://download.zerotier.com/contact%40zerotier.com.gpg', $preset['key_source_url']);
    }

    public function testZerotierPresetFeedsTheKeyInlineAndLandsDisabled(): void
    {
        $input = RepoPresets::input((new RepoPresets())->get('zerotier'));

        $this->assertSame('', $input['key_url']);
        $this->assertNotSame('', $input['key_armor']);
        $this->assertFalse($input['enabled']);
        $this->assertSame('74A5E9C458E1A431F1DA57A71657198823E52A61', $input['key_fingerprint']);
    }

    public function testZerotierPresetPassesTheSameValidationAsAHandEnteredRepository(): void
    {
        $normalised = RepoRegistry::normalize(RepoPresets::input((new RepoPresets())->get('zerotier')));

        $this->assertTrue($normalised['ok'], implode(' ', $normalised['errors']));
        $this->assertSame('zerotier', $normalised['repo']['id']);
        $this->assertSame('download.zerotier.com', $normalised['repo']['origin']);
        $this->assertSame(['main'], $normalised['repo']['components']);
        $this->assertSame(['amd64', 'arm64'], $normalised['repo']['architectures']);
    }

    public function testSeedingTheZerotierPresetRegistersItDisabled(): void
    {
        $registry = new RepoRegistry();

        $result = $registry->seedPreset('zerotier', 'preset');

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        $this->assertTrue($result['created']);

        $row = $registry->get('zerotier');
        $this->assertNotNull($row);
        $this->assertFalse($row['enabled'], 'a preset must not talk to the vendor until the operator enables it');
        $this->assertSame('74A5E9C458E1A431F1DA57A71657198823E52A61', $row['fingerprint']);
    }

    /**
     * A preset exists to satisfy some plugin's `requires.repos`. One that names
     * no plugin is trust data shipped for nothing, so the binding is asserted
     * rather than left to a reviewer's eye.
     */
    public function testEveryPresetIsBoundToAtLeastOnePlugin(): void
    {
        foreach ((new RepoPresets())->all() as $id => $preset) {
            $this->assertNotSame([], (array) $preset['plugins'], "preset {$id} names no plugin");
            $this->assertNotSame([], (new RepoPresets())->for($id), "preset {$id} is not reachable from its plugin");
        }
    }

    public function testPresetsAreAddressableByPlugin(): void
    {
        $presets = new RepoPresets();

        $this->assertSame(['netbird'], array_column($presets->for('netbird'), 'id'));
        $this->assertSame(['zerotier'], array_column($presets->for('zerotier'), 'id'));
        $this->assertSame([], $presets->for('lldpd'));
        $this->assertNull($presets->get('does-not-exist'));
        $this->assertArrayHasKey('netbird', $presets->all());
        $this->assertArrayHasKey('zerotier', $presets->all());
    }

    public function testSeedingAPresetRegistersItDisabledWithoutApplyingIt(): void
    {
        $registry = new RepoRegistry();

        $result = $registry->seedPreset('netbird', 'preset');

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        $this->assertTrue($result['created']);

        $row = $registry->get('netbird');
        $this->assertNotNull($row);
        $this->assertFalse($row['enabled'], 'a preset must not talk to the vendor until the operator enables it');
        $this->assertSame('preset', $row['added_by']);
        $this->assertSame('EFE37DF047DF7CCDF1FC54FA83F79AD029778355', $row['fingerprint']);
        $this->assertNotSame('', $row['key_armor']);
    }

    public function testSeedingTwiceLeavesTheStoredFingerprintAlone(): void
    {
        $registry = new RepoRegistry();
        $registry->seedPreset('netbird', 'preset');

        // An operator who pinned a different fingerprint (say, after the vendor
        // rotated a key) must not have it silently rewritten by a re-register.
        $this->db->table('plugin_repos')->where('id', 'netbird')
            ->update(['key_fingerprint' => '0F1E2D3C4B5A69788796A5B4C3D2E1F00A1B2C3D']);

        $again = $registry->seedPreset('netbird', 'preset');

        $this->assertTrue($again['ok']);
        $this->assertFalse($again['created'], 're-registering an existing repository is a no-op');
        $this->assertSame(
            '0F1E2D3C4B5A69788796A5B4C3D2E1F00A1B2C3D',
            (new RepoRegistry())->get('netbird')['fingerprint'],
        );
    }

    /**
     * `zenarmor` is the sentinel deliberately: §11 names it as a Class C
     * integration whose repository is a *credentialed* vendor service (the
     * endpoint answers 204 to an anonymous request), so it can never ship as a
     * preset — no fingerprint can be verified from public data. It is exactly
     * the kind of id the registry must refuse.
     */
    public function testAnUnknownPresetIsRefused(): void
    {
        $result = (new RepoRegistry())->seedPreset('zenarmor');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unknown preset', implode(' ', $result['errors']));
    }

    public function testPresetsReportTheirRegistrationState(): void
    {
        $registry = new RepoRegistry();

        $before = $registry->presets()[0];
        $this->assertFalse($before['registered']);

        $registry->seedPreset('netbird', 'preset');

        $after = (new RepoRegistry())->presets()[0];
        $this->assertTrue($after['registered']);
        $this->assertFalse($after['enabled']);
    }

    private function clearRepos(): void
    {
        if ($this->db->tableExists('plugin_repos')) {
            $this->db->table('plugin_repos')->emptyTable();
        }
    }
}

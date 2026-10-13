<?php

namespace Tests\App\Libraries;

use App\Libraries\SdwanCompiler;
use App\Libraries\SdwanEngine;
use App\Models\SdwanMemberModel;
use App\Models\SdwanProfileModel;
use App\Models\SdwanRuleModel;
use App\Models\SdwanSettingsModel;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

final class SdwanCompilerTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    public function testCompilerProducesValidNftablesAndIproute2Artifacts(): void
    {
        $compiler = new SdwanCompiler();
        $output = $compiler->compile();

        $this->assertTrue($output['enabled']);
        $this->assertStringContainsString('table ip micron_sdwan', $output['nftables']);
        $this->assertStringContainsString('chain prerouting_steering', $output['nftables']);
        $this->assertStringContainsString('meta mark set', $output['nftables']);
        $this->assertStringContainsString('ip route replace default via', $output['iproute2']);
        $this->assertGreaterThanOrEqual(1, $output['summary']['rules_count']);
    }

    public function testEngineEvaluatesSlaAndSteersTraffic(): void
    {
        $engine = new SdwanEngine();

        // 1. Quality score
        $scoreLow = $engine->computeQualityScore(0.0, 10.0, 1.0);
        $scoreHigh = $engine->computeQualityScore(5.0, 100.0, 20.0);
        $this->assertLessThan($scoreHigh, $scoreLow);

        // 2. SLA compliance
        $healthyMember = [
            'id'              => 'mem_wan1',
            'name'            => 'WAN1 Fiber',
            'current_loss'    => 0.1,
            'current_latency' => 20.0,
            'current_jitter'  => 2.0,
            'status'          => 'alive',
            'enabled'         => 1,
        ];
        $strictProfile = [
            'sla_max_loss_pct'    => 0.5,
            'sla_max_latency_ms'  => 50.0,
            'sla_max_jitter_ms'   => 5.0,
            'preferred_member_id' => 'mem_wan1',
        ];

        $this->assertTrue($engine->meetsSla($healthyMember, $strictProfile));

        // Degraded member violates SLA
        $degradedMember = array_merge($healthyMember, ['current_jitter' => 18.0]);
        $this->assertFalse($engine->meetsSla($degradedMember, $strictProfile));
    }

    public function testEngineFailsOverToBackupWhenPreferredViolatesSla(): void
    {
        $engine = new SdwanEngine();

        $preferredDegraded = [
            'id' => 'mem_wan1', 'name' => 'WAN1', 'enabled' => 1, 'status' => 'degraded',
            'current_loss' => 10.0, 'current_latency' => 200.0, 'current_jitter' => 30.0,
        ];
        $backup = [
            'id' => 'mem_wan2', 'name' => 'WAN2 Backup', 'enabled' => 1, 'status' => 'alive',
            'current_loss' => 0.0, 'current_latency' => 25.0, 'current_jitter' => 3.0,
        ];
        $profile = [
            'preferred_member_id' => 'mem_wan1',
            'backup_member_id'    => 'mem_wan2',
            'sla_max_loss_pct'    => 1.0,
            'sla_max_latency_ms'  => 100.0,
            'sla_max_jitter_ms'   => 10.0,
        ];

        $decision = $engine->resolveSteeringDecision($profile, [$preferredDegraded, $backup]);

        $this->assertNotNull($decision);
        $this->assertSame('mem_wan2', $decision['selected_member']['id'],
            'Engine must steer to backup when preferred member violates SLA thresholds.'
        );
        $this->assertFalse($decision['sla_compliant'],
            'When preferred is violated but backup is compliant, sla_compliant must reflect actual preferred state.'
        );
    }

    public function testCompilerDisabledReturnsDisabledArtifact(): void
    {
        // Directly test the disabled branch
        $settingsModel = $this->createMock(SdwanSettingsModel::class);
        $settingsModel->method('getSettings')->willReturn(['enabled' => 0]);

        $compiler = new SdwanCompiler(
            new SdwanMemberModel(),
            new SdwanProfileModel(),
            new SdwanRuleModel(),
            $settingsModel
        );

        $output = $compiler->compile();

        $this->assertFalse($output['enabled']);
        $this->assertStringContainsString('DISABLED', $output['nftables']);
        $this->assertSame(0, $output['summary']['rules_count']);
    }
}

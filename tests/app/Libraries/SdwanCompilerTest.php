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
}

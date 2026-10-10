<?php

use App\Libraries\NftablesCompiler;
use CodeIgniter\Config\Services;
use CodeIgniter\Security\Security;
use Tests\Support\BaseTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security as SecurityConfig;

/**
 * Feature & Integration tests for Schedule Objects across Firewall, NAT & QoS:
 *   - GET  /firewall/schedules
 *   - POST /firewall/schedules/store
 *   - POST /firewall/schedules/(:segment)/update
 *   - POST /firewall/schedules/(:segment)/delete
 *   - Cascade on delete resetting references to 'always'
 *   - Compiler verification for meta day / meta hour / meta time
 *
 * @internal
 */
final class ScheduleTest extends BaseTestCase
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
        'nat.view',
        'nat.edit',
        'qos.view',
        'qos.edit',
    ];

    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->csrfToken = bin2hex(random_bytes(16));
        $_COOKIE[config(SecurityConfig::class)->cookieName] = $this->csrfToken;
        Services::injectMock('security', new Security(config(SecurityConfig::class)));

        // firewall_rules.interface_id has an FK to interfaces; seed non-destructively
        // (deleting interfaces would CASCADE-delete unrelated rules).
        foreach ([['wan', 'WAN', 'eth0'], ['lan', 'LAN', 'eth1']] as [$id, $name, $dev]) {
            if ($this->db->table('interfaces')->where('id', $id)->countAllResults() === 0) {
                $this->db->table('interfaces')->insert([
                    'id'                 => $id,
                    'name'               => $name,
                    'physical_device'    => $dev,
                    'enabled'            => 1,
                    'ip_assignment_type' => 'static',
                ]);
            }
        }
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

    private function csrf(array $params): array
    {
        $this->withSession($this->session());

        return $params + ['csrf_test_name' => service('security')->getHash()];
    }

    private function insertFirewallRule(string $id, array $overrides = []): void
    {
        static $seq = 100;
        $seq++;
        $this->db->table('firewall_rules')->insert(array_merge([
            'id'            => $id,
            'sequence'      => $seq,
            'interface_id'  => 'lan',
            'action'        => 'pass',
            'direction'     => 'in',
            'protocol'      => 'tcp',
            'source_type'   => 'any',
            'source_value'  => 'any',
            'dest_type'     => 'any',
            'dest_value'    => 'any',
            'dest_port'     => 'any',
            'log_enabled'   => 0,
            'enabled'       => 1,
            'name'          => 'Firewall Policy',
            'src_interface' => 'lan',
            'dst_interface' => 'wan',
            'sources'       => json_encode(['all']),
            'destinations'  => json_encode(['all']),
            'services'      => json_encode(['ALL']),
            'schedule'      => 'always',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    public function testIndexRendersScheduleView(): void
    {
        $result = $this->withSession($this->session())->get('firewall/schedules');

        $result->assertOK();
        $result->assertSee('Firewall Schedules');
        $result->assertSee('Create Schedule Object');
        $result->assertSee('work_hours');
        $result->assertSee('weekend');
    }

    public function testStoreRecurringSchedule(): void
    {
        // Clean up if exists from previous run
        $this->db->table('firewall_schedules')->where('name', 'office_recurring')->delete();

        $result = $this->post('firewall/schedules/store', $this->csrf([
            'name'        => 'office_recurring',
            'type'        => 'recurring',
            'days'        => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'start_time'  => '09:00',
            'end_time'    => '17:00',
            'description' => 'Standard office operating hours',
        ]));

        $result->assertRedirect();

        $row = $this->db->table('firewall_schedules')->where('name', 'office_recurring')->get()->getRowArray();
        $this->assertNotNull($row, 'Recurring schedule was not created');
        $this->assertSame('recurring', $row['type']);
        $this->assertSame('09:00:00', $row['start_time']);
        $this->assertSame('17:00:59', $row['end_time']);
        $this->assertStringContainsString('mon', $row['days_of_week']);
        $this->assertStringContainsString('fri', $row['days_of_week']);
    }

    public function testStoreOneTimeSchedule(): void
    {
        $this->db->table('firewall_schedules')->where('name', 'maintenance_window')->delete();

        $result = $this->post('firewall/schedules/store', $this->csrf([
            'name'           => 'maintenance_window',
            'type'           => 'onetime',
            'start_datetime' => '2026-11-01 02:00:00',
            'end_datetime'   => '2026-11-01 06:00:00',
            'description'    => 'Overnight maintenance window',
        ]));

        $result->assertRedirect();

        $row = $this->db->table('firewall_schedules')->where('name', 'maintenance_window')->get()->getRowArray();
        $this->assertNotNull($row, 'One-time schedule was not created');
        $this->assertSame('onetime', $row['type']);
        $this->assertSame('2026-11-01 02:00:00', $row['start_datetime']);
        $this->assertSame('2026-11-01 06:00:00', $row['end_datetime']);
    }

    public function testUpdateSchedule(): void
    {
        $this->db->table('firewall_schedules')->where('name', 'updatable_sched')->delete();
        $this->db->table('firewall_schedules')->insert([
            'id'           => 'sched_updatable_test',
            'name'         => 'updatable_sched',
            'type'         => 'recurring',
            'days_of_week' => 'mon',
            'start_time'   => '10:00:00',
            'end_time'     => '12:00:00',
            'description'  => 'Old description',
            'is_system'    => 0,
        ]);

        $result = $this->post('firewall/schedules/sched_updatable_test/update', $this->csrf([
            'type'        => 'recurring',
            'days'        => ['mon', 'tue'],
            'start_time'  => '10:30',
            'end_time'    => '14:30',
            'description' => 'Updated description',
        ]));

        $result->assertRedirect();

        $row = $this->db->table('firewall_schedules')->where('id', 'sched_updatable_test')->get()->getRowArray();
        $this->assertSame('Updated description', $row['description']);
        $this->assertSame('10:30:00', $row['start_time']);
        $this->assertSame('14:30:59', $row['end_time']);
        $this->assertSame('mon,tue', $row['days_of_week']);
    }

    public function testCannotDeleteSystemSchedule(): void
    {
        $result = $this->post('firewall/schedules/sched_always/delete', $this->csrf([]));
        $result->assertRedirect();

        $row = $this->db->table('firewall_schedules')->where('name', 'always')->get()->getRowArray();
        $this->assertNotNull($row, 'System schedule always must not be deleted');
    }

    public function testToggleDisablesAndReEnablesSchedule(): void
    {
        $this->db->table('firewall_schedules')->where('id', 'sched_toggle_test')->delete();
        $this->db->table('firewall_schedules')->insert([
            'id'           => 'sched_toggle_test',
            'name'         => 'toggle_test',
            'type'         => 'recurring',
            'days_of_week' => 'mon',
            'start_time'   => '09:00:00',
            'end_time'     => '10:00:00',
            'is_system'    => 0,
            'enabled'      => 1,
        ]);

        $this->post('firewall/schedules/sched_toggle_test/toggle', $this->csrf([]))->assertRedirect();
        $row = $this->db->table('firewall_schedules')->where('id', 'sched_toggle_test')->get()->getRowArray();
        $this->assertSame(0, (int) $row['enabled']);

        $this->post('firewall/schedules/sched_toggle_test/toggle', $this->csrf([]))->assertRedirect();
        $row = $this->db->table('firewall_schedules')->where('id', 'sched_toggle_test')->get()->getRowArray();
        $this->assertSame(1, (int) $row['enabled']);

        $this->db->table('firewall_schedules')->where('id', 'sched_toggle_test')->delete();
    }

    public function testCannotDisableSystemSchedule(): void
    {
        $this->post('firewall/schedules/sched_always/toggle', $this->csrf([]))->assertRedirect();

        $row = $this->db->table('firewall_schedules')->where('id', 'sched_always')->get()->getRowArray();
        $this->assertSame(1, (int) ($row['enabled'] ?? 1), 'System schedule always must stay enabled');
    }

    public function testDisabledSchedulePausesFirewallNatAndQosRules(): void
    {
        $this->db->table('firewall_schedules')->where('id', 'sched_pause_test')->delete();
        $this->db->table('firewall_schedules')->insert([
            'id'           => 'sched_pause_test',
            'name'         => 'pause_test',
            'type'         => 'recurring',
            'days_of_week' => 'mon,tue,wed,thu,fri',
            'start_time'   => '08:00:00',
            'end_time'     => '18:00:00',
            'is_system'    => 0,
            'enabled'      => 0,
        ]);

        $fwId = 'fw_pause_' . bin2hex(random_bytes(4));
        $this->insertFirewallRule($fwId, [
            'name'        => 'Paused Policy Marker',
            'action'      => 'drop',
            'schedule'    => 'pause_test',
            'log_traffic' => 'none',
        ]);

        $dnatId = 'dnat_pause_' . bin2hex(random_bytes(4));
        $this->db->table('nat_dnat')->insert([
            'id'            => $dnatId,
            'name'          => 'Paused DNAT Marker',
            'interface_id'  => 'wan',
            'external_ip'   => '203.0.113.77',
            'external_port' => 2222,
            'mapped_ip'     => '192.168.1.77',
            'mapped_port'   => 22,
            'protocol'      => 'tcp',
            'schedule'      => 'pause_test',
            'enabled'       => 1,
        ]);

        $this->db->table('qos_rules')->insert([
            'shaper_id'       => 1,
            'name'            => 'Paused QoS Marker',
            'priority'        => 4,
            'protocol'        => 'tcp',
            'src_ip'          => 'any',
            'dst_ip'          => 'any',
            'dst_port'        => '2222',
            'dscp_class'      => 'AF41',
            'bandwidth_share' => 10,
            'schedule'        => 'pause_test',
            'enabled'         => 1,
        ]);

        // Disabled → all three are absent from the ruleset
        $ruleset = (new NftablesCompiler($this->db))->compile();
        $this->assertStringNotContainsString('Paused Policy Marker', $ruleset);
        $this->assertStringNotContainsString('Paused DNAT Marker', $ruleset);
        $this->assertStringNotContainsString('dnat to 192.168.1.77:22', $ruleset);
        $this->assertStringNotContainsString('Paused QoS Marker', $ruleset);

        // Re-enabled → all three come back, with their time window
        $this->db->table('firewall_schedules')->where('id', 'sched_pause_test')->update(['enabled' => 1]);
        $ruleset = (new NftablesCompiler($this->db))->compile();
        $this->assertStringContainsString('Paused Policy Marker', $ruleset);
        $this->assertStringContainsString('dnat to 192.168.1.77:22', $ruleset);
        $this->assertStringContainsString('Paused QoS Marker', $ruleset);
        $this->assertStringContainsString('meta hour "08:00:00" - "18:00:00"', $ruleset);

        $this->db->table('firewall_rules')->where('id', $fwId)->delete();
        $this->db->table('nat_dnat')->where('id', $dnatId)->delete();
        $this->db->table('qos_rules')->where('name', 'Paused QoS Marker')->delete();
        $this->db->table('firewall_schedules')->where('id', 'sched_pause_test')->delete();
    }

    public function testDeleteCustomScheduleCascadesToAlways(): void
    {
        // 1. Create a custom schedule
        $this->db->table('firewall_schedules')->where('name', 'sched_temp_cascade')->delete();
        $this->db->table('firewall_schedules')->insert([
            'id'           => 'sched_temp_cascade_id',
            'name'         => 'sched_temp_cascade',
            'type'         => 'recurring',
            'days_of_week' => 'sat,sun',
            'start_time'   => '00:00:00',
            'end_time'     => '23:59:59',
            'description'  => 'Temporary schedule for cascade test',
            'is_system'    => 0,
        ]);

        // 2. Insert firewall rule referencing this schedule
        $fwRuleId = 'fw_test_cascade_' . bin2hex(random_bytes(4));
        $this->insertFirewallRule($fwRuleId, [
            'name'     => 'Test FW Cascade',
            'schedule' => 'sched_temp_cascade',
        ]);

        // 3. Insert DNAT rule referencing this schedule
        $dnatId = 'dnat_test_cascade_' . bin2hex(random_bytes(4));
        $this->db->table('nat_dnat')->insert([
            'id'            => $dnatId,
            'name'          => 'Test DNAT Cascade',
            'interface_id'  => 'wan',
            'external_ip'   => '203.0.113.10',
            'external_port' => 8888,
            'mapped_ip'     => '192.168.1.100',
            'mapped_port'   => 8888,
            'protocol'      => 'tcp',
            'schedule'      => 'sched_temp_cascade',
            'enabled'       => 1,
        ]);

        // 4. Insert QoS rule referencing this schedule
        $this->db->table('qos_rules')->insert([
            'shaper_id'       => 1,
            'name'            => 'Test QoS Cascade',
            'priority'        => 3,
            'protocol'        => 'tcp',
            'src_ip'          => 'any',
            'dst_ip'          => 'any',
            'dst_port'        => '8888',
            'dscp_class'      => 'AF41',
            'bandwidth_share' => 10,
            'schedule'        => 'sched_temp_cascade',
            'enabled'         => 1,
        ]);

        // 5. Delete the custom schedule
        $result = $this->post('firewall/schedules/sched_temp_cascade_id/delete', $this->csrf([]));
        $result->assertRedirect();

        // 6. Verify schedule row was removed
        $schedRow = $this->db->table('firewall_schedules')->where('id', 'sched_temp_cascade_id')->get()->getRowArray();
        $this->assertNull($schedRow, 'Schedule row should be deleted');

        // 7. Verify all 3 subsystems were cascaded back to 'always'
        $fwRow = $this->db->table('firewall_rules')->where('id', $fwRuleId)->get()->getRowArray();
        $this->assertSame('always', $fwRow['schedule'], 'Firewall rule schedule should reset to always');

        $dnatRow = $this->db->table('nat_dnat')->where('id', $dnatId)->get()->getRowArray();
        $this->assertSame('always', $dnatRow['schedule'], 'DNAT rule schedule should reset to always');

        $qosRow = $this->db->table('qos_rules')->where('name', 'Test QoS Cascade')->get()->getRowArray();
        $this->assertSame('always', $qosRow['schedule'], 'QoS rule schedule should reset to always');

        // Clean up
        $this->db->table('firewall_rules')->where('id', $fwRuleId)->delete();
        $this->db->table('nat_dnat')->where('id', $dnatId)->delete();
        $this->db->table('qos_rules')->where('name', 'Test QoS Cascade')->delete();
    }

    public function testCompilerAppendsScheduleClauses(): void
    {
        $compiler = new NftablesCompiler($this->db);

        // Ensure seeded schedules exist in db
        $schedules = $compiler->fetchSchedules();
        $this->assertArrayHasKey('always', $schedules);
        $this->assertArrayHasKey('work_hours', $schedules);
        $this->assertArrayHasKey('weekend', $schedules);

        // 1. 'always' emits empty string (unconditional 24/7)
        $alwaysClause = $compiler->compileScheduleClause('always', $schedules);
        $this->assertSame('', $alwaysClause);

        // 2. 'work_hours' emits meta day and meta hour
        $workClause = $compiler->compileScheduleClause('work_hours', $schedules);
        $this->assertStringContainsString('meta day "Monday" - "Friday"', $workClause);
        $this->assertStringContainsString('meta hour "08:00:00" - "18:00:00"', $workClause);

        // 3. 'weekend' emits meta day set
        $weekendClause = $compiler->compileScheduleClause('weekend', $schedules);
        $this->assertStringContainsString('meta day { "Saturday", "Sunday" }', $weekendClause);

        // 4. One-time schedule emits meta time range
        $oneTimeClause = $compiler->compileScheduleClause('dummy_onetime', [
            'dummy_onetime' => [
                'type'           => 'onetime',
                'start_datetime' => '2026-10-05 09:00:00',
                'end_datetime'   => '2026-10-05 17:00:00',
            ],
        ]);
        $this->assertStringContainsString('meta time "2026-10-05 09:00:00" - "2026-10-05 17:00:00"', $oneTimeClause);
    }

    public function testCompilerIntegrationAcrossFirewallNatAndQos(): void
    {
        // 1. Interfaces (wan/lan) are seeded in setUp()

        // 2. Insert firewall rule with schedule = 'work_hours'
        $fwRuleId = 'fw_sched_test_' . bin2hex(random_bytes(4));
        $this->insertFirewallRule($fwRuleId, [
            'name'        => 'Social Media Block',
            'action'      => 'drop',
            'schedule'    => 'work_hours',
            'log_traffic' => 'none',
        ]);

        // 3. Insert DNAT rule with schedule = 'work_hours'
        $dnatId = 'dnat_sched_test_' . bin2hex(random_bytes(4));
        $this->db->table('nat_dnat')->insert([
            'id'            => $dnatId,
            'name'          => 'Work Hours RDP Forward',
            'interface_id'  => 'wan',
            'external_ip'   => '203.0.113.10',
            'external_port' => 3389,
            'mapped_ip'     => '192.168.1.50',
            'mapped_port'   => 3389,
            'protocol'      => 'tcp',
            'schedule'      => 'work_hours',
            'enabled'       => 1,
        ]);

        // 4. Insert QoS rule with schedule = 'weekend'
        $this->db->table('qos_rules')->where('name', 'Weekend Gaming Priority')->delete();
        $this->db->table('qos_rules')->insert([
            'shaper_id'       => 1,
            'name'            => 'Weekend Gaming Priority',
            'priority'        => 2,
            'protocol'        => 'udp',
            'src_ip'          => 'any',
            'dst_ip'          => 'any',
            'dst_port'        => '51820',
            'dscp_class'      => 'CS6',
            'bandwidth_share' => 20,
            'schedule'        => 'weekend',
            'enabled'         => 1,
        ]);

        // 5. Compile full nftables ruleset
        $compiler = new NftablesCompiler($this->db);
        $ruleset  = $compiler->compile();

        // 6. Verify schedule clauses in Firewall rules
        $this->assertStringContainsString('meta day "Monday" - "Friday"', $ruleset);
        $this->assertStringContainsString('meta hour "08:00:00" - "18:00:00"', $ruleset);
        $this->assertStringContainsString('drop comment "Social Media Block"', $ruleset);

        // 7. Verify schedule clauses in NAT rules
        $this->assertStringContainsString('dnat to 192.168.1.50:3389', $ruleset);
        $this->assertStringContainsString('comment "DNAT: Work Hours RDP Forward"', $ruleset);

        // 8. Verify QoS Mangle table and classification chain
        $this->assertStringContainsString('table inet micron_mangle', $ruleset);
        $this->assertStringContainsString('chain qos_classification', $ruleset);
        $this->assertStringContainsString('ip protocol udp', $ruleset);
        $this->assertStringContainsString('meta day { "Saturday", "Sunday" }', $ruleset);
        $this->assertStringContainsString('ip dscp set cs6', $ruleset);

        // 9. Verify single QoS rule compiler helper
        $schedules = $compiler->fetchSchedules();
        $qosRule = [
            'name'            => 'Test Helper',
            'protocol'        => 'tcp',
            'src_ip'          => '10.0.0.1',
            'dst_ip'          => 'any',
            'dst_port'        => '80,443',
            'dscp_class'      => 'EF',
            'schedule'        => 'work_hours',
        ];
        $singleCompiled = $compiler->compileQosRule($qosRule, $schedules);
        $this->assertStringContainsString('ip protocol tcp', $singleCompiled);
        $this->assertStringContainsString('ip saddr 10.0.0.1', $singleCompiled);
        $this->assertStringContainsString('th dport { 80, 443 }', $singleCompiled);
        $this->assertStringContainsString('meta day "Monday" - "Friday"', $singleCompiled);
        $this->assertStringContainsString('ip dscp set ef', $singleCompiled);

        // Cleanup
        $this->db->table('firewall_rules')->where('id', $fwRuleId)->delete();
        $this->db->table('nat_dnat')->where('id', $dnatId)->delete();
        $this->db->table('qos_rules')->where('name', 'Weekend Gaming Priority')->delete();
    }
}

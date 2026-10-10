<?php

namespace Tests\App\Commands;

use App\Libraries\PppoeBackoff;
use App\Libraries\PppoeCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * Tests the CLI redial command (micron:pppoe-redial) behavior with
 * escalating backoff, link skipping, --force, and --reset-backoff.
 *
 * @internal
 */
final class PppoeRedialCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    private PppoeBackoff $backoff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backoff = new PppoeBackoff($this->db);

        if ($this->db->tableExists(PppoeBackoff::TABLE)) {
            $this->db->table(PppoeBackoff::TABLE)->emptyTable();
        }
        $this->db->table('interfaces')->where('id', 'pppoe_test_wan')->delete();
    }

    protected function tearDown(): void
    {
        $this->db->table('interfaces')->where('id', 'pppoe_test_wan')->delete();
        parent::tearDown();
    }

    private function insertTestPppoeInterface(string $device = 'eth2'): void
    {
        $now = date('Y-m-d H:i:s');
        $compiler = new PppoeCompiler($this->db);
        $this->db->table('interfaces')->insert([
            'id'                 => 'pppoe_test_wan',
            'name'               => 'WAN_TEST',
            'physical_device'    => $device,
            'enabled'            => 1,
            'ip_assignment_type' => 'pppoe',
            'pppoe_username'     => 'testuser@isp.net',
            'pppoe_password'     => $compiler->encrypt('testpass'),
            'pppoe_service'      => null,
            'pppoe_ac_name'      => null,
            'pppoe_mtu'          => 1492,
            'pppoe_mru'          => 1492,
            'pppoe_idle_timeout' => 0,
            'pppoe_default_route'=> 1,
            'mtu'                => 1500,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function testCommandSkipsBackedOffLinkWhenNotForced(): void
    {
        $this->insertTestPppoeInterface('eth2');

        // Put eth2 in backoff with 2 failures
        $this->backoff->recordFailure('eth2', 'PPPoE discovery failed', time());
        $this->backoff->recordFailure('eth2', 'PPPoE discovery failed', time());
        $this->assertTrue($this->backoff->isBackedOff('eth2'));

        $this->resetStreamFilterBuffer();
        command('micron:pppoe-redial --interface=eth2 --ipc=1');
        $output = $this->getStreamFilterBuffer();

        // eth2 must be identified as backed off and skipped
        $this->assertStringContainsString('in escalating backoff', $output);
        $this->assertStringContainsString('1 backed off', $output);
    }

    public function testCommandBypassesBackoffWithForce(): void
    {
        $this->insertTestPppoeInterface('eth2');

        $this->backoff->recordFailure('eth2', 'Link timeout', time());
        $this->assertTrue($this->backoff->isBackedOff('eth2'));

        $this->resetStreamFilterBuffer();
        command('micron:pppoe-redial --interface=eth2 --force --ipc=1');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringNotContainsString('in escalating backoff', $output);
        $this->assertStringContainsString('would redial', $output);
    }

    public function testCommandResetsBackoffWithFlag(): void
    {
        $this->insertTestPppoeInterface('eth2');

        $this->backoff->recordFailure('eth2', 'Auth error', time());
        $this->assertTrue($this->backoff->isBackedOff('eth2'));

        $this->resetStreamFilterBuffer();
        command('micron:pppoe-redial --interface=eth2 --reset-backoff --ipc=1');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('backoff reset', $output);
        $this->assertFalse($this->backoff->isBackedOff('eth2'));
    }
}

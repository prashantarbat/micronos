<?php

namespace Tests\App\Libraries;

use App\Libraries\DpiCompiler;
use App\Models\DpiPolicyModel;
use App\Models\DpiSettingsModel;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\BaseTestCase;

final class DpiCompilerTest extends BaseTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;

    protected DpiCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new DpiCompiler();
    }

    public function testCompileNdpiConfigGeneratesValidRules(): void
    {
        $config = $this->compiler->compileNdpiConfig();

        $this->assertStringContainsString('engine.enabled = true', $config);
        $this->assertStringContainsString('engine.mode = inline_block', $config);
        $this->assertStringContainsString('proto 91  mark 0x1001   # YouTube', $config);
        $this->assertStringContainsString('proto 218 mark 0x1061   # Stratum (Mining)', $config);
    }

    public function testCompileNftablesSnippetCreatesFilterRules(): void
    {
        $ruleset = $this->compiler->compileNftablesSnippet();

        $this->assertStringContainsString('table inet micron_filter', $ruleset);
        $this->assertStringContainsString('chain dpi_forward', $ruleset);
        $this->assertStringContainsString('ct mark 0x1061', $ruleset);
        $this->assertStringContainsString('drop', $ruleset);
    }

    public function testCompileNftablesSnippetRespectsDisabledEngine(): void
    {
        $settingsModel = new DpiSettingsModel();
        $settingsModel->update(1, ['enabled' => 0]);

        $ruleset = $this->compiler->compileNftablesSnippet();
        $this->assertStringContainsString('globally disabled', $ruleset);

        // Restore
        $settingsModel->update(1, ['enabled' => 1]);
    }
}

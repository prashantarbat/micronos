<?php

namespace Tests\Packaging;

use App\Libraries\Eula;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The licence agreement ships with the appliance, the installer stops on it,
 * and the version the web interface asks for is the one in the text.
 */
final class EulaShippedTest extends CIUnitTestCase
{
    public function testTheTextCarriesTheVersionTheWebInterfaceAsksFor(): void
    {
        $text = (string) file_get_contents(ROOTPATH . 'EULA.txt');

        $this->assertStringContainsString("\nVersion " . Eula::VERSION . "\n", $text);
        $this->assertStringContainsString('Apache', $text);
        $this->assertStringNotContainsString('pfSense', $text);
        $this->assertStringNotContainsString('Netgate', $text);
    }

    public function testTheInstallerAsksBeforeAnythingElse(): void
    {
        $install = (string) file_get_contents(ROOTPATH . 'installer/install.sh');

        $eula = strpos($install, 'EULA_FILE="${SCRIPT_DIR}/../EULA.txt"');
        $this->assertNotFalse($eula, 'the installer reads EULA.txt');
        $this->assertLessThan(strpos($install, 'Step 1: Global Keyboard Layout'), $eula, 'the agreement comes before step 1');
        $this->assertLessThan(strpos($install, 'sgdisk'), $eula, 'the agreement comes before any disk change');
        $this->assertStringContainsString('--yes-button "Accept" --no-button "Decline"', $install);
        $this->assertMatchesRegularExpression('/ACCEPT_EULA:-\}" =~ \^\(\[yY\]\[eE\]\[sS\]/', $install, 'kickstart must accept it explicitly');
        $this->assertStringContainsString('/etc/micron/eula-accepted', $install);
    }

    public function testTheKickstartExampleDoesNotAcceptOnTheReadersBehalf(): void
    {
        $example = (string) file_get_contents(ROOTPATH . 'installer/micron-kickstart.cfg.example');

        $this->assertStringContainsString('ACCEPT_EULA="no"', $example);
    }
}

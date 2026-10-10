<?php

namespace App\Commands;

use App\Libraries\NftablesCompiler;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FirewallCompile extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'firewall:compile';
    protected $description = 'Compiles database firewall & NAT rules into nftables configuration syntax.';

    public function run(array $params)
    {
        $compiler = new NftablesCompiler();
        $ruleset = $compiler->compile();

        CLI::write('=== Compiled nftables Ruleset ===', 'green');
        CLI::write($ruleset);
        CLI::write('=== End of Ruleset ===', 'green');
    }
}

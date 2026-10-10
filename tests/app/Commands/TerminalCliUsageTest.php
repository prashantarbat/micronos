<?php

declare(strict_types=1);

namespace Tests\App\Commands;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The Terminal CLI's contract at the command line: what it accepts, and what it
 * does when it does not understand you.
 *
 * This is a contract rather than a detail because `microctl backup verify
 * --strict` is meant to gate a cron job on its exit status. A wrapper that
 * printed usage for a mistyped subcommand and still exited 0 would turn that
 * gate off silently — the job would look healthy forever while checking
 * nothing. So every usage error must fail, and stderr is where it must say so.
 *
 * Only invocations that never reach a command are exercised here. The commands
 * that delegate (`backup create`, `backup verify`, …) run `php spark` in a new
 * process, which would resolve the appliance's real database group instead of
 * the suite's in-memory one — a test must not do that to a developer's
 * appliance data.
 *
 * @internal
 */
final class TerminalCliUsageTest extends CIUnitTestCase
{
    /**
     * A subcommand the CLI does not know, in each namespace that has one.
     *
     * @return list<array{0: list<string>, 1: string}>
     */
    public static function mistypedCommands(): array
    {
        return [
            'unknown backup subcommand' => [['backup', 'nonsense'], 'Usage: microctl backup <create|restore|verify>'],
            'backup restore without a path' => [['backup', 'restore'], 'Usage: microctl backup restore <path>'],
            'unknown interface subcommand' => [['interface', 'bogus'], 'Usage: microctl interface set-lan'],
            'unknown firewall subcommand' => [['firewall', 'bogus'], 'Usage: microctl firewall reset'],
            'unknown config subcommand' => [['config', 'bogus'], 'Usage: microctl config rollback'],
            'unknown admin subcommand' => [['admin', 'bogus'], 'Usage: microctl admin reset-password'],
        ];
    }

    /**
     * @param list<string> $arguments
     *
     * @dataProvider mistypedCommands
     */
    public function testAMistypedCommandFailsInsteadOfLookingSuccessful(array $arguments, string $expectedUsage): void
    {
        $result = $this->runCli($arguments);

        $this->assertNotSame(
            0,
            $result['status'],
            'A usage error must exit non-zero, or a mistyped gate passes silently: ' . $result['stderr']
        );
        $this->assertStringContainsString($expectedUsage, $result['stderr'], 'the reason belongs on stderr, not in a success stream');
        $this->assertSame('', trim($result['stdout']), 'a usage error must not write to stdout');
    }

    public function testHelpSucceedsAndDocumentsEveryCommandItAccepts(): void
    {
        $result = $this->runCli(['help']);

        $this->assertSame(0, $result['status'], 'help is the one invocation that must always succeed');

        foreach (['backup create', 'backup restore', 'backup verify', 'config rollback', 'interface set-lan', 'firewall reset', 'admin reset-password'] as $documented) {
            $this->assertStringContainsString($documented, $result['stdout'], 'the help text must document ' . $documented);
        }
    }

    /**
     * `help` is also what the CLI prints when handed nothing at all, and that is
     * a success — an operator exploring the console is not a usage error.
     */
    public function testAnArgumentlessInvocationShowsHelpAndSucceeds(): void
    {
        $result = $this->runCli([]);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('MicronOS Console Management Utility', $result['stdout']);
    }

    // ------------------------------------------------------------------

    /**
     * @param list<string> $arguments
     *
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, rtrim((string) ROOTPATH, "/\\") . '/bin/microctl'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            $this->fail('Could not launch bin/microctl');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

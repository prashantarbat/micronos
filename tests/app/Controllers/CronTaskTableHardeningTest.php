<?php

namespace Tests\App\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\BaseTestCase;
use Tests\Support\Libraries\FakeIpcClient;

/**
 * The cron page's scheduled-tasks table is server-rendered, and its two row
 * actions used to carry the row's own values inside an `onclick`:
 *
 *     onclick='viewCronOutput(<?= json_encode($job['description']) ?>, …)'
 *     onclick='openEditCronModal(<?= json_encode($job) ?>)'
 *
 * `json_encode` escapes `"` as `\"` but leaves `'` alone, and the attribute was
 * single-quoted — so one apostrophe in a description, a command or a run's
 * output ended the attribute, and everything after it was parsed as markup. A
 * task whose description read `Ada's dump` was enough: the rest of the value
 * became stray attributes on the button, and anything an operator (or the
 * daemon, through a run's output) put there could inject a handler.
 *
 * The values now travel in a JSON island the page ships, and the buttons carry
 * a row id and an action and nothing else. These tests render the real page with
 * deliberately hostile rows and hold it to that: no attribute of the row's
 * values, no unescaped quote anywhere in the markup, an island that survives
 * `</script>` — and an island that parses back to exactly what was stored, which
 * is what makes the value the page opens the modal with the row's own.
 *
 * **The response body is read as `$result->response()->getBody()`.** A
 * `TestResponse` has no `getBody()` of its own, so CI4's `__call()` forwards the
 * call to its `DOMParser`, whose `getBody()` re-serialises the *parsed* document
 * — an HTML-shape-identical body in which `&#039;` has become a raw `'`. An
 * escaping assertion written against that string is vacuous: it cannot see the
 * difference between a value the page escaped and one it did not.
 *
 * @internal
 */
final class CronTaskTableHardeningTest extends BaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** A task whose own values are shaped to break out of markup and of a script. */
    private const ID = 'cron_snark';

    private const DESCRIPTION = "Nightly dump of Ada's config </script><img src=x onerror=alert(1)>";

    private const COMMAND = "php /usr/local/bin/cron-snark --name='a b' </script>";

    private const OUTPUT = "line one\nit's fine </script>";

    protected function setUp(): void
    {
        parent::setUp();

        // No daemon: the page must hold to its contract with the fallback path
        // live, which is how it runs on a dev box.
        Services::injectMock('ipc', new FakeIpcClient());

        $now = date('Y-m-d H:i:s');

        $this->db->table('cron_jobs')->where('id', self::ID)->delete();
        $this->db->table('cron_jobs')->insert([
            'id'           => self::ID,
            'minute'       => '*/15',
            'hour'         => '*',
            'day_of_month' => '*',
            'month'        => '*',
            'day_of_week'  => '*',
            'user'         => 'root',
            'command'      => self::COMMAND,
            'description'  => self::DESCRIPTION,
            'enabled'      => 1,
            'last_run_at'  => $now,
            'last_status'  => 'success',
            'last_output'  => self::OUTPUT,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->table('cron_jobs')->where('id', self::ID)->delete();

        parent::tearDown();
    }

    /**
     * The row offers its two actions as a row id and an action name. Nothing of
     * the task itself reaches an attribute, and — the tell that separates this
     * from escaping that happens to be right on the fields someone remembered —
     * no unescaped quote or angle bracket of it appears anywhere in the markup.
     */
    public function testTheTaskRowCarriesAnIdAndAnActionInsteadOfItsOwnValues(): void
    {
        $body = $this->cronPage();

        // Nothing the row carries may reach a handler, in any quoting — read off
        // the raw markup, where the escaping is still visible. This is the
        // assertion that fails first, because it is the one that describes the
        // hole: with the values back in the attribute, the apostrophe ends it and
        // the rest of the description lands in the handler.
        foreach ($this->handlers($body) as $handler) {
            foreach (['Ada', 'cron-snark', 'line one', self::ID] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $handler,
                    "an onclick attribute carries the row's data: {$handler}"
                );
            }
        }

        // The values are drawn as text, so their escaping is the HTML escaper's
        // rather than a step someone has to remember: the apostrophe of a
        // description reaches the markup as an entity, never as the character
        // that could end an attribute or a script element.
        $this->assertStringContainsString('Ada&#039;s', $body);
        $this->assertStringContainsString('&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;', $body);

        foreach (["Ada's", "it's", '</script><img'] as $raw) {
            $this->assertStringNotContainsString(
                $raw,
                $body,
                'an unescaped row value reached the markup: ' . $raw
            );
        }

        $buttons = [];

        // This row's own buttons: the table also holds whatever the seeds left
        // behind, and every row is held to the same contract.
        foreach ($this->dom($body)->getElementsByTagName('button') as $button) {
            if ($button->getAttribute('data-cron-id') === self::ID) {
                $buttons[] = $button;
            }
        }

        $this->assertCount(2, $buttons, 'the row must offer its Log and Edit actions');
        $this->assertSame(
            ['output', 'edit'],
            array_map(static fn ($button): string => $button->getAttribute('data-cron-action'), $buttons)
        );

        foreach ($buttons as $button) {
            $this->assertFalse(
                $button->hasAttribute('onclick'),
                'a row action must not be an inline handler carrying the row'
            );
        }
    }

    /**
     * The island is the one place the page writes a value off the wire with
     * something other than the DOM's own escaping, so it has to be inert in a
     * script context — and it has to parse back to exactly what was stored, or
     * the modal would be handed a different string than the row was.
     */
    public function testTheTaskIslandIsInertAndParsesBackToTheRowsOwnValues(): void
    {
        $island = $this->island($this->cronPage());

        // `</script>`, `<img`, a quote: none of them can appear as themselves,
        // because every one of them was hex-escaped rather than left as text.
        foreach (['<', '>', "'", '&'] as $character) {
            $this->assertStringNotContainsString(
                $character,
                $island,
                "the island must escape {$character} so it cannot end the element or a string"
            );
        }

        $jobs = json_decode($island, true);

        $this->assertIsArray($jobs, 'the island must be JSON the page can parse');

        $row = null;

        foreach ($jobs as $job) {
            if (($job['id'] ?? null) === self::ID) {
                $row = $job;
            }
        }

        $this->assertIsArray($row, 'the island must carry the row it was rendered beside');
        $this->assertSame(self::DESCRIPTION, $row['description']);
        $this->assertSame(self::COMMAND, $row['command']);
        $this->assertSame(self::OUTPUT, $row['last_output']);

        // `openEditCronModal` reads `enabled` through `parseInt`, so the island
        // hands it a number rather than a `true` that would edit every task as
        // though it were disabled.
        $this->assertSame(1, $row['enabled']);

        // The fields the edit modal fills its form with are all there.
        foreach (['minute', 'hour', 'day_of_month', 'month', 'day_of_week', 'user'] as $field) {
            $this->assertArrayHasKey($field, $row, "the island must carry {$field} for the edit modal");
        }
    }

    /**
     * The markup and the script are two halves of one contract: the island and
     * the attributes the page emits are the ones the page's script reads. PHP
     * can see both, so it is checked here rather than hoped for.
     */
    public function testThePageScriptReadsTheIslandAndTheActionAttributesItEmits(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'app/Views/cron/index.php');

        foreach ([
            'id="cronJobsData"',
            'type="application/json"',
            "document.getElementById('cronJobsData')",
            'JSON.parse(',
            'data-cron-action=',
            'data-cron-id=',
            "document.querySelectorAll('[data-cron-action]')",
        ] as $bridge) {
            $this->assertStringContainsString($bridge, $view, "the task table's script and markup must agree on: {$bridge}");
        }

        // And the tripwire that would have caught the original: no JSON is
        // encoded into an attribute again, on any line, in either quoting.
        foreach (preg_split('/\R/', $view) ?: [] as $offset => $line) {
            if (! str_contains($line, 'onclick')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'json_encode',
                $line,
                sprintf('cron/index.php encodes JSON into an onclick attribute on line %d', $offset + 1)
            );
        }
    }

    /** The rendered page, as the appliance would serve it. */
    private function cronPage(): string
    {
        $result = $this->withSession($this->session())->get('cron');

        $result->assertStatus(200);

        return (string) $result->response()->getBody();
    }

    /** The rendered island's own text, exactly as a browser would hand it to `JSON.parse`. */
    private function island(string $body): string
    {
        $island = $this->dom($body)->getElementById('cronJobsData');
        $this->assertNotNull($island, 'the task table must ship its JSON island');

        return (string) $island->textContent;
    }

    /**
     * Every raw `onclick` value in the markup, as written — before any parser
     * has had a chance to normalise or decode it.
     *
     * @return list<string>
     */
    private function handlers(string $body): array
    {
        preg_match_all('/onclick\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $body, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): string => ($match[2] ?? '') . ($match[3] ?? ''), $matches);
    }

    private function dom(string $body): \DOMDocument
    {
        $document = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function session(): array
    {
        return [
            'user_id'          => 'usr_superadmin',
            'username'         => 'Ministrator',
            'is_superadmin'    => 1,
            'permissions'      => ['dashboard.view'],
            'user_permissions' => ['dashboard.view'],
        ];
    }
}

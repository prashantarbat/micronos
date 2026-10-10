<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The appliance's shared request scheduler — the wiring.
 *
 * Every live screen used to own its polling: one `setInterval`, one `fetch`, one
 * quiet `console.error` per page. An appliance that went away for a minute made
 * each of them fail on its own clock, with its own (or no) message, and none of
 * them slow down while it was gone. `public/assets/js/micronos-scheduler.js` is
 * now the one thing that knows whether the appliance is reachable: a page hands
 * its polling to `MicronScheduler.poll` and its AJAX to `MicronScheduler.fetch`,
 * the first failure raises the layout's single banner and backs every poll off,
 * and the first request that gets through again clears it and wakes them all.
 *
 * The behaviour lives in the module and is driven, in Node, by
 * `tests/js/scheduler_harness.mjs` (see `SchedulerDomHarnessTest`). What *this*
 * test pins is the part a harness cannot see: that the module ships and stays
 * exposed, that the layout loads it and renders the one banner, and that each
 * page's poller actually hands its work over — a page that quietly kept its own
 * `setInterval` would look migrated and behave exactly as it did before.
 *
 * @internal
 */
final class RequestSchedulerTest extends CIUnitTestCase
{
    /** The module and the places that consume it. */
    private const MODULE = 'public/assets/js/micronos-scheduler.js';
    private const LAYOUT = 'app/Views/layouts/main.php';
    private const CSS    = 'public/css/micronos.css';
    private const PICKER = 'app/Views/partials/picker_search.php';

    /**
     * Every recurring network poller on the appliance, and the name it registers
     * under. A missing entry is a page that stopped sharing the one state.
     */
    private const POLLERS = [
        'app/Views/dashboard/index.php'            => "MicronScheduler.poll('dashboard.traffic'",
        'app/Views/diag/logs.php'                  => "MicronScheduler.poll('diag.logs'",
        'app/Views/diag/states.php'                => "MicronScheduler.poll('diag.states'",
        'app/Views/partials/service_controls.php'  => "MicronScheduler.poll('service.' + index",
        'app/Views/siem/index.php'                 => "MicronScheduler.poll('siem.stream'",
        'app/Views/snmp/index.php'                 => "MicronScheduler.poll('snmp.logs'",
        'app/Views/webfilter/index.php'            => "MicronScheduler.poll('webfilter.live'",
    ];

    private function source(string $relative): string
    {
        $path = ROOTPATH . $relative;

        $this->assertFileExists($path, "{$relative} is missing.");

        return (string) file_get_contents($path);
    }

    // ------------------------------------------------------------------
    // The module
    // ------------------------------------------------------------------

    public function testTheSchedulerModuleShipsAndExposesItsApi(): void
    {
        $js = $this->source(self::MODULE);

        // A classic script (not a module), bound to `window` and hung off it as
        // `MicronScheduler` so every page script can reach it.
        $this->assertStringContainsString("}(typeof window !== 'undefined' ? window : this));", $js);
        $this->assertStringContainsString('global.MicronScheduler = MicronScheduler;', $js);

        // The four entry points every page and the picker use.
        foreach (['poll: function (name, opts)', 'fetch: function (url, options)', 'report: function (ok, err)', 'backoffDelay: backoffDelay'] as $api) {
            $this->assertStringContainsString($api, $js, "The scheduler must expose `{$api}`.");
        }

        // The schedule itself: doubling to a ceiling, with a bounded jitter.
        $this->assertStringContainsString('var MAX_DELAY  = 30000;', $js);
        $this->assertStringContainsString('var JITTER     = 0.15;', $js);
        $this->assertStringContainsString('Math.pow(2, n - 1)', $js);

        // One banner id, and the message the layout also carries.
        $this->assertStringContainsString("getElementById('mnoConnBanner')", $js);
        $this->assertStringContainsString('Connection lost', $js);
    }

    // ------------------------------------------------------------------
    // The layout: one script, one banner
    // ------------------------------------------------------------------

    public function testTheLayoutLoadsTheSchedulerAndRendersTheOneBanner(): void
    {
        $layout = $this->source(self::LAYOUT);

        // Loaded synchronously in the head, so a poller started at parse time
        // (the service controls) finds `MicronScheduler` already defined.
        $this->assertStringContainsString("base_url('assets/js/micronos-scheduler.js')", $layout);
        $this->assertLessThan(
            strpos($layout, '</head>'),
            strpos($layout, 'micronos-scheduler.js'),
            'The scheduler must be loaded before any page script can start a poller.'
        );

        // The one banner, present in the markup and hidden until a failure.
        $this->assertStringContainsString('id="mnoConnBanner"', $layout);
        $this->assertStringContainsString('class="mno-conn-banner"', $layout);
        // The `hidden` attribute itself, not the `aria-hidden="true"` beside it.
        $this->assertMatchesRegularExpression('/id="mnoConnBanner"[^>]*\shidden>/', $layout);
        $this->assertSame(
            1,
            substr_count($layout, 'id="mnoConnBanner"'),
            'There must be exactly one connection banner in the layout.'
        );

        // The layout's own AJAX shares the state rather than reaching for fetch.
        $this->assertStringContainsString("MicronScheduler.fetch('<?= base_url('system/commit/status')", $layout);
    }

    public function testTheBannerIsStyledWithItsOwnHiddenRule(): void
    {
        $css = $this->source(self::CSS);

        $this->assertStringContainsString('.mno-conn-banner {', $css);
        // A `hidden` element must actually be hidden — the banner is `display:flex`
        // when shown, which would otherwise win over the attribute.
        $this->assertStringContainsString('.mno-conn-banner[hidden]', $css);
        $this->assertStringContainsString('.mno-conn-dot', $css);
    }

    // ------------------------------------------------------------------
    // Every poller hands its work over
    // ------------------------------------------------------------------

    public function testEveryPollerRegistersWithTheSharedScheduler(): void
    {
        foreach (self::POLLERS as $view => $registration) {
            $src = $this->source($view);

            $this->assertStringContainsString(
                $registration,
                $src,
                "{$view} must register its poller with the shared scheduler."
            );

            // And it must actually talk through it, so a failed request is what
            // the shared state learns from.
            $this->assertStringContainsString(
                'MicronScheduler.fetch(',
                $src,
                "{$view} must fetch through the shared scheduler."
            );
        }
    }

    /**
     * The whole point of the refactor: a page that kept its own timer would look
     * migrated while still failing on its own clock.
     */
    public function testNoPollerKeepsItsOwnTimer(): void
    {
        foreach (array_keys(self::POLLERS) as $view) {
            $this->assertStringNotContainsString(
                'setInterval(',
                $this->source($view),
                "{$view} still runs its own setInterval — its polling would not back off with the rest of the page."
            );
        }
    }

    /**
     * A poller reports failure by resolving `false` (or rejecting), which is what
     * drives the shared backoff. A poller that swallowed its error would keep
     * firing on its interval straight through an outage.
     */
    public function testEveryPollerCanReportAFailedAttempt(): void
    {
        foreach (array_keys(self::POLLERS) as $view) {
            $src = $this->source($view);

            $this->assertMatchesRegularExpression(
                '/return false;\s*(\/\/|})/',
                $src,
                "{$view} must let a failed attempt reach the scheduler (resolve false or reject)."
            );
        }
    }

    /**
     * The other half of "every page's polling and AJAX": a one-shot request that
     * still reached for `fetch` would fail silently, on its own, with no part of
     * it in the shared connection state.
     */
    public function testEveryAjaxCallGoesThroughTheScheduler(): void
    {
        $views = glob(ROOTPATH . 'app/Views/{,*/}*.php', GLOB_BRACE) ?: [];
        $this->assertNotEmpty($views, 'No views were found to check.');

        $offenders = [];

        foreach ($views as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(ROOTPATH)));

            // The picker makes its own requests and reports their outcome through
            // `MicronScheduler.report` (it owns the in-dialog note), so its bare
            // `fetch` calls are deliberate and covered by the test above.
            if ($relative === self::PICKER) {
                continue;
            }

            $src = (string) file_get_contents($path);

            // `fetch(` not already prefixed by `MicronScheduler.`.
            if (preg_match('/(?<!MicronScheduler\.)\bfetch\(/', $src)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These views still call fetch() directly; their AJAX would not feed the shared connection state:\n  - " . implode("\n  - ", $offenders)
        );
    }

    // ------------------------------------------------------------------
    // The picker reports through the same state
    // ------------------------------------------------------------------

    public function testThePickerReportsThroughTheSharedState(): void
    {
        $picker = $this->source(self::PICKER);

        // The picker keeps its own in-dialog note, but the *connection* is the
        // page's: a failed search raises the banner too, and an answer clears it.
        $this->assertStringContainsString('window.MicronScheduler.report', $picker);
        $this->assertStringContainsString('sharedReport(false, err);', $picker);
        $this->assertStringContainsString('sharedReport(true);', $picker);

        // And it quotes the shared schedule rather than its own arithmetic.
        $this->assertStringContainsString('window.MicronScheduler.backoffDelay', $picker);
        $this->assertStringContainsString('sharedDelay(cfg.failures)', $picker);
    }
}

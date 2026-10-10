<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every plugin-contributed UI view (`Views/plugins/apps/<id>.php`) must render
 * *inside* the shared layout.
 *
 * `Plugins::show()` renders one of these in place of the generic metadata page.
 * A view that forgets `extend()`/`section()`/`endSection()` still returns 200
 * and still contains every string a `assertSee('…')` content check looks for —
 * it simply has no stylesheet, no navigation and no page chrome, so the failure
 * is invisible to functional tests and only shows up as an unstyled page. This
 * lint catches exactly that mistake for the current views and for every view
 * added later.
 *
 * @internal
 */
final class PluginAppViewsTest extends CIUnitTestCase
{
    /** @return list<string> */
    private function views(): array
    {
        $files = glob(APPPATH . 'Views/plugins/apps/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function testThereIsAtLeastOnePluginAppView(): void
    {
        // A guard against the directory being renamed or emptied: the lint below
        // would otherwise pass vacuously.
        $this->assertNotEmpty($this->views(), 'no plugin app views were found to lint');
    }

    public function testEveryPluginAppViewExtendsTheLayout(): void
    {
        foreach ($this->views() as $file) {
            $name   = basename($file);
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString(
                "\$this->extend('layouts/main')",
                $source,
                $name . ' must extend the main layout, or it renders with no chrome',
            );
            $this->assertStringContainsString(
                "\$this->section('content')",
                $source,
                $name . ' must open the layout\'s content section',
            );
            $this->assertStringContainsString(
                '$this->endSection()',
                $source,
                $name . ' must close the content section',
            );
        }
    }

    public function testEveryPluginAppViewBalancesItsSections(): void
    {
        foreach ($this->views() as $file) {
            $name   = basename($file);
            $source = (string) file_get_contents($file);

            $this->assertSame(
                substr_count($source, '->section('),
                substr_count($source, '->endSection('),
                $name . ' opens a different number of sections than it closes',
            );
        }
    }
}

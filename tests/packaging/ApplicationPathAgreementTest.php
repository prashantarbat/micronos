<?php

declare(strict_types=1);

namespace Tests\Packaging;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the single application location across the packaging documentation.
 *
 * The appliance runs the application from one path: the provisioning hook writes
 * the Apache vhost, stage-overlay.sh stages the tree there, and the packaging
 * documentation tells the packager the same thing. Documentation that disagrees
 * with the hook is worse than no documentation — an operator following the ISO
 * guide's nginx `root`, or the implementation plan's clone target, would serve or
 * build against a tree the image never populates, and nothing in the build says
 * so. The repository once carried exactly that split: the hook and the staging
 * script used /usr/local/micron while the guide's overview diagram, its overlay
 * tree, its nginx block and the plan's clone step still said /var/www/micronos.
 *
 * The path is never listed here as a literal. It is derived from the provisioning
 * hook — the file that actually configures what the appliance serves — and every
 * document is asserted against that derivation, so moving the application moves
 * the whole set or fails naming the document left behind. A retired path is
 * swept from every published guide (source and regenerated export) so a stale
 * export cannot reintroduce it either.
 *
 * @internal
 */
final class ApplicationPathAgreementTest extends CIUnitTestCase
{
    private const HOOK = 'packaging/live-build/config/hooks/live/01-micronos-setup.hook.chroot';

    private const GUIDE = 'docs/ISO_CREATION_AND_INSTALLER_GUIDE.md';
    private const PLAN  = 'docs/STEP_BY_STEP_IMPLEMENTATION_PLAN.md';
    private const SPEC  = 'docs/MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.md';

    /** The application path this repository retired in favour of the hook's. */
    private const RETIRED = 'www/micronos';

    /**
     * The hook is the source of truth: it is what the image executes to decide
     * where the application is served from.
     */
    private function applicationRoot(): string
    {
        $hook = (string) file_get_contents(ROOTPATH . self::HOOK);

        $this->assertSame(
            1,
            preg_match('#^\s*DocumentRoot\s+(\S+)#m', $hook, $match),
            'The provisioning hook must declare the web root the appliance serves.'
        );

        $documentRoot = $match[1];

        $this->assertStringEndsWith(
            '/public',
            $documentRoot,
            'The DocumentRoot must be the public/ directory of the application root.'
        );

        return substr($documentRoot, 0, -strlen('/public'));
    }

    /** The documented web server root must be the hook's DocumentRoot itself. */
    public function testTheIsoGuideServesFromTheHooksDocumentRoot(): void
    {
        $guide = (string) file_get_contents(ROOTPATH . self::GUIDE);
        $hook  = (string) file_get_contents(ROOTPATH . self::HOOK);

        preg_match('#^\s*DocumentRoot\s+(\S+)#m', $hook, $documentRoot);

        $this->assertSame(
            1,
            preg_match('#^\s*root\s+(\S+);#m', $guide, $guideRoot),
            'The ISO guide must document a web server root for the appliance.'
        );

        $this->assertSame(
            $documentRoot[1],
            $guideRoot[1],
            'The ISO guide\'s nginx root must be the DocumentRoot the provisioning hook configures.'
        );
    }

    /** The guide's overview must place the management plane at that same path. */
    public function testTheIsoGuideDiagramNamesTheHooksApplicationRoot(): void
    {
        $guide    = (string) file_get_contents(ROOTPATH . self::GUIDE);
        $appRoot  = $this->applicationRoot();

        $this->assertStringContainsString(
            "({$appRoot})",
            $guide,
            'The ISO guide\'s overview diagram must show the application at the hook\'s path.'
        );
    }

    /** The plan deploys a test VM the way the appliance is laid out. */
    public function testTheImplementationPlanClonesIntoTheHooksApplicationRoot(): void
    {
        $plan    = (string) file_get_contents(ROOTPATH . self::PLAN);
        $appRoot = $this->applicationRoot();

        $this->assertStringContainsString(
            "into `{$appRoot}`",
            $plan,
            'The implementation plan must clone the repository into the hook\'s application path.'
        );
    }

    /** The current-state audit describes where the installer puts the tree. */
    public function testTheUpdateSpecificationLocatesTheApplicationAtTheHooksRoot(): void
    {
        $spec    = (string) file_get_contents(ROOTPATH . self::SPEC);
        $appRoot = $this->applicationRoot();

        $this->assertStringContainsString(
            "**`{$appRoot}`**",
            $spec,
            'The update specification must place the application at the hook\'s path.'
        );
        $this->assertStringContainsString(
            "{$appRoot}/public",
            $spec,
            'The update specification must root the web server at the hook\'s document root.'
        );
    }

    /**
     * The retired path must be gone from every guide, source and export alike:
     * the exported HTML is a committed artifact, so an edit to the markdown that
     * nobody rebuilt would otherwise ship the old path to a reader.
     */
    public function testNoPublishedGuideNamesTheRetiredApplicationPath(): void
    {
        $files = array_merge(
            glob(ROOTPATH . 'docs/*.md') ?: [],
            glob(ROOTPATH . 'docs/*.html') ?: []
        );

        $this->assertNotEmpty($files, 'The published guides must exist to be checked.');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                self::RETIRED,
                $contents,
                basename($file) . ' still names the retired application path "' . self::RETIRED . '".'
            );
        }
    }

    /**
     * The sweep above only fails loudly if the documents positively name the
     * path the hook serves; otherwise a guide that dropped the application path
     * entirely would pass by saying nothing.
     */
    public function testThePackagingDocumentsAllNameTheHooksApplicationRoot(): void
    {
        $appRoot = $this->applicationRoot();

        foreach ([self::GUIDE, self::PLAN, self::SPEC] as $document) {
            $contents = (string) file_get_contents(ROOTPATH . $document);

            $this->assertStringContainsString(
                $appRoot,
                $contents,
                basename($document) . ' must name the application path the provisioning hook serves.'
            );
        }
    }
}

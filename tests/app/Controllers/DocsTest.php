<?php

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the in-app documentation guides (docs/*.md → sectioned view).
 *
 * Auth is satisfied through the session, which PermissionModel and the global
 * auth filter read before ever touching the database.
 *
 * @internal
 */
final class DocsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = false;

    /** Permission keys the session carries. */
    private const PERMISSIONS = ['dashboard.view', 'dns.view'];

    public function testDefaultGuideIsTheArchitectureSpecification(): void
    {
        $result = $this->withSession($this->session())->get('docs');

        $result->assertOK();
        $result->assertSee('Table of Contents');
        $result->assertSee('Micron Firewall OS');
        // The architecture guide's first numbered section.
        $result->assertSee('Executive Summary &amp; Core Philosophy');
        $result->assertSee('docs/MICRON_FIREWALL_OS_ARCHITECTURE.md');

        $body = (string) $result->getBody();

        // The H2 subtitle is the page header, never a section: the guide has
        // exactly its 26 numbered sections.
        $this->assertSame(26, preg_match_all('/class="docx-section card card-glass"/', $body));
        $this->assertStringContainsString('Comprehensive Technical Architecture &amp; Engineering Specification', $body);
        $this->assertStringContainsString('data-anchor="1-executive-summary--core-philosophy"', $body);
    }

    public function testEveryGuideRenders(): void
    {
        $expectations = [
            'architecture' => 'Unified Database Schema',
            'modules'      => 'Management Plane Controllers Reference',
            'plan'         => 'Execution Overview',
            'iso'          => 'Overview &amp; Boot Architecture',
            'ztna'         => 'Executive Summary',
            'updates'      => 'Purpose &amp; Scope',
            'plugins'      => 'Navigation menus contributed by plugins',
            'connectors'   => 'Encrypted Secret Store',
            'threats'      => 'Assets &amp; Trust Boundaries',
            'install'      => 'Run the installer',
            'hardening'    => 'Management access',
            'release-notes' => 'Preview features',
        ];

        foreach ($expectations as $slug => $needle) {
            $result = $this->withSession($this->session())->get('docs/' . $slug);
            $result->assertOK();
            $result->assertSee($needle);
        }
    }

    /** The generated table of contents carries every section anchor. */
    public function testTableOfContentsLinksEverySection(): void
    {
        $body = (string) $this->withSession($this->session())->get('docs/iso')->getBody();

        $tocLinks = preg_match_all('/data-anchor="/', $body);
        $sections = preg_match_all('/class="docx-section card card-glass"/', $body);

        $this->assertGreaterThan(5, $tocLinks, 'The TOC should list the ISO guide sections.');
        $this->assertSame($tocLinks, $sections, 'Every TOC entry must map to exactly one section card.');
        $this->assertStringContainsString('data-anchor="1-overview--boot-architecture"', $body);
    }

    /**
     * Guides cross-reference each other by their standalone export name
     * (`MICRON_FIREWALL_OS_ARCHITECTURE.html`). The page must carry the
     * file-stem → in-app slug map so those links resolve to /docs/<slug>
     * instead of 404-ing.
     */
    public function testCrossGuideLinksAreRemappedToInAppSlugs(): void
    {
        $body = (string) $this->withSession($this->session())->get('docs/architecture')->getBody();

        // One entry per registered guide, covering both the `.html` export and
        // the `.md` source link forms used inside the guides.
        $this->assertStringContainsString('"MICRONOS_MODULE_REFERENCE":"modules"', $body);
        $this->assertStringContainsString('"MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION":"updates"', $body);
        $this->assertStringContainsString('"ISO_CREATION_AND_INSTALLER_GUIDE":"iso"', $body);
        // The Connector pair links to each other, so a missing entry here turns a
        // cross-guide link in the rendered page into a route that 404s.
        $this->assertStringContainsString('"MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION":"connectors"', $body);
        $this->assertStringContainsString('"MICRONOS_CONNECTOR_THREAT_MODEL":"threats"', $body);
        $this->assertStringContainsString('var DOC_LINKS = {', $body);
        $this->assertStringContainsString('var DOC_BASE', $body);
        $this->assertStringContainsString('\\.(?:html|md)(', $body);
    }

    /** The update specification is a first-class guide, not just a file on disk. */
    public function testUpdateSpecificationGuideIsRegistered(): void
    {
        $result = $this->withSession($this->session())->get('docs/updates');

        $result->assertOK();
        $result->assertSee('Not Yet Implemented');
        $result->assertSee('docs/MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.md');

        $body = (string) $result->getBody();

        // The guide has exactly its 14 numbered sections; the H2 subtitle is the
        // page header and must never render as a section card.
        $this->assertSame(14, preg_match_all('/class="docx-section card card-glass"/', $body));
        $this->assertStringContainsString('data-anchor="1-purpose--scope"', $body);
        $this->assertStringContainsString('data-anchor="14-document-corrections-applied"', $body);
    }

    /** The markdown payload ships as JSON, never as raw markup in the page. */
    public function testMarkdownIsPassedAsJsonPayload(): void
    {
        $body = (string) $this->withSession($this->session())->get('docs/architecture')->getBody();

        $this->assertStringContainsString('var MD = {', $body);
        $this->assertStringContainsString('dns_domain_overrides', $body);
        // Hex-escaped opening tag: markdown can contain "<" without ever
        // closing the script element that carries it.
        $this->assertStringNotContainsString('</script><script>', $body);
    }

    /**
     * Every section the page renders must arrive with its markdown.
     *
     * The payload is one `json_encode` of the whole guide, so a single section
     * that cannot be encoded empties it for *all* of them: the page still lists
     * every heading, and each card silently renders with no content. That is how
     * a byte inside a multi-byte character can take a guide's body out without
     * any other test noticing, so the two counts are compared per guide.
     */
    public function testEveryRenderedSectionCarriesItsMarkdown(): void
    {
        foreach (['architecture', 'plan', 'modules', 'iso', 'ztna', 'updates', 'plugins', 'connectors', 'threats'] as $slug) {
            $body = (string) $this->withSession($this->session())->get('docs/' . $slug)->getBody();

            $cards = preg_match_all('/class="docx-section card card-glass"/', $body);
            $this->assertGreaterThan(0, $cards, "the {$slug} guide must render its sections");

            $marker = strpos($body, 'var MD = ');
            $this->assertNotFalse($marker, "the {$slug} guide must ship a markdown payload");

            // Balanced-brace scan: the JSON contains braces and newlines of its own.
            $start = $marker + strlen('var MD = ');
            $depth = 0;
            $end   = null;
            for ($i = $start; $i < strlen($body); $i++) {
                if ($body[$i] === '{') {
                    $depth++;
                } elseif ($body[$i] === '}' && --$depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }

            $this->assertNotNull($end, "the {$slug} guide's markdown payload must be a JSON object");

            $map = json_decode(substr($body, $start, $end - $start), true);

            $this->assertIsArray($map, "the {$slug} guide's markdown payload must decode");
            $this->assertSame(
                $cards,
                count($map),
                "every section rendered for {$slug} must carry its markdown"
            );
        }
    }

    /**
     * The framework turns PageNotFoundException into the 404 response; the
     * test harness rethrows it, which is how CI4 wants this asserted.
     */
    public function testUnknownGuideIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->withSession($this->session())->get('docs/not-a-guide');
    }

    /** Path traversal must never resolve to a file outside docs/. */
    public function testTraversalSlugIsRejected(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->withSession($this->session())->get('docs/..%2F..%2Fcomposer');
    }

    /**
     * The guides are read on a management network with no route to the internet,
     * so every script the page needs must come from this appliance.
     *
     * The renderer and highlighter used to be pulled from jsDelivr and cdnjs,
     * which meant an air-gapped operator got the raw-markdown fallback instead of
     * rendered guides - and the page still looked loaded, so nothing signalled
     * the degradation.
     */
    public function testEveryScriptComesFromThisAppliance(): void
    {
        $result = $this->withSession($this->session())->get('docs');
        $result->assertOK();

        $body = (string) $result->getBody();

        preg_match_all('/<script[^>]+src="([^"]+)"/i', $body, $sources);

        // Exactly these, in this order: the two shared scripts the layout loads
        // for every page (the request scheduler, then the table builder), then
        // the two libraries — before the inline script that follows, which parses
        // markdown immediately.
        $this->assertSame(
            [
                base_url('assets/js/micronos-scheduler.js') . '?v=' . filemtime(FCPATH . 'assets/js/micronos-scheduler.js'),
                base_url('assets/js/micronos-table.js') . '?v=' . filemtime(FCPATH . 'assets/js/micronos-table.js'),
                // the shared shell helpers (confirm dialog / toasts, command palette)
                base_url('assets/js/micronos-ui.js') . '?v=' . filemtime(FCPATH . 'assets/js/micronos-ui.js'),
                base_url('assets/js/micronos-palette.js') . '?v=' . filemtime(FCPATH . 'assets/js/micronos-palette.js'),
                base_url('assets/vendor/marked.min.js') . '?v=' . filemtime(FCPATH . 'assets/vendor/marked.min.js'),
                base_url('assets/vendor/highlight.min.js') . '?v=' . filemtime(FCPATH . 'assets/vendor/highlight.min.js'),
            ],
            $sources[1],
            'the guides must load the shared scripts and their renderer and highlighter from this appliance, in order'
        );

        // Named hosts are called out so a reintroduced CDN fails with a message
        // that says what to do, not just an unexpected array. (The web-font
        // preconnect in the layout is a separate concern: fonts degrade to the
        // system stack, whereas a missing renderer drops the guides to raw text.)
        $scriptSources = implode(' ', $sources[1]);

        foreach (['jsdelivr', 'cdnjs', 'unpkg'] as $needle) {
            $this->assertStringNotContainsString($needle, $scriptSources, "the guides must not load a script from {$needle}");
        }
    }

    /**
     * A vendored file that was truncated by a partial download, or replaced by a
     * placeholder, would break the browser silently: the page still renders, the
     * markdown just comes out raw. So the files are checked for the upstream
     * banner that identifies and versions them.
     */
    public function testTheVendoredRendererAndHighlighterAreIntact(): void
    {
        $expected = [
            'assets/vendor/marked.min.js'    => 'marked v15.0.7',
            'assets/vendor/highlight.min.js' => 'Highlight.js v11.9.0',
        ];

        foreach ($expected as $relative => $banner) {
            $path = FCPATH . $relative;

            $this->assertFileExists($path, "{$relative} must be vendored into public/");
            $this->assertGreaterThan(
                10_000,
                (int) filesize($path),
                "{$relative} is implausibly small for a full build"
            );
            $this->assertStringContainsString(
                $banner,
                (string) file_get_contents($path),
                "{$relative} must still carry its upstream version banner"
            );
        }

        // Provenance travels with the files, so a version bump is a code change
        // with a licence and an upstream URL attached rather than a mystery blob.
        $readme = (string) file_get_contents(FCPATH . 'assets/vendor/README.md');
        $this->assertStringContainsString('marked.min.js', $readme);
        $this->assertStringContainsString('highlight.min.js', $readme);
        $this->assertStringContainsString('MIT', $readme);
        $this->assertStringContainsString('BSD-3-Clause', $readme);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
}

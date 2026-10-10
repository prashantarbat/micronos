<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Docs integrity — the plugin catalogue is the single source of truth for what
 * MicronOS ships as an integration.
 *
 * A specification that names an integration the catalogue does not carry is
 * worse than an omission: it advertises a capability an operator will look for
 * and not find. Four names had accumulated in the Plugin & Package Manager
 * Specification that were not plugins in this tree at all — ACME / Let's
 * Encrypt (a PKI capability, not a plugin), Q-Feeds (a Class A connector),
 * OpenDNS / Umbrella (a resolver-upstream mode) and Zenarmor (a vendor SaaS whose
 * repository answers `204` anonymously, so no preset can anchor a fingerprint).
 * They were removed; this guard is what keeps them from creeping back.
 *
 * ACME is the case worth stating precisely, because it is the one that became
 * real: it is now implemented — `app/Libraries/Acme.php`, `AcmeManager`, the
 * public HTTP-01 endpoint and `micron:acme-renew`, documented in the Module
 * Reference §2.24 — and it still belongs *out* of this specification. The rule
 * being enforced is about the shape of the capability, not its existence: it is
 * a PKI feature of the module, so it cannot be advertised as a plugin an
 * operator installs from the catalogue.
 *
 * The contract has three parts, and each is asserted against the real docs:
 *
 *  1. The §11 catalogue table lists **exactly** the registered integrations —
 *     neither a name the registry does not know, nor a missing one. Restoring a
 *     removed row therefore fails here, not in review.
 *  2. Nothing in the docs names a **retired** integration where it was removed
 *     from. `scope` is the widest set of documents each may not reappear in:
 *     `plugin-spec` for a capability that legitimately belongs to another spec
 *     (ACME lives in the Connector Spec as a `pki.deploy` target, Q-Feeds as its
 *     §15.1 pilot), and `all-docs` for a name removed outright.
 *  3. The catalogue **parses**. Every check above is vacuous if the table cannot
 *     be read, so the heading and the parsed rows are asserted to be non-empty.
 *
 * The checker is pure and the tests drive it: the negative controls feed it a
 * synthetic spec that names a retired integration and require it to object, and
 * they drive the scope distinction from both sides — a reminder that "the check
 * passed" only means something if the check can fail.
 *
 * @internal
 */
final class DocsIntegrationCatalogueTest extends CIUnitTestCase
{
    /** The document that owns the catalogue. */
    private const PLUGIN_SPEC = 'MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.md';

    /** The section whose first table *is* the catalogue. */
    private const CATALOGUE_HEADING = '## 11. Integration Catalogue';

    /**
     * Integrations the catalogue must list, exactly as the table's first column
     * spells them. Compared after {@see self::normalise()}, so markup and a
     * parenthetical gloss do not have to be repeated here.
     *
     * @var list<string>
     */
    private const CATALOGUE = [
        'LLDP (`lldpd`)',
        'iperf3',
        'darkstat',
        'Lightsquid',
        'ZeroTier One',
        'NetBird',
        'NAT64 / DNS64',
        'IPv6 tunnel broker (6in4)',
    ];

    /**
     * Removed integrations, keyed by the label used in messages.
     *
     * `[scope, patterns]`: `scope` is the widest set of documents the name may
     * not appear in, and `patterns` are the spellings it appears as (an alias
     * counts — "Umbrella" alone is the same removal as "OpenDNS / Umbrella").
     *
     * @var array<string, array{0: 'plugin-spec'|'all-docs', 1: list<string>}>
     */
    private const RETIRED = [
        "ACME / Let's Encrypt" => ['plugin-spec', ['ACME', "Let's Encrypt", 'Let’s Encrypt']],
        'Q-Feeds'              => ['plugin-spec', ['Q-Feeds', 'Q‑Feeds', 'Q&#8209;Feeds']],
        'Zenarmor'             => ['all-docs', ['Zenarmor']],
        'OpenDNS / Umbrella'   => ['all-docs', ['OpenDNS', 'Umbrella']],
    ];

    // ------------------------------------------------------------------
    // The catalogue itself
    // ------------------------------------------------------------------

    /**
     * The check reads a heading and a table out of the spec. If either is
     * reformatted every assertion below silently passes on an empty set, so both
     * are asserted first.
     */
    public function testThePluginSpecCarriesTheCatalogueThisGuardReads(): void
    {
        $docs = self::documents();

        $this->assertArrayHasKey(
            self::PLUGIN_SPEC,
            $docs,
            'the plugin specification this guard reads must exist under docs/'
        );
        $this->assertStringContainsString(
            self::CATALOGUE_HEADING,
            $docs[self::PLUGIN_SPEC],
            'the integration catalogue section must keep its heading, or this guard reads nothing'
        );
        $this->assertNotEmpty(
            self::catalogueRows($docs[self::PLUGIN_SPEC]),
            'the catalogue table must parse to at least one row'
        );
    }

    public function testTheCatalogueListsExactlyTheRegisteredIntegrations(): void
    {
        $findings = self::catalogueFindings(self::catalogueRows(self::documents()[self::PLUGIN_SPEC]));

        $this->assertSame(
            [],
            $findings,
            "the §11 catalogue must carry exactly the registered integrations — an unregistered row is an "
                . "advertisement, a missing one is a regression:\n  " . implode("\n  ", $findings)
        );
    }

    /**
     * Belt and braces beside the exact-set check, because "the catalogue does not
     * list a removed integration" is the invariant a reader cares about, and its
     * failure should name the integration rather than an array difference.
     */
    public function testNoRetiredIntegrationIsACatalogueRow(): void
    {
        $rows = self::catalogueRows(self::documents()[self::PLUGIN_SPEC]);

        foreach (array_keys(self::RETIRED) as $label) {
            $this->assertNotContains(
                self::normalise($label),
                $rows,
                "the retired integration \"{$label}\" must not be a catalogue row"
            );
        }
    }

    // ------------------------------------------------------------------
    // The docs the names were removed from
    // ------------------------------------------------------------------

    public function testNoDocumentNamesARetiredIntegration(): void
    {
        $violations = self::violations(self::documents());

        $this->assertSame(
            [],
            $violations,
            "the docs must not name an integration that is not in the plugin catalogue:\n  "
                . implode("\n  ", $violations)
        );
    }

    // ------------------------------------------------------------------
    // Controls — the check can fail, and its scope is exact
    // ------------------------------------------------------------------

    /**
     * A guard nobody has seen fail proves nothing. Feeding the real checker a
     * spec that names all four removed integrations must produce a violation for
     * each of them.
     */
    public function testTheCheckRejectsASpecThatNamesARetiredIntegration(): void
    {
        $violations = self::violations([
            self::PLUGIN_SPEC => 'The integration catalogue: ' . implode(', ', array_keys(self::RETIRED)) . '.',
        ]);

        $this->assertNotSame([], $violations, 'the check must be able to fail');

        foreach (array_keys(self::RETIRED) as $label) {
            $this->assertStringContainsString(
                $label,
                implode("\n", $violations),
                "a reintroduced \"{$label}\" must be reported by name"
            );
        }
    }

    /**
     * ACME and Q-Feeds keep legitimate non-plugin homes, so the scope has to be
     * exact rather than a blanket ban: naming them in the Connector Spec is
     * correct, naming them in the catalogue's own document is the regression.
     */
    public function testARetiredNameIsToleratedOnlyOutsideThePluginSpec(): void
    {
        $elsewhere = self::violations([
            'MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.md' => 'Reserved for ACME; the Q-Feeds pilot stages IP and domain feeds.',
        ]);
        $this->assertSame([], $elsewhere, 'a name with a legitimate home in another spec must not be flagged there');

        $here = self::violations([
            self::PLUGIN_SPEC => 'Reserved for ACME; the Q-Feeds pilot stages IP and domain feeds.',
        ]);
        $this->assertCount(2, $here, 'the same sentence in the catalogue document must be two violations');
    }

    /**
     * The parser must read rows — not the header, the separator, a blockquote or
     * a paragraph — and restoring one of the removed rows must fail the guard.
     */
    public function testTheParserReadsRowsAndCatchesAReintroducedOne(): void
    {
        $noise = self::CATALOGUE_HEADING
            . "\n\nThe request that motivated this document named a specific set.\n\n"
            . "| Integration | Class |\n| :-- | :-- |\n| **LLDP (`lldpd`)** | B |\n\n"
            . "> **Why ZeroTier is Class C.** Prose, not a row, and `|` is not a table.\n";

        $this->assertSame(['lldp'], self::catalogueRows($noise), 'only table rows may be read as catalogue entries');

        $reintroduced = self::CATALOGUE_HEADING
            . "\n\n| Integration | Class |\n| :-- | :-- |\n| **Zenarmor** | C (premium, high-risk) | Vendor repo/SaaS |\n";

        $rows = self::catalogueRows($reintroduced);
        $this->assertContains('zenarmor', $rows, 'a reintroduced row must be parsed as a row');

        $findings = self::catalogueFindings($rows);
        $this->assertNotSame([], $findings, 'a reintroduced row must fail the catalogue check');
        $this->assertStringContainsString('zenarmor', implode("\n", $findings));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Every document under `docs/`, source and generated alike, keyed by file
     * name.
     *
     * The generated HTML embeds each guide's markdown, so a `*.md` edit that is
     * never rebuilt would still be caught here — and a hand-edited `*.html` that
     * names a removed integration is caught even though no markdown contains it.
     *
     * @return array<string, string>
     */
    private static function documents(): array
    {
        $docs = [];

        foreach (['md', 'html'] as $extension) {
            foreach ((array) glob(ROOTPATH . 'docs/*.' . $extension) as $path) {
                $docs[basename($path)] = (string) file_get_contents($path);
            }
        }

        return $docs;
    }

    /**
     * The first column of every row in the §11 catalogue table.
     *
     * The section is cut at the next heading, so a table added later in the
     * document cannot leak in, and the header and `| :-- |` separator are skipped
     * so only entries are returned.
     *
     * @return list<string> normalised entries
     */
    private static function catalogueRows(string $spec): array
    {
        $start = strpos($spec, self::CATALOGUE_HEADING);
        if ($start === false) {
            return [];
        }

        $section = substr($spec, $start);

        if (preg_match('/\n## /', $section, $match, PREG_OFFSET_CAPTURE, 1) === 1) {
            $section = substr($section, 0, $match[0][1]);
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue;
            }

            $first = array_map('trim', explode('|', $line))[1] ?? '';
            if ($first === '' || $first === 'Integration' || str_starts_with($first, ':--')) {
                continue;
            }

            $rows[] = self::normalise($first);
        }

        return $rows;
    }

    /**
     * What is wrong with a set of catalogue rows: an unregistered entry, or one
     * the registry expects and the table does not carry.
     *
     * @param list<string> $rows normalised entries
     *
     * @return list<string>
     */
    private static function catalogueFindings(array $rows): array
    {
        $expected = array_map([self::class, 'normalise'], self::CATALOGUE);
        $findings = [];

        foreach (array_diff($rows, $expected) as $extra) {
            $findings[] = "unregistered catalogue row: {$extra}";
        }

        foreach (array_diff($expected, $rows) as $missing) {
            $findings[] = "registered integration missing from the catalogue: {$missing}";
        }

        return $findings;
    }

    /**
     * Every place a retired integration is named where it may not be.
     *
     * @param array<string, string> $docs file name => contents
     *
     * @return list<string> one finding per document and integration
     */
    private static function violations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $name => $body) {
            foreach (self::RETIRED as $label => [$scope, $patterns]) {
                // `plugin-spec` means "the catalogue's own document": the name is
                // still a legitimate subject elsewhere, just not an integration
                // this catalogue lists.
                if ($scope === 'plugin-spec' && $name !== self::PLUGIN_SPEC) {
                    continue;
                }

                foreach ($patterns as $pattern) {
                    if (preg_match('/' . preg_quote($pattern, '/') . '/i', $body) === 1) {
                        $violations[] = "{$name} names the removed integration \"{$label}\" (as \"{$pattern}\")";

                        break;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * A cell or a label reduced to what it means: markup dropped, a trailing
     * parenthetical gloss dropped — the catalogue's LLDP row and its 6in4 row
     * name the same integration the registry does once the gloss is gone — and
     * everything else collapsed to words.
     */
    private static function normalise(string $text): string
    {
        $text = str_replace(['**', '`'], '', $text);
        $text = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $text);
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim($text);
    }
}

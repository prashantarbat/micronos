<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Documentation Controller — renders the repository's `docs/*.md` guides as
 * in-app reference pages: a filterable table of contents on the left and one
 * card per section on the right (operator-manual layout rather than a
 * standalone HTML export).
 *
 * The markdown stays the single source of truth; this controller only splits a
 * guide into sections and hands them to the view, which renders them client
 * side. Section anchors reuse the GitHub-style slugs used inside the guides, so
 * any in-document link keeps resolving.
 */
class Docs extends BaseController
{
    /**
     * Available guides: slug => [source file, switcher label].
     *
     * @var array<string, array{file: string, label: string}>
     */
    private const GUIDES = [
        'architecture' => [
            'file'  => 'MICRON_FIREWALL_OS_ARCHITECTURE',
            'label' => 'Architecture Blueprint',
        ],
        'plan' => [
            'file'  => 'STEP_BY_STEP_IMPLEMENTATION_PLAN',
            'label' => 'Implementation Plan',
        ],
        'modules' => [
            'file'  => 'MICRONOS_MODULE_REFERENCE',
            'label' => 'Module Reference',
        ],
        'iso' => [
            'file'  => 'ISO_CREATION_AND_INSTALLER_GUIDE',
            'label' => 'ISO & Installer Guide',
        ],
        'ztna' => [
            'file'  => 'SSLVPN_ZTNA_CLIENTLESS_GATEWAY_SPECIFICATION',
            'label' => 'SSL-VPN & ZTNA Spec',
        ],
        'updates' => [
            'file'  => 'MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION',
            'label' => 'Update & Upgrade Spec',
        ],
        'plugins' => [
            'file'  => 'MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION',
            'label' => 'Plugin & Package Manager Spec',
        ],
        'connectors' => [
            'file'  => 'MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION',
            'label' => 'Connector Abstraction Spec',
        ],
        'threats' => [
            'file'  => 'MICRONOS_CONNECTOR_THREAT_MODEL',
            'label' => 'Connector Threat Model',
        ],
        'hyperv' => [
            'file'  => 'HYPERV_WIN11_DEVELOPMENT_AND_AI_COORDINATION_GUIDE',
            'label' => 'Hyper-V & AI Dev Guide',
        ],
        'recovery' => [
            'file'  => 'POWER_LOSS_RESILIENCE_AND_RECOVERY_ENGINEERING_SPECIFICATION',
            'label' => 'Power Resilience & LKGC Spec',
        ],
        'roadmap' => [
            'file'  => 'MICRONOS_ADVANCED_ROADMAP_SPECIFICATION',
            'label' => 'Advanced Roadmap Spec',
        ],
        'versioning' => [
            'file'  => 'MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION',
            'label' => 'Versioning & Release Spec',
        ],
    ];

    /**
     * Renders one guide (defaults to the architecture specification).
     */
    public function index(string $slug = 'architecture'): string
    {
        if (! isset(self::GUIDES[$slug])) {
            throw new PageNotFoundException("No documentation guide named '{$slug}'.");
        }

        $guide = self::GUIDES[$slug];
        $path  = ROOTPATH . 'docs' . DIRECTORY_SEPARATOR . $guide['file'] . '.md';

        if (! is_readable($path)) {
            throw new PageNotFoundException('The markdown source for this guide is missing.');
        }

        [$docTitle, $docSubtitle, $sections] = $this->splitGuide((string) file_get_contents($path));

        $guides = [];
        foreach (self::GUIDES as $key => $meta) {
            $guides[] = ['slug' => $key, 'label' => $meta['label'], 'file' => $meta['file']];
        }

        // Markdown cross-references point at the standalone HTML exports
        // (docs/<FILE>.html). The view rewrites those to the in-app guide
        // routes, so a link between guides resolves here as well.
        $docLinks = [];
        foreach (self::GUIDES as $key => $meta) {
            $docLinks[$meta['file']] = $key;
        }

        return view('docs/page', [
            'title'       => $docTitle . ' — MicronOS Documentation',
            'breadcrumb'  => 'Documentation: ' . $guide['label'],
            'docTitle'    => $docTitle,
            'docSubtitle' => $docSubtitle,
            'sections'    => $sections,
            'guides'      => $guides,
            'docLinks'    => $docLinks,
            'activeSlug'  => $slug,
            'sourceFile'  => 'docs/' . $guide['file'] . '.md',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Markdown → sections
    // ─────────────────────────────────────────────────────────────────

    /**
     * Splits a guide into its intro and its `## ` sections.
     *
     * @return array{0: string, 1: string, 2: list<array<string, string>>}
     *         [document title, subtitle, sections]
     */
    private function splitGuide(string $markdown): array
    {
        $blocks = preg_split('/(?=^## )/m', $markdown) ?: [];

        $title    = 'MicronOS Documentation';
        $subtitle = '';
        $sections = [];
        $used     = [];

        foreach ($blocks as $index => $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            // `/u` is load-bearing: without it PCRE treats the byte 0x85 — which
            // is an ordinary continuation byte inside a multi-byte character such
            // as `✅` (E2 9C 85) — as a NEL line break, splitting *inside* the
            // character. The section's markdown then fails `json_encode` in the
            // view (`var MD = ;`), and every section of that guide renders with a
            // heading and no content, silently.
            $lines = preg_split('/\R/u', $block) ?: [];
            $first = (string) array_shift($lines);

            if ($index === 0) {
                // Prologue: "# Title" followed by "## Subtitle".
                $firstLine = trim($first);
                if (str_starts_with($firstLine, '# ')) {
                    $title = trim(substr($firstLine, 2)) ?: $title;
                }
                foreach ($lines as $line) {
                    if (str_starts_with($line, '## ')) {
                        $subtitle = trim(substr($line, 3));
                        break;
                    }
                }
                continue;
            }

            if (! str_starts_with($first, '## ')) {
                continue;
            }

            $heading = trim(substr($first, 3));
            $lower   = strtolower($heading);

            // Prologue continuation: the "## Subtitle" (and optional "### tagline")
            // that sits between the H1 and the Table of Contents.
            if ($subtitle === '' && $lower !== 'table of contents' && preg_match('/^\d/', $heading) !== 1) {
                $subtitle = $heading;
                foreach ($lines as $line) {
                    if (str_starts_with($line, '### ')) {
                        $subtitle .= ' — ' . trim(substr($line, 4));
                        break;
                    }
                }
                continue;
            }

            if ($lower === 'table of contents') {
                // The page renders its own table of contents.
                continue;
            }

            $position = count($sections) + 1;
            $sections[] = [
                'id'       => $this->guideSlug($heading, $used),
                'number'   => $this->headingNumber($heading, $position),
                'heading'  => $heading,
                'label'    => $this->stripNumbering($heading),
                'icon'     => $this->iconSvg($heading),
                'markdown' => trim(implode("\n", $lines)),
            ];
        }

        return [$title, $subtitle, $sections];
    }

    /**
     * The section number printed in the TOC and the section badge: the guide's
     * own numbering when it has one (so "4.5" stays "4.5"), else its position.
     */
    private function headingNumber(string $heading, int $position): string
    {
        return preg_match('/^(\d+(?:\.\d+)+|\d+)[.)]?\s/', $heading . ' ', $m) === 1
            ? $m[1]
            : (string) $position;
    }

    /**
     * Heading text without its leading numbering, for the TOC label.
     */
    private function stripNumbering(string $heading): string
    {
        $label = preg_replace('/^\d+(?:\.\d+)*[.)]?\s+/', '', $heading) ?? $heading;

        return trim($label) !== '' ? trim($label) : $heading;
    }

    /**
     * GitHub-compatible heading slug, deduplicated — matches the anchors the
     * guides already link to (`#18-unified-database-schema-...`).
     *
     * @param array<string, true> $used slugs already handed out
     */
    private function guideSlug(string $heading, array &$used): string
    {
        $base = trim(str_replace(' ', '-', preg_replace('/[^a-z0-9 -]/', '', strtolower($heading))), '-');
        if ($base === '') {
            $base = 'section';
        }

        $slug  = $base;
        $count = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $count++;
        }
        $used[$slug] = true;

        return $slug;
    }

    // ─────────────────────────────────────────────────────────────────
    // Section icon (inline SVG — the appliance ships no icon font)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Picks an inline SVG glyph for a section from its heading keywords.
     */
    private function iconSvg(string $heading): string
    {
        $glyphs = [
            'shield'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
            'fire'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 .5-7m0 0A8 8 0 1017.5 7.5"/>',
            'globe'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'database'=> '<path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 10c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4z"/>',
            'server'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>',
            'clock'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'pulse'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
            'archive' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>',
            'key'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>',
            'map'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 20.5L3 18V6l6 2.5m0 12l6-2.5m-6 2.5V8.5m6 9.5l6 2.5V6.5l-6 2.5m0 9v-11"/>',
            'cloud'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>',
            'book'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
        ];

        // First keyword wins, so the more specific groups sit on top.
        $keywords = [
            'shield'   => ['vpn', 'wireguard', 'openvpn', 'ipsec', 'security', 'rbac', 'permission', 'identity', 'access control', '2fa', 'ha', 'failover'],
            'fire'     => ['firewall', 'nftables', 'nat ', 'packet', 'ids', 'suricata', 'threat', 'dnsbl', 'sinkhole'],
            'database' => ['database', 'schema', 'ddl', 'sqlite', 'mysql', 'migration', 'catalogue'],
            'archive'  => ['backup', 'restore', 'rollback', 'commit', 'disaster', 'lifecycle'],
            'clock'    => ['cron', 'scheduled', 'watchdog', 'maintenance', 'job'],
            'pulse'    => ['snmp', 'siem', 'telemetry', 'monitor', 'log', 'metric', 'observability'],
            'globe'    => ['routing', 'bgp', 'ospf', 'frr', 'gateway', 'multi-wan', 'wan ', 'pppoe', 'radius', 'subscriber', 'network'],
            'server'   => ['iso', 'install', 'partition', 'appliance', 'build', 'cli', 'codeigniter'],
            'cloud'    => ['proxy', 'squid', 'web filter', 'content', 'filter'],
            'key'      => ['identity', 'ldap', 'authentication', 'role', 'user'],
            'map'      => ['overview', 'executive', 'roadmap', 'phase', 'philosophy', 'comparison', 'road warrior'],
        ];

        $text    = strtolower($heading);
        $key     = 'book';
        foreach ($keywords as $candidate => $terms) {
            foreach ($terms as $term) {
                if (str_contains($text, $term)) {
                    $key = $candidate;
                    break 2;
                }
            }
        }

        return '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
            . $glyphs[$key] . '</svg>';
    }
}

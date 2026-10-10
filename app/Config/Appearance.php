<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Appearance — canonical list of colour themes and layout presets.
 *
 * Single source of truth for:
 *   - the theme & layout switcher popover (app/Views/partials/theme_switcher_popover.php)
 *   - System Settings → System & Misc
 *   - request/session validation in app/Views/layouts/main.php
 *   - POST /system/saveTheme and POST /system/save
 *
 * `mode` drives the shared light/dark override sets in public/css/micronos.css:
 * themes with mode "light" get data-mode="light" on <html>, so every light
 * theme inherits the light-mode component fixes without repeating them.
 * Each theme id must have a matching [data-theme="<id>"] block in micronos.css.
 */
class Appearance extends BaseConfig
{
    /**
     * id => label, swatch preview gradient, mode.
     *
     * @var array<string, array{label: string, dot: string, mode: string}>
     */
    public array $themes = [
        // ── Dark themes ──────────────────────────────────────────────
        'dark'     => ['label' => 'Dark Abyss',  'dot' => 'linear-gradient(135deg,#070b14,#00d4ff)', 'mode' => 'dark'],
        'midnight' => ['label' => 'Midnight',    'dot' => 'linear-gradient(135deg,#090712,#a78bfa)', 'mode' => 'dark'],
        'ocean'    => ['label' => 'Ocean Teal',  'dot' => 'linear-gradient(135deg,#030f14,#2dd4bf)', 'mode' => 'dark'],
        'forest'   => ['label' => 'Forest',      'dot' => 'linear-gradient(135deg,#050f08,#4ade80)', 'mode' => 'dark'],
        'slate'    => ['label' => 'Slate Grey',  'dot' => 'linear-gradient(135deg,#0a0d12,#94a3b8)', 'mode' => 'dark'],
        // ── Light themes ─────────────────────────────────────────────
        'light'    => ['label' => 'Light / Day', 'dot' => 'linear-gradient(135deg,#f1f5f9,#0284c7)', 'mode' => 'light'],
        'solar'    => ['label' => 'Solar Amber', 'dot' => 'linear-gradient(135deg,#fdf7ee,#ea580c)', 'mode' => 'light'],
        'mint'     => ['label' => 'Mint Breeze', 'dot' => 'linear-gradient(135deg,#f0fdf9,#0d9488)', 'mode' => 'light'],
        'paper'    => ['label' => 'Paper Ink',   'dot' => 'linear-gradient(135deg,#f7f7f8,#4f46e5)', 'mode' => 'light'],
    ];

    /**
     * Layout presets: id => label used in the switcher and settings UI.
     *
     * @var array<string, string>
     */
    public array $layouts = [
        'sidebar' => 'Sidebar — left navigation panel',
        'topnav'  => 'Top Nav — menus in the header',
    ];

    /** All valid theme ids. @return list<string> */
    public function ids(): array
    {
        return array_keys($this->themes);
    }

    /** Ids of the light-base themes. @return list<string> */
    public function lightIds(): array
    {
        return array_keys(array_filter($this->themes, static fn (array $t): bool => $t['mode'] === 'light'));
    }

    /** All valid layout ids. @return list<string> */
    public function layoutIds(): array
    {
        return array_keys($this->layouts);
    }

    /** Human label for a theme id (falls back to the raw id). */
    public function label(string $id): string
    {
        return $this->themes[$id]['label'] ?? $id;
    }

    /** True when the theme id renders on a light base. */
    public function isLight(string $id): bool
    {
        return ($this->themes[$id]['mode'] ?? 'dark') === 'light';
    }
}

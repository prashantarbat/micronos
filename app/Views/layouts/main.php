<?php
// ── Load system settings for theme + layout ──────────────────────────────
$_sysCfg   = (new \App\Models\SystemSettingsModel())->getSettings();
$_themes    = config('Appearance');
$_validThemes  = $_themes->ids();
// Themes that render on a light base — these set data-mode="light" so the
// shared light-mode overrides in micronos.css apply to all of them.
$_lightThemes  = $_themes->lightIds();
$_validLayouts = $_themes->layoutIds();
$_appTheme  = $_themes->resolveTheme($_sysCfg['theme'] ?? null);
$_appLayout = $_themes->resolveLayout($_sysCfg['layout'] ?? null);
// Allow URL ?_theme=xxx and ?_layout=xxx for quick previews (session-stored)
if (isset($_GET['_theme']) && in_array($_GET['_theme'], $_validThemes, true)) {
    session()->set('ui_theme', $_GET['_theme']);
}
if (isset($_GET['_layout']) && in_array($_GET['_layout'], $_validLayouts, true)) {
    session()->set('ui_layout', $_GET['_layout']);
}
if (session()->has('ui_theme') && in_array(session()->get('ui_theme'), $_validThemes, true)) {
    $_appTheme = session()->get('ui_theme');
}
if (session()->has('ui_layout') && in_array(session()->get('ui_layout'), $_validLayouts, true)) {
    $_appLayout = session()->get('ui_layout');
}
$_appMode = $_themes->isLight($_appTheme) ? 'light' : 'dark';

// ── Optional sticky device-identification header ─────────────────────────
// Handy when one operator watches several appliances: the bar names the box
// (location / host) and its own address without needing a page of its own.
$_stickyOn = ! empty($_sysCfg['webgui_sticky_header_enabled']);
$_stickyTokens = [
    '{hostname}'    => (string) ($_sysCfg['hostname'] ?? ''),
    '{domain}'      => (string) ($_sysCfg['domain'] ?? ''),
    '{micron_id}'   => (string) ($_sysCfg['micron_id'] ?? ''),
    '{org_name}'    => (string) ($_sysCfg['org_name'] ?? ''),
    '{contact}'     => (string) ($_sysCfg['org_contact_name'] ?? ''),
    '{org_address}' => (string) ($_sysCfg['org_address'] ?? ''),
    '{org_city}'    => (string) ($_sysCfg['org_city'] ?? ''),
    '{org_state}'   => (string) ($_sysCfg['org_state'] ?? ''),
    '{org_country}' => (string) ($_sysCfg['org_country'] ?? ''),
    '{ip}'          => (string) (service('request')->getServer('SERVER_ADDR') ?: gethostname() ?: ''),
];
$_resolveSeg = static function (string $seg) use ($_stickyTokens): string {
    // strtr swaps the tokens, then tidy up whitespace and dangling separators
    // left behind by empty tokens (e.g. "{org_address}, {org_city}").
    $out = strtr($seg, $_stickyTokens);
    $out = preg_replace('/\s+/', ' ', (string) $out);

    return trim((string) $out, " \t\n\r,");
};
$_stickyLeft   = $_stickyOn ? $_resolveSeg((string) ($_sysCfg['webgui_sticky_left'] ?? '')) : '';
$_stickyCenter = $_stickyOn ? $_resolveSeg((string) ($_sysCfg['webgui_sticky_center'] ?? '')) : '';
$_stickyRight  = $_stickyOn ? $_resolveSeg((string) ($_sysCfg['webgui_sticky_right'] ?? '')) : '';
$_stickyShow   = $_stickyOn && ($_stickyLeft !== '' || $_stickyCenter !== '' || $_stickyRight !== '');
// Optional flash so the appliance identity catches the eye across tabs.
$_stickyBlink  = $_stickyShow && ! empty($_sysCfg['webgui_sticky_blink_enabled']);
// Optional colour overrides. Empty = keep the active theme's colours, so the
// bar stays consistent with whatever theme the operator picked. Re-validated
// here (a stale form can submit whatever the stylesheet-bound value was).
$_stickyHex = static fn (string $v): string =>
    preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) ? strtolower($v) : '';
$_stickyBg = $_stickyShow ? $_stickyHex(trim((string) ($_sysCfg['webgui_sticky_bg'] ?? ''))) : '';
$_stickyFg = $_stickyShow ? $_stickyHex(trim((string) ($_sysCfg['webgui_sticky_fg'] ?? ''))) : '';
$_stickyStyle = '';
if ($_stickyBg !== '') { $_stickyStyle .= 'background:' . $_stickyBg . ';'; }
if ($_stickyFg !== '') { $_stickyStyle .= '--dsb-fg:' . $_stickyFg . ';'; }
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($_appTheme) ?>" data-mode="<?= esc($_appMode) ?>" data-layout="<?= esc($_appLayout) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Micron OS — Enterprise Linux Firewall Management">
  <title><?= esc($title ?? 'Micron OS') ?></title>
  <!-- The subset every page needs, started at HTML parse time. Without it the
       typeface is only discovered after micronos.css (render-blocking) has been
       fetched, parsed, and its fonts.css @import fetched in turn — two round
       trips before the woff2 request even begins. The URL, `?v=` included, has
       to match fonts.css exactly or the browser fetches it twice instead of
       reusing the preload (assets/fonts/README.md). -->
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
  <link rel="icon" href="<?= base_url('logos/favicon.ico') ?>" type="image/x-icon">
  <link rel="shortcut icon" href="<?= base_url('logos/favicon.ico') ?>" type="image/x-icon">
  <!-- The shared request scheduler. Loaded synchronously, before any page
       script can start a poller at parse time: every page's polling and AJAX
       reports through one connection state and backs off on one schedule. -->
  <script src="<?= base_url('assets/js/micronos-scheduler.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/micronos-scheduler.js') ?: '1' ?>"></script>
  <!-- The shared table builder, also synchronously and before any page script:
       every live table is drawn through it, so its rows are built from nodes
       rather than assembled as HTML strings, and a wire value can only ever
       reach a cell as text. -->
  <script src="<?= base_url('assets/js/micronos-table.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/micronos-table.js') ?: '1' ?>"></script>
  <!-- Shared shell helpers: confirmation dialog, toasts, and the Ctrl/⌘+K
       command palette (built from this page's own navigation). -->
  <script src="<?= base_url('assets/js/micronos-ui.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/micronos-ui.js') ?: '1' ?>" defer></script>
  <script src="<?= base_url('assets/js/micronos-palette.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/micronos-palette.js') ?: '1' ?>" defer></script>
</head>
<body class="<?= $_stickyShow ? 'has-device-bar' : '' ?>">
<a class="skip-link" href="#pageMain">Skip to main content</a>
  <!-- One connection-lost banner for the whole appliance, painted by the
       scheduler. Present in the markup (rather than built in JS) so it is
       styled by the stylesheet and exists for a page's first failed request. -->
  <div id="mnoConnBanner" class="mno-conn-banner" role="status" aria-live="polite" aria-hidden="true" hidden>
    <span class="mno-conn-dot" aria-hidden="true"></span>
    <span class="mno-conn-text">Connection lost — retrying automatically</span>
  </div>
<?php if ($_stickyShow): ?>
  <!-- Appliance identification — enabled in System Settings → Web GUI -->
  <div class="device-sticky-bar<?= $_stickyBlink ? ' dsb-blink' : '' ?>"<?= $_stickyStyle !== '' ? ' style="' . $_stickyStyle . '"' : '' ?> role="status" aria-label="Appliance identification">
    <span class="dsb-seg left"><?= esc($_stickyLeft) ?></span>
    <span class="dsb-seg center"><?= esc($_stickyCenter) ?></span>
    <span class="dsb-seg right"><?= esc($_stickyRight) ?></span>
  </div>
<?php endif; ?>
<?php
// ── Build nav structure used in BOTH sidebar AND topnav ─────────────────
$_path = current_url(true)->getPath();
// Menu entries contributed by *installed* plugins (`provides.menu`), already
// filtered by the session's permissions.
$_pluginMenu = \App\Libraries\PluginNav::entries();
if (! function_exists('mnIsActive')) {
    function mnIsActive(string $path, string|array $match): string {
        if (is_array($match)) { foreach ($match as $m) { if (str_starts_with($path, $m)) return 'active'; } return ''; }
        return str_starts_with($path, $match) ? 'active' : '';
    }
}
if (! function_exists('mnNavGroupOpen')) {
    /**
     * Opens one sidebar parent: the toggle, then the sub-menu it controls.
     *
     * The parents themselves live in App\Libraries\NavGroups, which is also
     * what the endpoint that remembers unfolded parents validates against: one
     * definition, so the rail and that endpoint cannot disagree about what a
     * parent is.
     *
     * The sub-menu ships with `hidden` and the toggle with `aria-expanded`
     * rather than being closed by the script, so a group is already folded on
     * the first paint instead of flashing its items open. Which parent is open
     * is decided by `NavGroups::opens()` — the one section the operator unfolded
     * in this session, or until they choose, the one owning the current page.
     * The script below reports the choice back, so the rail that greets them on
     * the next page is the one they left behind. Only the parent owning the
     * current page is marked active: being unfolded is not the same as being
     * where you are, and the section they are reading can well be folded.
     */
    function mnNavGroupOpen(string $key, string $path): string
    {
        $group  = \App\Libraries\NavGroups::all()[$key];
        $owns   = \App\Libraries\NavGroups::owns($path, $key);
        $open   = \App\Libraries\NavGroups::opens($key, $path);
        $menuId = 'navGroup-' . $key;
        $class  = 'nav-group-toggle' . ($owns ? ' active' : '');

        return implode("\n", [
            '<div class="nav-group">',
            '        <button type="button" class="' . $class . '" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $menuId . '" data-nav-group="' . $key . '">',
            '          <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' . $group['icon'] . '</svg>',
            '          <span class="nav-group-title">' . esc($group['label']) . '</span>',
            '          <svg class="nav-group-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>',
            '        </button>',
            '        <ul class="nav-group-menu" id="' . $menuId . '"' . ($open ? '' : ' hidden') . '>',
        ]);
    }
}

if (! function_exists('mnPluginLinks')) {
    /**
     * Installed plugins' pages that belong in one functional rail section
     * (PluginNav::SECTIONS). Rendered inside that section's menu so an
     * operator finds, say, LLDP neighbours under Network — the Plugins group
     * still lists every plugin for installation and troubleshooting.
     */
    function mnPluginLinks(string $section, array $menu, string $path, bool $topnav = false): string
    {
        $out = '';
        foreach (\App\Libraries\PluginNav::entriesFor($section, $menu) as $pm) {
            $href   = esc(base_url(ltrim((string) $pm['path'], '/')), 'attr');
            $active = str_starts_with($path, (string) $pm['path']) ? 'active' : '';
            $icon   = '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>';
            $label  = esc((string) $pm['label']);
            $out   .= $topnav
                ? '<a href="' . $href . '" class="topnav-dropdown-item ' . $active . '"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' . $icon . '</svg>' . $label . ' <span class="nav-badge-sm nav-tag-amber">Plugin</span></a>' . "\n"
                : '<li class="nav-item"><a href="' . $href . '" class="nav-link ' . $active . '"><svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' . $icon . '</svg>' . $label . ' <span class="nav-tag nav-tag-amber">Plugin</span></a></li>' . "\n";
        }

        return $out;
    }
}

?>
<div class="app-shell">

  <!-- ========================================================
       TOP NAV BAR (topnav layout only)
  ======================================================== -->
  <nav class="topnav-bar" id="topnavBar">
    <div class="topnav-inner">

      <!-- Logo -->
      <a href="<?= base_url('dashboard') ?>" class="topnav-logo">
        <img src="<?= base_url('logos/logo_wh.png') ?>" alt="Micron OS" style="height:22px; display:none;" class="topnav-logo-text logo-dark" id="topnavLogoText">
        <img src="<?= base_url('logos/logo_bk.png') ?>" alt="Micron OS" style="height:22px; display:none;" class="topnav-logo-text logo-light" id="topnavLogoTextLight">
      </a>

      <!-- Navigation groups -->
      <nav class="topnav-menu" id="topnavMenu">

        <!-- Dashboard (single link, no dropdown) -->
        <a href="<?= base_url('dashboard') ?>" class="topnav-group-btn <?= mnIsActive($_path, '/dashboard') ?>" style="border-bottom:2px solid <?= mnIsActive($_path,'/dashboard') ? 'var(--color-cyan)':'transparent' ?>; text-decoration:none;">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>

        <?php $_canTerm = session('is_superadmin') || in_array('terminal.access', (array) (session('permissions') ?? []), true); ?>
        <?php if ($_canTerm): ?>
        <!-- Terminal / Console (single link) -->
        <a href="<?= base_url('terminal') ?>" class="topnav-group-btn <?= mnIsActive($_path, '/terminal') ?>" aria-label="Terminal" title="Terminal" style="border-bottom:2px solid <?= mnIsActive($_path,'/terminal') ? 'var(--color-cyan)':'transparent' ?>; text-decoration:none;">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </a>
        <?php endif; ?>

        <!-- Network -->
        <div class="topnav-group <?= mnIsActive($_path, ['/interfaces','/dhcp','/dns','/routing','/ha','/pppoe','/multicast','/captive','/services/captive']) ?>">
          <button type="button" class="topnav-group-btn">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></svg>
            Network
            <svg class="topnav-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="topnav-dropdown">
            <div class="topnav-dropdown-label">Connectivity</div>
            <a href="<?= base_url('interfaces') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/interfaces') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></svg>
              Interfaces
            </a>
            <a href="<?= base_url('dhcp') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/dhcp') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/></svg>
              DHCP
            </a>
            <a href="<?= base_url('dhcp/relay') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/dhcp/relay') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
              DHCP Relay
            </a>
            <a href="<?= base_url('dns') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/dns') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
              DNS
            </a>
            <a href="<?= base_url('pppoe') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/pppoe') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M8.464 15.536a5 5 0 010-7.072m7.072 0a5 5 0 010 7.072M12 12h.01"/></svg>
              PPPoE Server
            </a>
            <div class="topnav-divider"></div>
            <div class="topnav-dropdown-label">Routing</div>
            <a href="<?= base_url('routing/static') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/routing/static') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
              Static Routes
            </a>
            <a href="<?= base_url('routing') ?>" class="topnav-dropdown-item <?= (($_path === '/routing' || str_starts_with($_path, '/routing')) && $_path !== '/routing/static' && !str_starts_with($_path, '/routing/sdwan')) ? 'active' : '' ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
              Dynamic Routing <span class="nav-badge-sm nav-tag-purple">FRR</span>
            </a>
            <a href="<?= base_url('ha') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/ha') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              High Availability <span class="nav-badge-sm nav-tag-amber">VRRP</span>
            </a>
            <a href="<?= base_url('routing/sdwan') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/routing/sdwan') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6M3 17l4-4 4 4 6-6"/></svg>
              SD-WAN SLA Steering <span class="nav-badge-sm nav-tag-indigo">SLA</span>
            </a>
            <a href="<?= base_url('multicast') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/multicast') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
              Multicast &amp; IGMP <span class="nav-badge-sm nav-tag-cyan">IPTV</span>
            </a>
            <a href="<?= base_url('captive') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,['/captive','/services/captive']) ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
              Captive Portal <span class="nav-badge-sm nav-tag-amber">Hotspot</span>
            </a>
            <?= mnPluginLinks('network', $_pluginMenu, $_path, true) ?>
            <?= mnPluginLinks('routing', $_pluginMenu, $_path, true) ?>
          </div>
        </div>

        <!-- Policy & Objects -->
        <div class="topnav-group <?= mnIsActive($_path,['/firewall','/qos','/nat']) ?>">
          <button type="button" class="topnav-group-btn">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Policy
            <svg class="topnav-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="topnav-dropdown">
            <div class="topnav-dropdown-label">Firewall</div>
            <a href="<?= base_url('firewall/rules') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/rules') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              Firewall Rules
            </a>
            <a href="<?= base_url('firewall/dos') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/dos') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              DoS Policies <span class="nav-badge-sm nav-tag-red">DDoS</span>
            </a>
            <a href="<?= base_url('firewall/addresses') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/addresses') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
              Addresses
            </a>
            <a href="<?= base_url('firewall/address-groups') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/address-groups') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
              Address Groups
            </a>
            <a href="<?= base_url('firewall/schedules') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/schedules') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>
              Schedules
            </a>
            <a href="<?= base_url('firewall/internet-services') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/internet-services') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
              Internet Services
            </a>
            <a href="<?= base_url('firewall/services') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/services') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
              Services / Ports
            </a>
            <a href="<?= base_url('firewall/service-groups') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/firewall/service-groups') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
              Service Groups
            </a>
            <div class="topnav-divider"></div>
            <a href="<?= base_url('nat') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/nat') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
              NAT <span class="nav-badge-sm nav-tag-cyan">DNAT/SNAT</span>
            </a>
            <a href="<?= base_url('qos') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/qos') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
              Traffic Shaper <span class="nav-badge-sm nav-tag-cyan">CAKE</span>
            </a>
          </div>
        </div>

        <!-- VPN -->
        <div class="topnav-group <?= mnIsActive($_path,['/vpn']) ?>">
          <button type="button" class="topnav-group-btn">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            VPN
            <svg class="topnav-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="topnav-dropdown">
            <a href="<?= base_url('vpn/wireguard') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/vpn/wireguard') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              WireGuard
            </a>
            <a href="<?= base_url('vpn/openvpn') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/vpn/openvpn') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              OpenVPN
            </a>
            <a href="<?= base_url('vpn/ipsec') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/vpn/ipsec') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
              IPsec / IKEv2
            </a>
            <a href="<?= base_url('vpn/portal') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/vpn/portal') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Road Warrior Portal <span class="nav-badge-sm nav-tag-emerald">Portal</span>
            </a>
            <a href="<?= base_url('vpn/clientless-gateway') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/vpn/clientless-gateway') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
              Clientless Gateway <span class="nav-badge-sm nav-tag-indigo">ZTNA</span>
            </a>
            <?= mnPluginLinks('vpn', $_pluginMenu, $_path, true) ?>
          </div>
        </div>

        <!-- Security -->
        <div class="topnav-group <?= mnIsActive($_path,['/webfilter','/ids','/appid','/certificates','/siem']) ?>">
          <button type="button" class="topnav-group-btn">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Security
            <svg class="topnav-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="topnav-dropdown">
            <a href="<?= base_url('webfilter') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/webfilter') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064"/></svg>
              Web Filter &amp; Proxy <span class="nav-badge-sm nav-tag-cyan">WPAD</span>
            </a>
            <a href="<?= base_url('ids') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/ids') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              IDS/IPS
            </a>
            <a href="<?= base_url('appid') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/appid') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
              App Control (AppID) <span class="nav-badge-sm nav-tag-indigo">nDPI</span>
            </a>
            <a href="<?= base_url('certificates') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/certificates') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              Certificates & PKI <span class="nav-badge-sm nav-tag-purple">X.509</span>
            </a>
            <a href="<?= base_url('siem') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/siem') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
              SIEM Forwarding <span class="nav-badge-sm nav-tag-cyan">SOC</span>
            </a>
            <a href="<?= base_url('ssl-inspection') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/ssl-inspection') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              SSL/TLS Inspection <span class="nav-badge-sm nav-tag-amber">MITM CA</span>
            </a>
            <a href="<?= base_url('security/threat-intel') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/security/threat-intel') ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              Threat Intel Feeds <span class="nav-badge-sm nav-tag-rose">IoC</span>
            </a>
            <a href="<?= base_url('security/cve') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,['/security/cve', '/cve']) ?>">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              CVEs Security Advisories <span class="nav-badge-sm nav-tag-amber">NVD</span>
            </a>
            <?= mnPluginLinks('security', $_pluginMenu, $_path, true) ?>
          </div>
        </div>

        <!-- Admin -->
        <div class="topnav-group <?= mnIsActive($_path,['/users','/roles','/backup','/diag','/services','/snmp','/cron','/system']) ?>">
          <button type="button" class="topnav-group-btn">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Admin
            <svg class="topnav-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="topnav-dropdown" style="min-width:200px;">
            <div class="topnav-dropdown-label">Identity</div>
            <a href="<?= base_url('users') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/users') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>Users</a>
            <a href="<?= base_url('roles') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/roles') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>Roles & Permissions</a>
            <a href="<?= base_url('system/sso') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/system/sso') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>Single Sign-On (SSO) <span class="nav-badge-sm nav-tag-cyan">OIDC/SAML</span></a>
            <div class="topnav-divider"></div>
            <div class="topnav-dropdown-label">Operations</div>
            <a href="<?= base_url('system/revisions') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/system/revisions') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Config Revisions <span class="nav-badge-sm nav-tag-purple">Git</span></a>
            <a href="<?= base_url('backup') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/backup') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>Backup & Restore</a>
            <a href="<?= base_url('diag') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/diag') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Diagnostics</a>
            <a href="<?= base_url('diag/logs') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/diag/logs') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Live Logs <span class="nav-badge-sm nav-tag-emerald">Live</span></a>
            <a href="<?= base_url('services') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/services') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Services Status</a>
            <a href="<?= base_url('plugins') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/plugins') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Plugins &amp; Packages</a>
            <a href="<?= base_url('snmp') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/snmp') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>SNMP Agent</a>
            <a href="<?= base_url('cron') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/cron') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Cron Jobs</a>
            <div class="topnav-divider"></div>
            <a href="<?= base_url('system/updates') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/system/updates') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>System Updates</a>
            <a href="<?= base_url('system') ?>" class="topnav-dropdown-item <?= $_path === '/system' ? 'active' : '' ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>System Settings</a>
            <a href="<?= base_url('docs') ?>" class="topnav-dropdown-item <?= mnIsActive($_path,'/docs') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Documentation</a>
            <?= mnPluginLinks('admin', $_pluginMenu, $_path, true) ?>
          </div>
        </div>

        <!-- Plugins (extension surface) -->
        <div class="topnav-group <?= mnIsActive($_path, '/plugins') ?>">
          <button type="button" class="topnav-group-btn">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            Plugins
            <svg class="topnav-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </button>
          <div class="topnav-dropdown" style="min-width:220px;">
            <div class="topnav-dropdown-label">Manager</div>
            <a href="<?= base_url('plugins') ?>" class="topnav-dropdown-item <?= $_path === '/plugins' ? 'active' : '' ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Plugins &amp; Packages</a>
            <a href="<?= base_url('plugins/updates') ?>" class="topnav-dropdown-item <?= mnIsActive($_path, '/plugins/updates') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Updates &amp; Upgrades</a>
            <a href="<?= base_url('plugins/repositories') ?>" class="topnav-dropdown-item <?= mnIsActive($_path, '/plugins/repositories') ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6c0-1.657 3.582-3 8-3s8 1.343 8 3-3.582 3-8 3-8-1.343-8-3zm0 0v12c0 1.657 3.582 3 8 3s8-1.343 8-3V6M4 12c0 1.657 3.582 3 8 3s8-1.343 8-3"/></svg>Repositories</a>
            <?php if ($_pluginMenu !== []): ?>
              <div class="topnav-divider"></div>
              <div class="topnav-dropdown-label">Installed plugins</div>
              <?php foreach ($_pluginMenu as $__pm): ?>
                <a href="<?= base_url(ltrim((string) $__pm['path'], '/')) ?>" class="topnav-dropdown-item <?= mnIsActive($_path, (string) $__pm['path']) ?>"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg><?= esc((string) $__pm['label']) ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </nav><!-- /topnav-menu -->

      <!-- Right side -->
      <div class="topnav-right">
        <!-- Status -->
        <div class="status-pill online" style="font-size:0.7rem; padding:3px 10px;">
          <span class="status-dot"></span> WAN
        </div>
        <div class="status-pill online" style="font-size:0.7rem; padding:3px 10px;">
          <span class="status-dot"></span> FW
        </div>

        <!-- Commit watchdog -->
        <button type="button" class="topnav-commit-btn" onclick="triggerCommitWatchdog()" title="Commit Watchdog">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Commit
        </button>


        <!-- User -->
        <a href="<?= base_url('profile') ?>" class="topnav-user">
          <div class="sidebar-avatar"><?= strtoupper(substr(session('username') ?? 'U', 0, 1)) ?></div>
          <span><?= esc(session('username') ?? 'User') ?></span>
        </a>

        <!-- Logout -->
        <a href="<?= base_url('auth/logout') ?>" class="topnav-icon-btn" title="Sign out">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        </a>
      </div>

    </div>
  </nav><!-- /topnav-bar -->

  <!-- ========================================================
       SIDEBAR
  ======================================================== -->
  <nav class="sidebar" id="sidebar">

    <!-- Logo -->
    <a href="<?= base_url('dashboard') ?>" class="sidebar-logo" style="text-decoration:none;">
      <img src="<?= base_url('logos/logo_orig.png') ?>" alt="Micron" class="sidebar-logo-mark" />
      <img src="<?= base_url('logos/logo_wh.png') ?>" alt="Micron Infosec &amp; IT Solutions" class="sidebar-logo-full logo-dark" />
      <img src="<?= base_url('logos/logo_bk.png') ?>" alt="Micron Infosec &amp; IT Solutions" class="sidebar-logo-full logo-light" />
    </a>

    <!-- Navigation -->
    <div class="sidebar-nav">

      <!-- Dashboard -->
      <ul style="list-style:none;">
        <li class="nav-item">
          <a href="<?= base_url('dashboard') ?>" class="nav-link <?= (current_url(true)->getPath() === '/dashboard') ? 'active' : '' ?>">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
              <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Dashboard
          </a>
        </li>
        <?php if (session('is_superadmin') || in_array('terminal.access', (array) (session('permissions') ?? []), true)): ?>
        <li class="nav-item">
          <a href="<?= base_url('terminal') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/terminal') ? 'active' : '' ?>">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            Terminal
          </a>
        </li>
        <?php endif; ?>
      </ul>

      <!-- Network -->
      <?= mnNavGroupOpen('network', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('interfaces') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/interfaces') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
              </svg>
              Interfaces
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('multicast') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/multicast') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
              </svg>
              Multicast &amp; IGMP
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('captive') ?>" class="nav-link <?= (str_starts_with(current_url(true)->getPath(), '/captive') || str_starts_with(current_url(true)->getPath(), '/services/captive')) ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
              </svg>
              Captive Portal
              <span class="nav-tag nav-tag-amber">Hotspot</span>
            </a>
          </li>
          <?= mnPluginLinks('network', $_pluginMenu, $_path) ?>
        </ul>
      </div>

      <!-- Policy & Objects -->
      <?= mnNavGroupOpen('policy', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('firewall/rules') ?>" class="nav-link <?= (current_url(true)->getPath() === '/firewall/rules' || current_url(true)->getPath() === '/firewall') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              Firewall Rules
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/dos') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/dos') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
              DoS Policies
              <span class="nav-tag nav-tag-red">DDoS</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/addresses') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/addresses') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
              </svg>
              Addresses
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/address-groups') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/address-groups') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
              </svg>
              Address Groups
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/schedules') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/schedules') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
              </svg>
              Schedules
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/internet-services') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/internet-services') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
              </svg>
              Internet Services
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/services') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/services') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
              </svg>
              Services / Ports
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('firewall/service-groups') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/firewall/service-groups') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              Service Groups
            </a>
          </li>
          <!-- Network Address Translation -->
          <li class="nav-item">
            <a href="<?= base_url('nat') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/nat') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
              </svg>
              NAT
              <span class="nav-tag nav-tag-cyan">DNAT/SNAT</span>
            </a>
          </li>
          <!-- Traffic Shaper (CAKE / FQ-CoDel) -->
          <li class="nav-item">
            <a href="<?= base_url('qos') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/qos') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
              </svg>
              Traffic Shaper
              <span class="nav-tag nav-tag-cyan">CAKE</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- Routing & Clustering -->
      <?= mnNavGroupOpen('routing', $_path) ?>
          <!-- High Availability (Keepalived & conntrackd) -->
          <li class="nav-item">
            <a href="<?= base_url('ha') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/ha') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              High Availability
              <span class="nav-tag nav-tag-amber">VRRP</span>
            </a>
          </li>
          <!-- Static Routes -->
          <li class="nav-item">
            <a href="<?= base_url('routing/static') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/routing/static') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
              </svg>
              Static Routes
            </a>
          </li>
          <!-- Dynamic Routing (FRR) -->
          <li class="nav-item">
            <a href="<?= base_url('routing') ?>" class="nav-link <?= (str_starts_with(current_url(true)->getPath(), '/routing') && !str_starts_with(current_url(true)->getPath(), '/routing/static') && !str_starts_with(current_url(true)->getPath(), '/routing/sdwan')) ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
              </svg>
              Dynamic Routing
              <span class="nav-tag nav-tag-purple">FRR</span>
            </a>
          </li>
          <!-- VRF & PBR -->
          <li class="nav-item">
            <a href="<?= base_url('routing') ?>?tab=vrf" class="nav-link <?= (str_starts_with(current_url(true)->getPath(), '/routing') && in_array($_GET['tab'] ?? '', ['vrf','pbr'])) ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              VRF &amp; PBR
            </a>
          </li>
          <!-- Multi-WAN -->
          <li class="nav-item">
            <a href="<?= base_url('routing') ?>?tab=multiwan" class="nav-link <?= (str_starts_with(current_url(true)->getPath(), '/routing') && ($_GET['tab'] ?? '') === 'multiwan') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
              </svg>
              Multi-WAN
            </a>
          </li>
          <!-- Dynamic SD-WAN SLA Steering -->
          <li class="nav-item">
            <a href="<?= base_url('routing/sdwan') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/routing/sdwan') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6M3 17l4-4 4 4 6-6"/>
              </svg>
              SD-WAN SLA Steering
              <span class="nav-tag nav-tag-indigo">SD-WAN</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('pppoe') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/pppoe') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728M8.464 15.536a5 5 0 010-7.072m7.072 0a5 5 0 010 7.072M12 12h.01"/>
              </svg>
              PPPoE Server
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('dhcp') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/dhcp') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-4-2v8"/>
              </svg>
              DHCP
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('dhcp/relay') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/dhcp/relay') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
              </svg>
              DHCP Relay
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('dns') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/dns') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
              </svg>
              DNS
            </a>
          </li>
          <?= mnPluginLinks('routing', $_pluginMenu, $_path) ?>
        </ul>
      </div>

      <!-- VPN -->
      <?= mnNavGroupOpen('vpn', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('vpn/wireguard') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/vpn/wireguard') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
              WireGuard
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('vpn/openvpn') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/vpn/openvpn') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              OpenVPN
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('vpn/ipsec') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/vpn/ipsec') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
              </svg>
              IPsec / IKEv2
            </a>
          </li>
          <!-- Road Warrior User Self-Service Portal -->
          <li class="nav-item">
            <a href="<?= base_url('vpn/portal') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/vpn/portal') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Road Warrior Portal
              <span class="nav-tag nav-tag-emerald">Portal</span>
            </a>
          </li>
          <!-- Clientless Gateway (ZTNA) -->
          <li class="nav-item">
            <a href="<?= base_url('vpn/clientless-gateway') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/vpn/clientless-gateway') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
              </svg>
              Clientless Gateway
              <span class="nav-tag nav-tag-indigo">ZTNA</span>
            </a>
          </li>
          <?= mnPluginLinks('vpn', $_pluginMenu, $_path) ?>
        </ul>
      </div>

      <!-- Security -->
      <?= mnNavGroupOpen('security', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('webfilter') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/webfilter') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
              </svg>
              Web Filter &amp; Proxy
              <span class="nav-tag nav-tag-cyan">WPAD</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('ids') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/ids') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
              IDS/IPS
            </a>
          </li>
          <!-- Application Identification (AppID) & Layer 7 DPI -->
          <li class="nav-item">
            <a href="<?= base_url('appid') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/appid') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
              </svg>
              App Control (AppID)
              <span class="nav-tag nav-tag-indigo">nDPI</span>
            </a>
          </li>
          <!-- Certificates & PKI -->
          <li class="nav-item">
            <a href="<?= base_url('certificates') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/certificates') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              Certificates &amp; PKI
              <span class="nav-tag nav-tag-purple">X.509</span>
            </a>
          </li>
          <!-- SIEM Integration -->
          <li class="nav-item">
            <a href="<?= base_url('siem') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/siem') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
              </svg>
              SIEM Forwarding
              <span class="nav-tag nav-tag-cyan">SOC</span>
            </a>
          </li>
          <!-- SSL/TLS Inspection -->
          <li class="nav-item">
            <a href="<?= base_url('ssl-inspection') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/ssl-inspection') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
              SSL/TLS Inspection
              <span class="nav-tag nav-tag-amber">MITM</span>
            </a>
          </li>
          <!-- Threat Intelligence Feeds -->
          <li class="nav-item">
            <a href="<?= base_url('security/threat-intel') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/security/threat-intel') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
              Threat Intel Feeds
              <span class="nav-tag nav-tag-rose">IoC</span>
            </a>
          </li>
          <!-- CVEs Security Advisories -->
          <li class="nav-item">
            <a href="<?= base_url('security/cve') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/security/cve') || str_starts_with(current_url(true)->getPath(), '/cve') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              CVEs Security Advisories
              <span class="nav-tag nav-tag-amber">NVD</span>
            </a>
          </li>
          <?= mnPluginLinks('security', $_pluginMenu, $_path) ?>
        </ul>
      </div>

      <!-- Identity -->
      <?= mnNavGroupOpen('identity', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('users') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/users') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/>
              </svg>
              Users
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('roles') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/roles') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
              </svg>
              Roles & Permissions
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('system/sso') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/system/sso') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
              Single Sign-On (SSO)
              <span class="nav-tag nav-tag-cyan">OIDC/SAML</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- Administration -->
      <?= mnNavGroupOpen('admin', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('system/revisions') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/system/revisions') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Config Revisions
              <span class="nav-tag nav-tag-purple">Git</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('backup') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/backup') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
              </svg>
              Backup & Restore
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('diag') ?>" class="nav-link <?= current_url(true)->getPath() === '/diag' ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
              </svg>
              Diagnostics Tools
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('diag/logs') ?>" class="nav-link <?= current_url(true)->getPath() === '/diag/logs' ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Live Firewall Logs
              <span class="nav-tag nav-tag-emerald">Live</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('diag/states') ?>" class="nav-link <?= current_url(true)->getPath() === '/diag/states' ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
              </svg>
              States Inspector
              <span class="nav-tag nav-tag-cyan">States</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('services') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/services') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              Services Status
              <span class="nav-tag nav-tag-emerald">Daemons</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('snmp') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/snmp') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
              </svg>
              SNMP Agent
              <span class="nav-tag nav-tag-cyan">v2/v3</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('cron') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/cron') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
              </svg>
              Cron Jobs
              <span class="nav-tag nav-tag-purple">Tasks</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('system/updates') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/system/updates') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              System Updates
              <span class="nav-tag nav-tag-cyan">Update</span>
            </a>
          </li>
          <li class="nav-item">            <a href="<?= base_url('system') ?>" class="nav-link <?= current_url(true)->getPath() === '/system' ? 'active' : '' ?>">
                <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                </svg>
                System
              </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('docs') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/docs') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
              </svg>
              Documentation
              <span class="nav-tag nav-tag-cyan">Guides</span>
            </a>
          </li>
          <?= mnPluginLinks('admin', $_pluginMenu, $_path) ?>
        </ul>
      </div>

      <!-- Plugins -->
      <?= mnNavGroupOpen('plugins', $_path) ?>
          <li class="nav-item">
            <a href="<?= base_url('plugins') ?>" class="nav-link <?= current_url(true)->getPath() === '/plugins' ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              Plugins &amp; Packages
              <span class="nav-tag nav-tag-amber">Extend</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('plugins/updates') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/plugins/updates') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              Updates &amp; Upgrades
              <span class="nav-tag nav-tag-cyan">Updates</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="<?= base_url('plugins/repositories') ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), '/plugins/repositories') ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6c0-1.657 3.582-3 8-3s8 1.343 8 3-3.582 3-8 3-8-1.343-8-3zm0 0v12c0 1.657 3.582 3 8 3s8-1.343 8-3V6M4 12c0 1.657 3.582 3 8 3s8-1.343 8-3"/>
              </svg>
              Repositories
              <span class="nav-tag nav-tag-cyan">Signed</span>
            </a>
          </li>
          <?php foreach ($_pluginMenu as $__pm): ?>
          <li class="nav-item">
            <a href="<?= base_url(ltrim((string) $__pm['path'], '/')) ?>" class="nav-link <?= str_starts_with(current_url(true)->getPath(), (string) $__pm['path']) ? 'active' : '' ?>">
              <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              <?= esc((string) $__pm['label']) ?>
              <span class="nav-tag nav-tag-amber">Plugin</span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

    </div><!-- /sidebar-nav -->

    <!-- User footer -->
    <div class="sidebar-footer">
      <a href="<?= base_url('profile') ?>" class="sidebar-user" style="text-decoration:none;">
        <div class="sidebar-avatar">
          <?= strtoupper(substr(session('username') ?? 'U', 0, 1)) ?>
        </div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name"><?= esc(session('username') ?? 'User') ?></div>
          <div class="sidebar-user-role"><?= esc(session('user_role') ?? 'Operator') ?></div>
        </div>
      </a>
    </div>

  </nav><!-- /sidebar -->

  <!-- ========================================================
       MAIN CONTENT
  ======================================================== -->
  <div class="main-content" id="mainContent">

    <!-- Top Bar -->
    <header class="topbar">
      <button type="button" class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <div class="topbar-breadcrumb">
        <img src="<?= base_url('logos/logo_orig.png') ?>" alt="Micron" style="height:24px; width:24px; object-fit:contain; border-radius:4px;" />
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <strong><?= esc($breadcrumb ?? ($title ?? 'Dashboard')) ?></strong>
      </div>

      <div class="topbar-spacer"></div>

      <!-- Transactional commit control — staged config with an auto-revert watchdog -->
      <div class="d-flex align-items-center me-3 topbar-commit-group">
        <button type="button" class="btn btn-sm btn-outline-warning" id="topbarCommitBtn" onclick="triggerCommitWatchdog()" title="Apply the staged configuration with a 60-second auto-revert watchdog">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="me-1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Commit Watchdog
        </button>
      </div>

      <div class="topbar-badge-group">
        <a href="<?= base_url('system/revisions') ?>" class="topbar-version-pill" title="Current Version & Channel">
          <span>v<?= esc(\App\Libraries\AppVersion::getVersion()) ?></span>
          <span class="badge <?= \App\Libraries\AppVersion::isDevelopment() ? 'badge-warning' : (\App\Libraries\AppVersion::isReleaseCandidate() ? 'badge-info' : 'badge-success') ?> topbar-version-badge">
            <?= esc(\App\Libraries\AppVersion::getChannel()) ?>
          </span>
        </a>
        <div class="status-pill online">
          <span class="status-dot"></span>
          WAN
        </div>
        <div class="status-pill online">
          <span class="status-dot"></span>
          FW Active
        </div>
      </div>

      <a href="<?= base_url('auth/logout') ?>" class="topbar-icon-btn" title="Sign out">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
      </a>
    </header>

    <!-- Commit watchdog active banner -->
    <div id="commitWatchdogBanner" style="display:none; margin: 16px 28px 0; background: linear-gradient(90deg, rgba(234, 179, 8, 0.15), rgba(239, 68, 68, 0.2)); border: 1px solid rgba(234, 179, 8, 0.4); border-radius: 8px; padding: 12px 18px;" class="animate-in">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center">
          <span class="badge bg-warning text-dark me-2 font-monospace fs-6"><i class="bi bi-clock-history"></i> <span id="commitTimer">60</span>s</span>
          <div>
            <strong class="text-warning">Commit Watchdog Active:</strong>
            <span class="text-light small ms-1">Changes are live in candidate staging. Automatically rolling back if unconfirmed.</span>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-success fw-bold" onclick="confirmPermanentCommit()">
            <i class="bi bi-check-lg me-1"></i> Confirm Permanent
          </button>
          <button type="button" class="btn btn-sm btn-danger" onclick="rollbackCommit()">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Rollback Now
          </button>
        </div>
      </div>
    </div>

    <!-- Flash Messages (escaped; see partials/flash.php) -->
    <div style="padding: 16px 28px 0;">
      <?= view('partials/flash') ?>
    </div>

    <!-- Page Content -->
    <main class="page-content" id="pageMain" tabindex="-1">
      <?= $this->renderSection('content') ?>
    </main>

    <?= view('partials/app_footer', ['footerVariant' => 'app']) ?>
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<script>
// Sidebar toggle & collapsed persistence
const sidebar     = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const toggle      = document.getElementById('sidebarToggle');
let collapsed     = false;

// Below 768px the rail lives off-canvas (media query), so the hamburger must
// open it as a DRAWER instead of collapsing a rail that is already hidden. The
// topnav layout reaches that state earlier: from 1200px down its own bar gives
// way to this compact header, and the rail it hides is what the hamburger then
// has to open — the same drawer, just on a wider screen. The content margin is
// written to a custom property rather than an inline
// style so the responsive `margin-left: 0` rule can still override it.
const mobileRail = () => window.matchMedia('(max-width: 768px)').matches
  || (document.documentElement.dataset.layout === 'topnav'
      && window.matchMedia('(max-width: 1200px)').matches);

function applySidebarState(isCollapsed) {
  collapsed = isCollapsed;
  if (sidebar) sidebar.classList.toggle('collapsed', isCollapsed);
  document.documentElement.style.setProperty('--main-margin',
    isCollapsed ? 'var(--sidebar-collapsed)' : 'var(--sidebar-width)');
}

function restoreRailPreference() {
  try {
    if (localStorage.getItem('micron-sidebar-collapsed') === '1') applySidebarState(true);
  } catch(e) {}
}

// Populate tooltips on nav links so hovering in collapsed view shows link title
document.querySelectorAll('.sidebar-nav .nav-link, .sidebar-nav .nav-group-toggle').forEach(el => {
  if (el.hasAttribute('title')) return;
  // A group's own label lives in its title span, which the generic sweep below
  // would strip along with the badge spans.
  const own = el.querySelector('.nav-group-title');
  const clone = el.cloneNode(true);
  clone.querySelectorAll('span, svg').forEach(part => part.remove());
  const label = (own ? own.textContent : clone.textContent).trim();
  if (label) el.setAttribute('title', label);
});

// Sidebar parents: the rail is an accordion, so unfolding one folds every other
// and no more than one section is ever open. The markup ships the state (`hidden`
// on the menu, `aria-expanded` on the button) — this only has to keep the two in
// step, so a group is correct before any of this runs.
const navToggles = document.querySelectorAll('.sidebar-nav .nav-group-toggle');

function foldNavGroup(btn, open) {
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');

  const menu = document.getElementById(btn.getAttribute('aria-controls'));
  if (menu) menu.hidden = !open;
}

navToggles.forEach(btn => {
  btn.addEventListener('click', () => {
    const open = btn.getAttribute('aria-expanded') === 'true';

    // Every toggle is settled on every click, not just the one clicked: opening
    // a parent is also the act of closing the others, and clicking the open one
    // leaves the rail with nothing unfolded at all.
    navToggles.forEach(other => foldNavGroup(other, !open && other === btn));

    rememberNavGroup(open ? '' : btn.dataset.navGroup || '');
  });
});

// The operator's choice travels back so the next page renders the rail they left
// behind, rather than one the current URL happens to suggest. An empty answer is
// a real one — it says they folded the last section away — and the report is
// fire-and-forget: a rail that cannot be remembered is still a working rail.
function rememberNavGroup(group) {
  const body = new FormData();
  if (group) body.append('groups[]', group);
  body.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('system/nav-groups') ?>', { method: 'POST', body })
    .catch(() => {});
}

if (toggle) {
  toggle.addEventListener('click', () => {
    if (mobileRail()) {
      const open = sidebar.classList.toggle('mobile-open');
      if (open) {
        sidebar.classList.remove('collapsed'); // drawer shows full labels
      } else {
        restoreRailPreference();               // back to the stored rail state
      }
      return;
    }
    const nextState = !collapsed;
    try { localStorage.setItem('micron-sidebar-collapsed', nextState ? '1' : '0'); } catch(e) {}
    applySidebarState(nextState);
  });
}

// Tapping outside the open drawer closes it
if (sidebar && toggle) {
  document.addEventListener('click', (e) => {
    if (!sidebar.classList.contains('mobile-open')) return;
    if (sidebar.contains(e.target) || toggle.contains(e.target)) return;
    sidebar.classList.remove('mobile-open');
    restoreRailPreference();
  });
}

// Restore saved collapse state if previously collapsed
restoreRailPreference();

// Auto-dismiss flash messages (transient banners only — anything marked
// data-persist stays until the page or its own code removes it, e.g. the
// inline notices that explain why an action could not proceed)
document.querySelectorAll('.alert').forEach(el => {
  if (el.id === 'commitWatchdogBanner') return;
  if (el.hasAttribute('data-persist')) return;
  setTimeout(() => {
    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    el.style.opacity = '0';
    el.style.transform = 'translateY(-8px)';
    setTimeout(() => el.remove(), 500);
  }, 5000);
});

// Transactional commit safety watchdog
let commitTimerInterval = null;
function checkCommitStatus() {
  MicronScheduler.fetch('<?= base_url('system/commit/status') ?>')
    .then(r => r.json())
    .then(data => {
      const banner = document.getElementById('commitWatchdogBanner');
      const timerEl = document.getElementById('commitTimer');
      if (data.active && data.remaining_sec > 0) {
        banner.style.display = 'block';
        let rem = data.remaining_sec;
        timerEl.innerText = rem;
        clearInterval(commitTimerInterval);
        commitTimerInterval = setInterval(() => {
          rem--;
          if (rem <= 0) {
            clearInterval(commitTimerInterval);
            banner.style.display = 'none';
            alert('Watchdog timer expired! Configuration automatically rolled back to safeguard remote connectivity.');
            location.reload();
          } else {
            timerEl.innerText = rem;
          }
        }, 1000);
      } else {
        banner.style.display = 'none';
        clearInterval(commitTimerInterval);
      }
    })
    .catch(() => {});
}

function triggerCommitWatchdog() {
  if (!confirm('Apply the staged configuration with a 60-second watchdog? If connectivity fails, changes auto-revert.')) return;
  MicronScheduler.fetch('<?= base_url('system/commit/watchdog') ?>', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      checkCommitStatus();
    });
}

function confirmPermanentCommit() {
  MicronScheduler.fetch('<?= base_url('system/commit/permanent') ?>', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      alert('Configuration permanently confirmed!');
      document.getElementById('commitWatchdogBanner').style.display = 'none';
      clearInterval(commitTimerInterval);
    });
}

function rollbackCommit() {
  if (!confirm('Rollback immediately to previous configuration snapshot?')) return;
  MicronScheduler.fetch('<?= base_url('system/commit/rollback') ?>', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      alert('Configuration rolled back!');
      location.reload();
    });
}

// Check status on page load
document.addEventListener('DOMContentLoaded', checkCommitStatus);
</script>

<!-- ============================================================
     THEME & LAYOUT SWITCHER JAVASCRIPT
============================================================ -->
<script>
/* ── State ─────────────────────────────────────────────────── */
const _html         = document.documentElement;
let   _pendingTheme  = _html.dataset.theme  || '<?= esc($_themes->defaultTheme, 'js') ?>';
let   _pendingLayout = _html.dataset.layout || '<?= esc($_themes->defaultLayout, 'js') ?>';
/* Themes that render on a light base (source: Config\Appearance) */
const LIGHT_THEMES = <?= json_encode(array_values($_lightThemes)) ?>;
const ALL_THEMES   = <?= json_encode(array_values($_validThemes)) ?>;

/* ── Open / Close Popover ───────────────────────────────────── */
function toggleThemeSwitcher() {
    const pop = document.getElementById('themeSwitcherPopover');
    if (!pop) return;
    const isOpen = pop.classList.toggle('open');
    if (isOpen) {
        _pendingTheme  = _html.dataset.theme  || '<?= esc($_themes->defaultTheme, 'js') ?>';
        _pendingLayout = _html.dataset.layout || '<?= esc($_themes->defaultLayout, 'js') ?>';
        syncSwatches();
        // Close if user clicks outside
        setTimeout(() => {
            document.addEventListener('click', closeSwitcherOutside, { once: true });
        }, 50);
    }
}

function closeSwitcherOutside(e) {
    const pop = document.getElementById('themeSwitcherPopover');
    if (pop && !pop.contains(e.target) &&
        !e.target.closest('#themeSwitcherBtn') &&
        !e.target.closest('#themeSwitcherBtnTopbar')) {
        pop.classList.remove('open');
    }
}

/* ── Apply Theme (live preview) ─────────────────────────────── */
function applyTheme(theme) {
    _pendingTheme = theme;
    _html.dataset.theme = theme;
    // data-mode switches the shared light/dark override sets in micronos.css
    _html.dataset.mode = LIGHT_THEMES.includes(theme) ? 'light' : 'dark';
    syncSwatches();
}

/* ── Apply Layout (live) ─────────────────────────────────────── */
function applyLayout(layout) {
    _pendingLayout = layout;
    _html.dataset.layout = layout;
    syncSwatches();
}

/* ── Sync active states in popover ────────────────────────────
   The popover partial is rendered twice (topnav bar + sidebar topbar),
   so sync every instance via data attributes instead of ids. */
function syncSwatches() {
    ALL_THEMES.forEach(t => {
        document.querySelectorAll('.theme-swatch[data-theme-id="' + t + '"]')
            .forEach(el => el.classList.toggle('active', _html.dataset.theme === t));
    });
    ['sidebar','topnav'].forEach(l => {
        document.querySelectorAll('.layout-card[data-layout-id="' + l + '"]')
            .forEach(el => el.classList.toggle('active', _html.dataset.layout === l));
    });
}

/* ── Save to DB (AJAX POST → ?_saveTheme=1) ─────────────────── */
function saveAppearanceToDB() {
    const btn   = document.getElementById('saveAppearanceBtn');
    const orig  = btn.innerHTML;
    btn.innerHTML = '<svg class="spin" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving…';
    btn.disabled = true;

    MicronScheduler.fetch('<?= base_url('system/saveTheme') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ theme: _html.dataset.theme, layout: _html.dataset.layout })
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Saved!';
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.disabled = false;
        }, 2000);
    })
    .catch(() => {
        btn.innerHTML = '⚠ Failed';
        setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 2000);
    });
}

/* ── Init swatches on page load ─────────────────────────────── */
document.addEventListener('DOMContentLoaded', syncSwatches);
</script>

<script>
/* ── Compact table mode ─────────────────────────────────────────
   The wide policy and NAT tables stack into readable label/value
   cards instead of scrolling sideways. Auto-applies on viewports
   up to 1100px; any page header button marked data-compact-toggle
   lets the user override, remembered in localStorage. ─────────── */
(function () {
  var KEY = 'micron-table-compact';
  var WIDTH = 1100;

  function tables() {
    return document.querySelectorAll('table.policy-table, table.rt-table');
  }
  function effective() {
    var pref = localStorage.getItem(KEY);
    if (pref === '1') return true;
    if (pref === '0') return false;
    return window.innerWidth <= WIDTH;
  }
  function apply() {
    var compact = effective();
    tables().forEach(function (t) { t.classList.toggle('table-compact', compact); });
    document.querySelectorAll('[data-compact-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', compact ? 'true' : 'false');
      var lbl = btn.querySelector('[data-compact-label]');
      if (lbl) lbl.textContent = compact ? 'Full-width view' : 'Compact view';
    });
  }

  window.micronToggleCompact = function () {
    localStorage.setItem(KEY, effective() ? '0' : '1');
    apply();
  };

  var raf;
  window.addEventListener('resize', function () {
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(apply);
  });

  apply();
  document.addEventListener('DOMContentLoaded', apply);
})();
</script>

<script>
/* ── Type-ahead for a single-select dropdown ─────────────────────
   A `<select>` holding the appliance's interfaces, schedules or address
   objects is a long, unordered list, and the browser's own type-ahead
   matches an option's *leading* characters only. Typing over the field now
   narrows the list to the options whose text or value contains the query and
   puts the first of them in the field, so a list of a few hundred can be
   searched without putting a search box beside it.

   Bound once on the document rather than per view: every dropdown on every
   screen is covered, including one a page adds later, and there is a single
   implementation to keep right. `data-mno-typeahead="off"` opts a dropdown
   out. See public/css/micronos.css for the "list is filtered" ring.
   ================================================================ */
(function () {
  'use strict';

  var IDLE_MS      = 900;
  var ACTIVE_CLASS = 'mno-typeahead-active';

  // The dropdown being searched, the query so far, and the value it held when
  // the search began — so `change` fires once, at the end of the search, and
  // only if the selection actually moved. A dropdown whose onchange submits a
  // form or navigates must not be triggered once per keystroke.
  var sel         = null;
  var query       = '';
  var startedWith = null;
  var idle        = null;

  function setVisible(node, visible) {
    node.hidden = !visible;
    node.style.display = visible ? '' : 'none';
  }

  function matches(text, value, q) {
    return String(text || '').toLowerCase().indexOf(q) !== -1
        || String(value || '').toLowerCase().indexOf(q) !== -1;
  }

  function unhideAll(select) {
    Array.prototype.forEach.call(select.children, function (node) {
      setVisible(node, true);
      if (node.tagName === 'OPTGROUP') {
        Array.prototype.forEach.call(node.children, function (opt) { setVisible(opt, true); });
      }
    });

    select.classList.remove(ACTIVE_CLASS);
  }

  /** A selection the page made itself already fired its own change. */
  function noteCommitted(select) {
    if (select === sel) startedWith = select.value;
  }

  function commit(select) {
    if (select !== sel || select.value === startedWith) return;

    startedWith = select.value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function stop(select, notify) {
    if (idle) { clearTimeout(idle); idle = null; }

    if (select === sel) {
      if (notify) commit(select);
      sel = null;
      query = '';
      startedWith = null;
    }

    unhideAll(select);
  }

  /** Narrow the list to the query and put the first match in the field. */
  function apply(select) {
    var q     = query.trim().toLowerCase();
    var first = null;

    if (q === '') { unhideAll(select); return; }

    Array.prototype.forEach.call(select.children, function (node) {
      if (node.tagName === 'OPTGROUP') {
        // A group whose own label matches opens wholesale, the way the
        // multi-select picker's filter behaves.
        var groupHit = matches(node.label, '', q);
        var visible  = 0;

        Array.prototype.forEach.call(node.children, function (opt) {
          var hit = groupHit || matches(opt.textContent, opt.value, q);
          setVisible(opt, hit);
          if (hit) { visible++; if (!first && !opt.disabled) first = opt; }
        });

        // An empty group header above nothing reads as a broken list.
        setVisible(node, visible > 0);
      } else if (node.tagName === 'OPTION') {
        var keep = matches(node.textContent, node.value, q);
        setVisible(node, keep);
        if (keep && !first && !node.disabled) first = node;
      }
    });

    select.classList.add(ACTIVE_CLASS);

    if (first && select.value !== first.value) select.value = first.value;

    if (idle) clearTimeout(idle);
    idle = setTimeout(function () { stop(select, true); }, IDLE_MS);
  }

  function begin(select) {
    if (sel === select) return;
    if (sel) stop(sel, true);

    sel         = select;
    query       = '';
    startedWith = select.value;
  }

  function onKeydown(e) {
    var select = e.target;

    if (!select || select.tagName !== 'SELECT' || select.multiple || select.disabled) return;
    if (select.dataset && select.dataset.mnoTypeahead === 'off') return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    if (e.key === 'Escape') {
      if (sel === select) stop(select, true);
      return;                      // the page's own Escape handling still runs
    }

    if (e.key === 'Backspace') {
      if (sel !== select || query === '') return;   // nothing of ours to delete
      e.preventDefault();
      query = query.slice(0, -1);
      apply(select);
      return;
    }

    // Space joins a query already under way; on its own it stays the browser's
    // "choose this option", which a search must not take away.
    if (e.key === ' ') {
      if (sel !== select || query === '') return;
    } else if (e.key.length !== 1) {
      return;                      // arrows, Enter, Tab, function keys
    }

    e.preventDefault();
    begin(select);
    query += e.key;
    apply(select);
  }

  document.addEventListener('keydown', onKeydown);

  document.addEventListener('focusout', function (e) {
    if (e.target && e.target.tagName === 'SELECT') stop(e.target, true);
  });

  document.addEventListener('change', function (e) {
    if (e.target && e.target.tagName === 'SELECT') noteCommitted(e.target);
  });
})();

// Global MicronOS Modal Helpers
window.openModal = function(id) {
  var el = document.getElementById(id);
  if (!el) {
    console.warn('openModal: element #' + id + ' not found in DOM.');
    return;
  }
  el.classList.add('active');
  document.body.classList.add('modal-open');
};

window.closeModal = function(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('active');
  if (!document.querySelector('.rt-modal-backdrop.active, .modal-backdrop.active')) {
    document.body.classList.remove('modal-open');
  }
};

document.addEventListener('click', function(e) {
  if (e.target && (e.target.classList.contains('rt-modal-backdrop') || e.target.classList.contains('modal-backdrop'))) {
    e.target.classList.remove('active');
    if (!document.querySelector('.rt-modal-backdrop.active, .modal-backdrop.active')) {
      document.body.classList.remove('modal-open');
    }
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.rt-modal-backdrop.active, .modal-backdrop.active').forEach(function(m) {
      m.classList.remove('active');
    });
    document.body.classList.remove('modal-open');
  }
});
</script>
</body>

</html>

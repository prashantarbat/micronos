<?php
/**
 * MicronOS Documentation Builder
 * Compiles docs/*.md into rich, standalone HTML interactive pages with fullpage sidebar TOC,
 * 9 theme presets (dark, midnight, ocean, forest, slate, light, solar, mint, paper),
 * theme-adaptive code syntax highlighting, search filter, copy buttons, and responsive navigation.
 */

//
// One entry per guide, and the **only** place a guide is declared:
// the sidebar navigation, the active-page marker and the generated file all
// come from this map. Adding a specification means adding one entry here — the
// two Connector documents were absent from the portal for exactly as long as
// their links, nav entries and export filenames were maintained by hand in
// several places at once.
//
// `label` is the sidebar text and `icon` is the inline SVG path data (the
// appliance ships no icon font), both drawn from the entry by buildHtmlTemplate.
//
$docs = [
    'MICRON_FIREWALL_OS_ARCHITECTURE' => [
        'md' => __DIR__ . '/MICRON_FIREWALL_OS_ARCHITECTURE.md',
        'html' => __DIR__ . '/MICRON_FIREWALL_OS_ARCHITECTURE.html',
        'title' => 'Micron Firewall OS (MicronOS) — Full Enterprise Technical Architecture',
        'subtitle' => 'Comprehensive Blueprint: Data Plane, Control Plane, RBAC, IPC, SNMP, SIEM, and Linux System Services',
        'label' => 'Architecture Blueprint',
        'icon' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>'
    ],
    'MICRONOS_MODULE_REFERENCE' => [
        'md' => __DIR__ . '/MICRONOS_MODULE_REFERENCE.md',
        'html' => __DIR__ . '/MICRONOS_MODULE_REFERENCE.html',
        'title' => 'Micron Firewall OS (MicronOS) — Module Reference & Developer Guide',
        'subtitle' => 'Complete Subsystem Reference: All 35 Controllers, 31 Libraries, 75 Migrations, IPC Commands & Test Suite',
        'label' => 'Module Reference',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>'
    ],
    'STEP_BY_STEP_IMPLEMENTATION_PLAN' => [
        'md' => __DIR__ . '/STEP_BY_STEP_IMPLEMENTATION_PLAN.md',
        'html' => __DIR__ . '/STEP_BY_STEP_IMPLEMENTATION_PLAN.html',
        'title' => 'Micron Firewall OS (MicronOS) — Step-by-Step Implementation Plan',
        'subtitle' => 'From Local CodeIgniter 4 Scaffolding to Bootable Appliance ISO',
        'label' => 'Implementation Plan',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>'
    ],
    'ISO_CREATION_AND_INSTALLER_GUIDE' => [
        'md' => __DIR__ . '/ISO_CREATION_AND_INSTALLER_GUIDE.md',
        'html' => __DIR__ . '/ISO_CREATION_AND_INSTALLER_GUIDE.html',
        'title' => 'Micron Firewall OS (MicronOS) — Appliance ISO Creation & Installer Guide',
        'subtitle' => 'Debian 13 Live-Build Toolchain, TUI Installer, and Appliance Automation',
        'label' => 'ISO &amp; Installer Guide',
        'icon' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>'
    ],
    'MICRONOS_AB_BOOT_RECOVERY_AND_CONFIG_STORAGE' => [
        'md' => __DIR__ . '/MICRONOS_AB_BOOT_RECOVERY_AND_CONFIG_STORAGE.md',
        'html' => __DIR__ . '/MICRONOS_AB_BOOT_RECOVERY_AND_CONFIG_STORAGE.html',
        'title' => 'Micron OS — A/B Dual-Boot, System Recovery & Configuration Storage Architecture',
        'subtitle' => 'Comprehensive Guide to Slot A / Slot B Failover, Filesystem Synchronization, Emergency Recovery, and Single-File Database vs. Flat-File Configs',
        'label' => 'A/B Boot &amp; Recovery',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>'
    ],
    'SSLVPN_ZTNA_CLIENTLESS_GATEWAY_SPECIFICATION' => [
        'md' => __DIR__ . '/SSLVPN_ZTNA_CLIENTLESS_GATEWAY_SPECIFICATION.md',
        'html' => __DIR__ . '/SSLVPN_ZTNA_CLIENTLESS_GATEWAY_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — SSL-VPN & Zero Trust Network Access (ZTNA)',
        'subtitle' => 'Upcoming Version Architecture: Clientless Web Application Proxy, In-Browser RDP/SSH Gateway, and Identity-Aware Micro-Segmentation',
        'label' => 'SSL-VPN &amp; ZTNA Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>'
    ],
    'MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_UPDATE_AND_UPGRADE_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Update & Upgrade Mechanism Specification',
        'subtitle' => 'Three-Plane Lifecycle Architecture: Content, Management-Plane and Platform Image Updates with A/B Slot Activation and Automatic Rollback',
        'label' => 'Update &amp; Upgrade Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>'
    ],
    'MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Plugin & Package Manager Specification',
        'subtitle' => 'OPNsense-/pfSense-Class Extension Surface: Signed Plugin Bundles, a Curated Package Manager, Capability Grants, and Third-Party Integrations',
        'label' => 'Plugin &amp; Package Manager',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'
    ],
    'MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Connector Abstraction Specification',
        'subtitle' => 'Subscribed Feed, Cloud Service & Integration Connectors: Manifest Format, Encrypted Secret Store, Scheduled Sync Engine, and Target Application',
        'label' => 'Connector Abstraction Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-4 4a4 4 0 11-5.656-5.656l1-1m9.656-2.344a4 4 0 00-5.656-5.656l-4 4a4 4 0 105.656 5.656l1-1"/>'
    ],
    'MICRONOS_CONNECTOR_THREAT_MODEL' => [
        'md' => __DIR__ . '/MICRONOS_CONNECTOR_THREAT_MODEL.md',
        'html' => __DIR__ . '/MICRONOS_CONNECTOR_THREAT_MODEL.html',
        'title' => 'Micron Firewall OS (MicronOS) — Connector Abstraction Threat Model',
        'subtitle' => 'Adversarial Analysis of the Connector Design: Master-Key Bootstrap & Backup, SSRF Boundaries on Feed URLs, and the Blast Radius of a Compromised Connector',
        'label' => 'Connector Threat Model',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>'
    ],
    'HYPERV_WIN11_DEVELOPMENT_AND_AI_COORDINATION_GUIDE' => [
        'md' => __DIR__ . '/HYPERV_WIN11_DEVELOPMENT_AND_AI_COORDINATION_GUIDE.md',
        'html' => __DIR__ . '/HYPERV_WIN11_DEVELOPMENT_AND_AI_COORDINATION_GUIDE.html',
        'title' => 'Micron Firewall OS (MicronOS) — Hyper-V & Windows 11 AI-Assisted Development Guide',
        'subtitle' => 'Complete Reference for Local Appliance Virtualization on Windows 11 Pro, Live Code Hot-Syncing, and AI-Coordinated GUI/OS Modifications',
        'label' => 'Hyper-V &amp; AI Dev Guide',
        'icon' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'
    ],
    'MICRONOS_SYSTEM_RESILIENCE_AND_SELF_HEALING_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_SYSTEM_RESILIENCE_AND_SELF_HEALING_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_SYSTEM_RESILIENCE_AND_SELF_HEALING_SPECIFICATION.html',
        'title' => 'Micron OS — Inherent System Resilience & Self-Healing Architecture Specification',
        'subtitle' => 'Immutable Storage, Application & Database Durability, A/B Boot Arbitration, LKGC Recovery, and Disaster Self-Healing',
        'label' => 'System Resilience Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'
    ],
    'MICRONOS_BONDING_AND_WIRELESS_GUIDE' => [
        'md' => __DIR__ . '/MICRONOS_BONDING_AND_WIRELESS_GUIDE.md',
        'html' => __DIR__ . '/MICRONOS_BONDING_AND_WIRELESS_GUIDE.html',
        'title' => 'Micron Firewall OS (MicronOS) — Link Aggregation (IEEE 802.3ad LACP) & Wireless WLAN Guide',
        'subtitle' => 'Enterprise Trunk Aggregation (bond0), Active-Backup Failover, hostapd AP Mode with WPA3-SAE, and wpa_supplicant Client Configuration',
        'label' => 'Bonding &amp; Wi-Fi Guide',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m7.156-7.156a4 4 0 00-5.656 0l-3 3a4 4 0 000 5.656l1.5 1.5"/>'
    ],
    'MICRONOS_ADVANCED_ROADMAP_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_ADVANCED_ROADMAP_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_ADVANCED_ROADMAP_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Advanced Capabilities & Next-Generation Roadmap Specification',
        'subtitle' => 'Git-Backed Configuration Revisions, Layer 7 AppID via nDPI, SSL Deep Inspection, Dynamic SD-WAN, and Threat Feeds',
        'label' => 'Advanced Roadmap Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>'
    ],
    'MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_VERSIONING_AND_RELEASE_LIFECYCLE_SPECIFICATION.html',
        'title' => 'Micron OS — Semantic Versioning & Release Lifecycle Specification',
        'subtitle' => 'MAJOR.MINOR.PATCH Release Channels, Odd/Even Stability Convention, and Incremental Patch Edits Architecture',
        'label' => 'Versioning &amp; Release Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>'
    ],
    'UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN' => [
        'md' => __DIR__ . '/UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.md',
        'html' => __DIR__ . '/UI_UX_COMPREHENSIVE_MODERNIZATION_PLAN.html',
        'title' => 'Micron OS — UI/UX Comprehensive Modernization Plan',
        'subtitle' => 'Modular pfSense/FortiGate Dashboard Cards, Interactive Live Physical Interface Faceplate, and Modern NOC/SOC Design System',
        'label' => 'UI/UX Modernization Plan',
        'icon' => '<rect x="3" y="3" width="18" height="18" rx="2" stroke-linecap="round" stroke-linejoin="round"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 9h18M9 21V9"/>'
    ],
    'MICRONOS_CAPTIVE_PORTAL_AND_HOTSPOT_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_CAPTIVE_PORTAL_AND_HOTSPOT_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_CAPTIVE_PORTAL_AND_HOTSPOT_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (Micron OS) — Captive Portal & Hotspot Voucher Engine Architecture Specification',
        'subtitle' => 'Enterprise Guest WLAN, Voucher Billing, Walled Garden & Netfilter Redirection Blueprint',
        'label' => 'Captive Portal Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>'
    ],
    'MICRONOS_COMPLETION_MASTER_PROMPT' => [
        'md' => __DIR__ . '/MICRONOS_COMPLETION_MASTER_PROMPT.md',
        'html' => __DIR__ . '/MICRONOS_COMPLETION_MASTER_PROMPT.html',
        'title' => 'Micron Firewall OS (MicronOS) — Completion Master Prompt',
        'subtitle' => 'The paste-ready instruction set for finishing the platform: UI revamp, IPC/RPC/CLI alignment, diagnostic toolkit, plugin workspaces, and proof',
        'label' => 'Completion Master Prompt',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>'
    ],
    'MICRONOS_COMPLETION_PROGRAMME' => [
        'md' => __DIR__ . '/MICRONOS_COMPLETION_PROGRAMME.md',
        'html' => __DIR__ . '/MICRONOS_COMPLETION_PROGRAMME.html',
        'title' => 'Micron Firewall OS (MicronOS) — Completion Programme',
        'subtitle' => 'Audited current state, gap register, workstreams, milestones, test matrix and definition of done',
        'label' => 'Completion Programme',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>'
    ],
    'MICRONOS_VERIFICATION_REPORT' => [
        'md' => __DIR__ . '/MICRONOS_VERIFICATION_REPORT.md',
        'html' => __DIR__ . '/MICRONOS_VERIFICATION_REPORT.html',
        'title' => 'Micron Firewall OS (MicronOS) — Verification Report',
        'subtitle' => 'Suites, results, security fixes, regressions found and what remains unverified',
        'label' => 'Verification Report',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    ],
    'MICRONOS_NETWORK_CONFIGURATION' => [
        'md' => __DIR__ . '/MICRONOS_NETWORK_CONFIGURATION.md',
        'html' => __DIR__ . '/MICRONOS_NETWORK_CONFIGURATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Network Configuration & IPv6',
        'subtitle' => 'One transaction for WAN/LAN changes, dependency propagation, IPv6 master switch and boot',
        'label' => 'Network Configuration',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>'
    ],
    'MICRONOS_WEB_TERMINAL' => [
        'md' => __DIR__ . '/MICRONOS_WEB_TERMINAL.md',
        'html' => __DIR__ . '/MICRONOS_WEB_TERMINAL.html',
        'title' => 'Micron Firewall OS (MicronOS) — Web Terminal',
        'subtitle' => 'Browser shell: PTY service, WebSocket tickets, privilege model, limits and audit',
        'label' => 'Web Terminal',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>'
    ],
    'MICRONOS_IPC_RPC_CLI_CONTRACT' => [
        'md' => __DIR__ . '/MICRONOS_IPC_RPC_CLI_CONTRACT.md',
        'html' => __DIR__ . '/MICRONOS_IPC_RPC_CLI_CONTRACT.html',
        'title' => 'Micron Firewall OS (MicronOS) — IPC / RPC / CLI Alignment Contract',
        'subtitle' => 'The wire contract, the method catalogue, the trust model, and the tests that stop the three surfaces from drifting apart',
        'label' => 'IPC / RPC / CLI Contract',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 4H6a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-2M9 4a2 2 0 002 2h2a2 2 0 002-2M9 4a1 1 0 011-1h4a1 1 0 011 1M9 12h6m-6 4h6"/>'
    ],
    'MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_DIAGNOSTIC_TOOLKIT_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Diagnostic & Network Utility Toolkit',
        'subtitle' => 'telnet/nc, nmap, mtr, whois, dig, arping, ss, TLS and HTTP probes, iperf3 — safety model, RBAC, RPC, CLI and tests',
        'label' => 'Diagnostic Toolkit Spec',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 19a8 8 0 100-16 8 8 0 000 16zm10 2l-4.35-4.35"/>'
    ],
    'MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION' => [
        'md' => __DIR__ . '/MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.md',
        'html' => __DIR__ . '/MICRONOS_PLUGIN_WORKSPACE_SPECIFICATION.html',
        'title' => 'Micron Firewall OS (MicronOS) — Plugin Workspace Specification',
        'subtitle' => 'Turning every installed plugin into a working menu destination: status, configuration, logs, lifecycle and enforced menu semantics',
        'label' => 'Plugin Workspaces',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'
    ],
    'PRODUCTION_DATABASE_MIGRATION_AND_INSTALLER_GUIDE' => [
        'md' => __DIR__ . '/PRODUCTION_DATABASE_MIGRATION_AND_INSTALLER_GUIDE.md',
        'html' => __DIR__ . '/PRODUCTION_DATABASE_MIGRATION_AND_INSTALLER_GUIDE.html',
        'title' => 'Micron OS — Production Database Migrations, Seeders & Universal Installer Guide',
        'subtitle' => 'Architecture Reference: Blank DB Baseline, Purge Migration 122, Seeder Pipeline, A/B Slots, and Systemd Shutdown Hardening',
        'label' => 'DB &amp; Installer Guide',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3M7 14h10"/>'
    ]
];

function buildHtmlTemplate($title, $subtitle, $mdContent, $filename, array $guides) {
    $escapedMd = json_encode($mdContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // The sidebar is generated from the registry, so a guide cannot be exported
    // and simultaneously be unreachable from the portal's own navigation — which
    // is how the two Connector documents ended up compiled by nothing at all.
    $guideNav = '';
    foreach ($guides as $key => $guide) {
        $active = $key === $filename ? ' class="active"' : '';
        $basename = basename($guide['html']);
        // Double-quoted on purpose: in single quotes `\n` is a literal
        // backslash-n, and `.doc-nav-links` is a flex column, so each stray
        // text node became its own visible flex item between two links.
        $guideNav .= "\n        <a href=\"{$basename}\"{$active}>"
            . "\n          <svg width=\"15\" height=\"15\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"2\">"
            . "\n            {$guide['icon']}"
            . "\n          </svg>"
            . "\n          {$guide['label']}"
            . "\n        </a>";
    }
    
    return <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-mode="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
  <style>
    /* ============================================================
       THEME PRESETS: 9 MicronOS Schemes (Dark + Light)
       ============================================================ */
    :root, [data-theme="dark"] {
      --bg-primary: #070b14;
      --bg-secondary: #0d1220;
      --bg-tertiary: #111827;
      --card-bg: rgba(17, 24, 39, 0.7);
      --card-border: rgba(255, 255, 255, 0.08);
      --card-border-bright: rgba(255, 255, 255, 0.14);
      --text-main: #f1f5f9;
      --text-muted: #94a3b8;
      --accent-cyan: #00d4ff;
      --accent-cyan-dim: rgba(0, 212, 255, 0.15);
      --accent-deep: #0096c7;
      --accent-purple: #7c3aed;
      --accent-emerald: #10b981;
      --accent-amber: #f59e0b;
      --accent-rose: #ef4444;
      --code-bg: #0d1220;
      --sidebar-w: 320px;
    }

    [data-theme="midnight"] {
      --bg-primary: #090712;
      --bg-secondary: #110f1e;
      --bg-tertiary: #16132a;
      --card-bg: rgba(22, 19, 42, 0.75);
      --card-border: rgba(139, 92, 246, 0.12);
      --card-border-bright: rgba(139, 92, 246, 0.22);
      --text-main: #ede9fe;
      --text-muted: #a1a1b5;
      --accent-cyan: #a78bfa;
      --accent-cyan-dim: rgba(167, 139, 250, 0.15);
      --accent-deep: #7c3aed;
      --accent-purple: #c084fc;
      --accent-emerald: #34d399;
      --accent-amber: #fbbf24;
      --accent-rose: #f87171;
      --code-bg: #110f1e;
    }

    [data-theme="ocean"] {
      --bg-primary: #030f14;
      --bg-secondary: #071820;
      --bg-tertiary: #0c202a;
      --card-bg: rgba(12, 32, 42, 0.75);
      --card-border: rgba(45, 212, 191, 0.12);
      --card-border-bright: rgba(45, 212, 191, 0.22);
      --text-main: #f0fdfa;
      --text-muted: #99f6e4;
      --accent-cyan: #2dd4bf;
      --accent-cyan-dim: rgba(45, 212, 191, 0.15);
      --accent-deep: #0f766e;
      --accent-purple: #818cf8;
      --accent-emerald: #34d399;
      --accent-amber: #fbbf24;
      --accent-rose: #f87171;
      --code-bg: #071820;
    }

    [data-theme="forest"] {
      --bg-primary: #050f08;
      --bg-secondary: #0a170e;
      --bg-tertiary: #0e2014;
      --card-bg: rgba(14, 32, 20, 0.75);
      --card-border: rgba(74, 222, 128, 0.12);
      --card-border-bright: rgba(74, 222, 128, 0.22);
      --text-main: #f0fdf4;
      --text-muted: #bbf7d0;
      --accent-cyan: #4ade80;
      --accent-cyan-dim: rgba(74, 222, 128, 0.15);
      --accent-deep: #15803d;
      --accent-purple: #a78bfa;
      --accent-emerald: #22c55e;
      --accent-amber: #fbbf24;
      --accent-rose: #f87171;
      --code-bg: #0a170e;
    }

    [data-theme="slate"] {
      --bg-primary: #0a0d12;
      --bg-secondary: #111520;
      --bg-tertiary: #161b28;
      --card-bg: rgba(22, 27, 40, 0.75);
      --card-border: rgba(100, 116, 139, 0.14);
      --card-border-bright: rgba(100, 116, 139, 0.24);
      --text-main: #e2e8f0;
      --text-muted: #94a3b8;
      --accent-cyan: #94a3b8;
      --accent-cyan-dim: rgba(148, 163, 184, 0.15);
      --accent-deep: #475569;
      --accent-purple: #818cf8;
      --accent-emerald: #34d399;
      --accent-amber: #fbbf24;
      --accent-rose: #f87171;
      --code-bg: #111520;
    }

    /* Shared light mode base */
    [data-mode="light"] {
      --bg-primary: #f1f5f9;
      --bg-secondary: #ffffff;
      --bg-tertiary: #f8fafc;
      --card-bg: #ffffff;
      --card-border: rgba(0, 0, 0, 0.08);
      --card-border-bright: rgba(0, 0, 0, 0.15);
      --text-main: #0f172a;
      --text-muted: #64748b;
      --accent-cyan: #0284c7;
      --accent-cyan-dim: rgba(2, 132, 199, 0.10);
      --accent-deep: #0369a1;
      --accent-purple: #7c3aed;
      --accent-emerald: #059669;
      --accent-amber: #d97706;
      --accent-rose: #dc2626;
      --code-bg: #f8fafc;
    }

    /* Solar Amber (warm cream) */
    [data-theme="solar"] {
      --bg-primary: #fdf7ee;
      --bg-secondary: #ffffff;
      --bg-tertiary: #fffaf3;
      --card-bg: #ffffff;
      --card-border: rgba(146, 94, 28, 0.14);
      --card-border-bright: rgba(146, 94, 28, 0.25);
      --text-main: #3b2410;
      --text-muted: #7c5a34;
      --accent-cyan: #ea580c;
      --accent-cyan-dim: rgba(234, 88, 12, 0.12);
      --accent-deep: #c2410c;
      --accent-purple: #9333ea;
      --accent-emerald: #0f766e;
      --accent-amber: #b45309;
      --accent-rose: #dc2626;
      --code-bg: #fbf0e0;
    }

    /* Mint Breeze (cool teal) */
    [data-theme="mint"] {
      --bg-primary: #f0fdf9;
      --bg-secondary: #ffffff;
      --bg-tertiary: #f6fefb;
      --card-bg: #ffffff;
      --card-border: rgba(13, 120, 100, 0.14);
      --card-border-bright: rgba(13, 120, 100, 0.25);
      --text-main: #0b3b34;
      --text-muted: #3f6f66;
      --accent-cyan: #0d9488;
      --accent-cyan-dim: rgba(13, 148, 136, 0.12);
      --accent-deep: #0f766e;
      --accent-purple: #7c3aed;
      --accent-emerald: #047857;
      --accent-amber: #b45309;
      --accent-rose: #dc2626;
      --code-bg: #e3f9f2;
    }

    /* Paper Ink (neutral paper) */
    [data-theme="paper"] {
      --bg-primary: #f7f7f8;
      --bg-secondary: #ffffff;
      --bg-tertiary: #fbfbfc;
      --card-bg: #ffffff;
      --card-border: rgba(15, 23, 42, 0.10);
      --card-border-bright: rgba(15, 23, 42, 0.20);
      --text-main: #0f172a;
      --text-muted: #64748b;
      --accent-cyan: #4f46e5;
      --accent-cyan-dim: rgba(79, 70, 229, 0.10);
      --accent-deep: #4338ca;
      --accent-purple: #7c3aed;
      --accent-emerald: #047857;
      --accent-amber: #b45309;
      --accent-rose: #dc2626;
      --code-bg: #f0f0f3;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: var(--bg-primary);
      color: var(--text-main);
      line-height: 1.65;
      display: flex;
      min-height: 100vh;
      overflow-x: hidden;
      transition: background-color 0.2s ease, color 0.2s ease;
    }

    /* Scrollbars */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--card-border-bright); border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--accent-cyan); }

    /* ============================================================
       FULLPAGE SIDEBAR NAVIGATION
       ============================================================ */
    aside.sidebar {
      width: var(--sidebar-w);
      background-color: var(--bg-secondary);
      border-right: 1px solid var(--card-border);
      position: fixed;
      top: 0; bottom: 0; left: 0;
      height: 100vh;
      max-height: 100vh;
      overflow: hidden;
      padding: 1.25rem 1rem 1rem 1rem;
      display: flex;
      flex-direction: column;
      z-index: 100;
      box-shadow: 2px 0 12px rgba(0, 0, 0, 0.15);
      transition: background-color 0.2s ease, border-color 0.2s ease;
    }

    .sidebar-top {
      flex: 0 0 auto;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .brand-box {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding-bottom: 0.85rem;
      border-bottom: 1px solid var(--card-border);
    }
    .brand-logo {
      width: 38px;
      height: 38px;
      background: linear-gradient(135deg, var(--accent-cyan), var(--accent-deep));
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 1.2rem;
      color: #fff;
      box-shadow: 0 4px 12px var(--accent-cyan-dim);
    }
    .brand-text h2 {
      font-size: 1.02rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }
    .brand-text p {
      font-size: 0.72rem;
      color: var(--accent-cyan);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .doc-nav-links {
      display: flex;
      flex-direction: column;
      gap: 0.3rem;
      padding-bottom: 0.85rem;
      border-bottom: 1px solid var(--card-border);
    }
    .doc-nav-links a {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-muted);
      text-decoration: none;
      padding: 0.45rem 0.65rem;
      border-radius: 7px;
      transition: all 0.18s ease;
      display: flex;
      align-items: center;
      gap: 0.55rem;
    }
    .doc-nav-links a:hover {
      color: var(--text-main);
      background: var(--bg-tertiary);
    }
    .doc-nav-links a.active {
      color: var(--accent-cyan);
      background: var(--accent-cyan-dim);
      border-left: 3px solid var(--accent-cyan);
      font-weight: 700;
    }

    .toc-header-wrap {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .toc-title {
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--accent-cyan);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .toc-search {
      width: 100%;
      background: var(--bg-tertiary);
      border: 1px solid var(--card-border-bright);
      border-radius: 7px;
      padding: 7px 10px;
      font-size: 0.78rem;
      color: var(--text-main);
      outline: none;
      font-family: inherit;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .toc-search:focus {
      border-color: var(--accent-cyan);
      box-shadow: 0 0 0 3px var(--accent-cyan-dim);
    }

    /* Dedicated scrollable TOC list filling full viewport */
    nav.toc-nav {
      flex: 1 1 0;
      min-height: 0;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
      padding-top: 0.4rem;
      padding-right: 4px;
      scrollbar-width: thin;
    }
    nav.toc-nav a {
      font-size: 0.79rem;
      color: var(--text-muted);
      text-decoration: none;
      padding: 0.4rem 0.6rem;
      border-radius: 6px;
      transition: all 0.15s ease;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      line-height: 1.35;
    }
    nav.toc-nav a:hover {
      color: var(--text-main);
      background: var(--bg-tertiary);
    }
    nav.toc-nav a.active {
      color: var(--accent-cyan);
      background: var(--accent-cyan-dim);
      font-weight: 600;
    }
    nav.toc-nav a.h3-link {
      padding-left: 1.25rem;
      font-size: 0.74rem;
      opacity: 0.85;
    }

    /* Pinned Sidebar Footer (Theme Preset Switcher) */
    .sidebar-foot {
      flex: 0 0 auto;
      padding-top: 0.75rem;
      border-top: 1px solid var(--card-border);
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }
    .theme-select-label {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--text-muted);
    }
    .theme-select {
      width: 100%;
      background: var(--bg-tertiary);
      border: 1px solid var(--card-border-bright);
      border-radius: 6px;
      padding: 6px 10px;
      font-size: 0.78rem;
      color: var(--text-main);
      outline: none;
      cursor: pointer;
      font-family: inherit;
    }
    .theme-select:focus {
      border-color: var(--accent-cyan);
    }

    /* ============================================================
       MAIN CONTENT
       ============================================================ */
    main.main-content {
      margin-left: var(--sidebar-w);
      flex: 1;
      padding: 2.5rem 3.5rem 5rem 3.5rem;
      max-width: 1200px;
    }

    .doc-header {
      margin-bottom: 2.5rem;
      padding-bottom: 1.5rem;
      border-bottom: 1px solid var(--card-border);
    }
    .doc-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 0.25rem 0.75rem;
      border-radius: 9999px;
      background: var(--accent-cyan-dim);
      color: var(--accent-cyan);
      border: 1px solid var(--accent-cyan);
      margin-bottom: 0.75rem;
    }
    .doc-header h1 {
      font-size: 2.2rem;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.03em;
      margin-bottom: 0.5rem;
      line-height: 1.2;
    }
    .doc-header p {
      font-size: 1.05rem;
      color: var(--text-muted);
    }

    /* Markdown Styling */
    #markdown-body {
      color: var(--text-main);
      font-size: 0.95rem;
    }
    #markdown-body h1, #markdown-body h2, #markdown-body h3, #markdown-body h4 {
      color: var(--text-main);
      font-weight: 700;
      margin-top: 2rem;
      margin-bottom: 0.75rem;
      letter-spacing: -0.02em;
      scroll-margin-top: 2rem;
    }
    #markdown-body h2 {
      font-size: 1.45rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--card-border);
      margin-top: 2.5rem;
      color: var(--accent-cyan);
    }
    #markdown-body h3 {
      font-size: 1.2rem;
      color: var(--text-main);
    }
    #markdown-body h4 {
      font-size: 1rem;
      color: var(--text-muted);
    }
    #markdown-body p {
      margin-bottom: 1rem;
      line-height: 1.7;
    }
    #markdown-body ul, #markdown-body ol {
      margin-bottom: 1.25rem;
      padding-left: 1.5rem;
    }
    #markdown-body li {
      margin-bottom: 0.4rem;
      line-height: 1.6;
    }
    #markdown-body a {
      color: var(--accent-cyan);
      text-decoration: none;
      font-weight: 500;
    }
    #markdown-body a:hover {
      text-decoration: underline;
    }

    /* ============================================================
       CODE BLOCKS: Adaptive Theme Presets (Light & Dark)
       ============================================================ */
    #markdown-body code {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.85em;
      background: var(--code-bg);
      color: var(--accent-cyan);
      padding: 0.2em 0.45em;
      border-radius: 5px;
      border: 1px solid var(--card-border-bright);
      font-weight: 600;
    }
    #markdown-body pre {
      background: var(--code-bg);
      border: 1px solid var(--card-border-bright);
      border-radius: 10px;
      padding: 1.25rem;
      overflow-x: auto;
      margin-bottom: 1.5rem;
      position: relative;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
    #markdown-body pre code {
      background: transparent !important;
      padding: 0 !important;
      border: none !important;
      color: var(--text-main) !important;
      font-size: 0.85rem;
      line-height: 1.65;
    }

    /* Theme-adaptive syntax highlighting tokens */
    #markdown-body pre code.hljs { background: transparent !important; padding: 0 !important; }
    .hljs-keyword, .hljs-selector-tag, .hljs-subst, .hljs-doctag { color: var(--accent-cyan) !important; font-weight: 600; }
    .hljs-string, .hljs-title, .hljs-title.function_, .hljs-section, .hljs-attribute, .hljs-literal,
    .hljs-template-tag, .hljs-template-variable, .hljs-type, .hljs-addition { color: var(--accent-emerald) !important; }
    .hljs-comment, .hljs-quote, .hljs-deletion, .hljs-meta { color: var(--text-muted) !important; font-style: italic; }
    .hljs-number, .hljs-regexp, .hljs-link { color: var(--accent-amber) !important; }
    .hljs-variable, .hljs-params, .hljs-attr { color: var(--text-main) !important; }
    .hljs-built_in, .hljs-builtin-name, .hljs-symbol, .hljs-bullet { color: var(--accent-purple) !important; font-weight: 600; }

    /* Copy Button */
    .code-copy-btn {
      position: absolute;
      top: 8px;
      right: 8px;
      background: var(--bg-secondary);
      border: 1px solid var(--card-border-bright);
      border-radius: 6px;
      color: var(--text-muted);
      font-size: 0.72rem;
      padding: 4px 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.15s ease;
      font-family: inherit;
    }
    .code-copy-btn:hover {
      background: var(--accent-cyan);
      color: #fff;
      border-color: var(--accent-cyan);
    }

    #markdown-body table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 1.75rem;
      background: var(--card-bg);
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--card-border);
      font-size: 0.88rem;
    }
    #markdown-body th {
      background: var(--bg-tertiary);
      color: var(--text-main);
      font-weight: 600;
      text-align: left;
      padding: 0.75rem 1rem;
      border-bottom: 1px solid var(--card-border);
    }
    #markdown-body td {
      padding: 0.65rem 1rem;
      border-bottom: 1px solid var(--card-border);
      color: var(--text-main);
    }
    #markdown-body tr:hover td {
      background: var(--bg-tertiary);
    }
    #markdown-body blockquote {
      border-left: 4px solid var(--accent-cyan);
      background: var(--accent-cyan-dim);
      padding: 0.85rem 1.25rem;
      border-radius: 0 8px 8px 0;
      margin-bottom: 1.25rem;
      color: var(--text-main);
    }
    #markdown-body hr {
      border: none;
      height: 1px;
      background: var(--card-border);
      margin: 2.5rem 0;
    }

    @media (max-width: 900px) {
      aside.sidebar { display: none; }
      main.main-content { margin-left: 0; padding: 1.5rem; }
    }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-top">
      <div class="brand-box">
        <div class="brand-logo">M</div>
        <div class="brand-text">
          <h2>MicronOS Docs</h2>
          <p>Enterprise Firewall</p>
        </div>
      </div>

      <div class="doc-nav-links">
        <a href="index.html">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          Docs Portal Hub
        </a>
{$guideNav}
        <a href="http://micronos.test/dashboard" target="_blank" style="color:var(--accent-cyan); background:var(--accent-cyan-dim);">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
          </svg>
          Launch Appliance GUI &rarr;
        </a>
      </div>

      <div class="toc-header-wrap">
        <div class="toc-title">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
          </svg>
          Table of Contents
        </div>
        <input type="text" id="toc-search" class="toc-search" placeholder="Filter sections (e.g. snmp, siem, nat)..." oninput="filterToc(this.value)">
      </div>
    </div>

    <nav class="toc-nav" id="toc-container">
      <!-- Generated dynamically -->
    </nav>

    <div class="sidebar-foot">
      <label for="themePresetSelect" class="theme-select-label">Theme Preset</label>
      <select id="themePresetSelect" class="theme-select" onchange="setThemePreset(this.value)">
        <optgroup label="Dark Themes">
          <option value="dark">Dark Abyss</option>
          <option value="midnight">Midnight</option>
          <option value="ocean">Ocean Teal</option>
          <option value="forest">Forest</option>
          <option value="slate">Slate Grey</option>
        </optgroup>
        <optgroup label="Light Themes">
          <option value="light">Light / Day</option>
          <option value="solar">Solar Amber</option>
          <option value="mint">Mint Breeze</option>
          <option value="paper">Paper Ink</option>
        </optgroup>
      </select>
    </div>
  </aside>

  <main class="main-content">
    <div class="doc-header">
      <span class="doc-badge">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        MicronOS Technical Manual
      </span>
      <h1>{$title}</h1>
      <p>{$subtitle}</p>
    </div>

    <article id="markdown-body"></article>
  </main>

  <script>
    const rawMarkdown = {$escapedMd};
    
    // Theme Presets Management
    const LIGHT_THEMES = ['light', 'solar', 'mint', 'paper'];
    function setThemePreset(theme) {
      document.documentElement.dataset.theme = theme;
      document.documentElement.dataset.mode = LIGHT_THEMES.includes(theme) ? 'light' : 'dark';
      try {
        localStorage.setItem('micron_docs_theme', theme);
      } catch (e) {}
      const sel = document.getElementById('themePresetSelect');
      if (sel) sel.value = theme;
    }

    try {
      const savedTheme = localStorage.getItem('micron_docs_theme') || 'dark';
      setThemePreset(savedTheme);
    } catch (e) {
      setThemePreset('dark');
    }

    // Configure marked with highlight.js
    marked.setOptions({
      highlight: function(code, lang) {
        if (lang && hljs.getLanguage(lang)) {
          return hljs.highlight(code, { language: lang }).value;
        }
        return hljs.highlightAuto(code).value;
      },
      breaks: false,
      gfm: true
    });

    const rendered = marked.parse(rawMarkdown);
    document.getElementById('markdown-body').innerHTML = rendered;

    // Generate Dynamic TOC with IDs
    const headings = document.getElementById('markdown-body').querySelectorAll('h2, h3');
    const tocContainer = document.getElementById('toc-container');
    
    headings.forEach(heading => {
      const text = heading.textContent;
      const base = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
      let slug = base;
      for (let n = 2; document.getElementById(slug); n++) {
        slug = base + '-' + n;
      }
      heading.id = slug;
      
      const link = document.createElement('a');
      link.href = '#' + slug;
      link.textContent = text;
      link.dataset.title = text.toLowerCase();
      if (heading.tagName.toLowerCase() === 'h3') {
        link.classList.add('h3-link');
      }
      tocContainer.appendChild(link);
    });

    // Add Copy Buttons to Pre blocks
    document.querySelectorAll('#markdown-body pre').forEach(pre => {
      const copyBtn = document.createElement('button');
      copyBtn.className = 'code-copy-btn';
      copyBtn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> Copy';
      copyBtn.onclick = () => {
        const codeText = pre.querySelector('code')?.innerText || pre.innerText;
        navigator.clipboard.writeText(codeText);
        copyBtn.innerHTML = '✔ Copied!';
        setTimeout(() => {
          copyBtn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> Copy';
        }, 2000);
      };
      pre.appendChild(copyBtn);
    });

    // TOC Filter
    function filterToc(query) {
      query = (query || '').toLowerCase().trim();
      document.querySelectorAll('#toc-container a').forEach(a => {
        if (!query || a.dataset.title.includes(query)) {
          a.style.display = 'block';
        } else {
          a.style.display = 'none';
        }
      });
    }

    // Scrollspy for TOC highlight
    const tocLinks = Array.from(tocContainer.querySelectorAll('a'));
    function syncTocActive() {
      const scrollPos = window.scrollY + 120;
      let activeHeading = null;
      headings.forEach(h => {
        if (h.offsetTop <= scrollPos) {
          activeHeading = h;
        }
      });
      if (activeHeading) {
        tocLinks.forEach(link => {
          link.classList.toggle('active', link.getAttribute('href') === '#' + activeHeading.id);
        });
      }
    }
    window.addEventListener('scroll', syncTocActive, { passive: true });
    syncTocActive();
  </script>
</body>
</html>
HTML;
}

foreach ($docs as $key => $doc) {
    if (!file_exists($doc['md'])) {
        echo "Warning: MD file {$doc['md']} not found.\n";
        continue;
    }
    $md = file_get_contents($doc['md']);
    $html = buildHtmlTemplate($doc['title'], $doc['subtitle'], $md, $key, $docs);
    file_put_contents($doc['html'], $html);
    echo "Generated {$doc['html']} (Size: " . strlen($html) . " bytes)\n";
}

echo "Documentation build complete!\n";

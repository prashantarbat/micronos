<?php

namespace App\Libraries;

use App\Models\DpiApplicationModel;
use App\Models\DpiCategoryModel;
use App\Models\DpiPolicyModel;
use App\Models\DpiSettingsModel;
use App\Models\DpiSignatureModel;

/**
 * DpiCompiler — Compiles Micron OS Application Identification (AppID)
 * & Layer 7 DPI rules into nDPI configuration and nftables filter rules.
 */
class DpiCompiler
{
    protected DpiPolicyModel $policyModel;
    protected DpiApplicationModel $appModel;
    protected DpiCategoryModel $categoryModel;
    protected DpiSettingsModel $settingsModel;
    protected DpiSignatureModel $signatureModel;

    public function __construct()
    {
        $this->policyModel    = new DpiPolicyModel();
        $this->appModel       = new DpiApplicationModel();
        $this->categoryModel  = new DpiCategoryModel();
        $this->settingsModel  = new DpiSettingsModel();
        $this->signatureModel = new DpiSignatureModel();
    }

    /**
     * Compiles the nDPI configuration file (/etc/ndpi/ndpi_rules.conf).
     */
    public function compileNdpiConfig(): string
    {
        $settings = $this->settingsModel->getSettings();
        $apps = $this->appModel->findAll();
        $customSigs = $this->signatureModel->getActiveSignatures();

        $out = [];
        $out[] = '# =====================================================================';
        $out[] = '# Micron OS Layer 7 nDPI Daemon Configuration (/etc/ndpi/ndpi_rules.conf)';
        $out[] = '# Generated at: ' . date('Y-m-d H:i:s T');
        $out[] = '# Mode: ' . ($settings['engine_mode'] ?? 'inline_block');
        $out[] = '# Inspection Depth: ' . ($settings['inspection_depth_packets'] ?? 10) . ' packets';
        $out[] = '# =====================================================================';
        $out[] = '';

        $out[] = '# Global Daemon Parameters';
        $out[] = 'engine.enabled = ' . (!empty($settings['enabled']) ? 'true' : 'false');
        $out[] = 'engine.mode = ' . ($settings['engine_mode'] ?? 'inline_block');
        $out[] = 'engine.max_flows = ' . ($settings['flow_table_max'] ?? 262144);
        $out[] = 'engine.depth_packets = ' . ($settings['inspection_depth_packets'] ?? 10);
        $out[] = '';

        $out[] = '# Protocol to Netfilter Mark Mapping';
        foreach ($apps as $app) {
            $protoId = $app['proto_id'] ?? 0;
            $markHex = $app['mark_hex'] ?? '0x0';
            $name    = $app['name'] ?? $app['id'];
            $out[] = sprintf('proto %-3d mark %-8s # %s', $protoId, $markHex, $name);
        }
        $out[] = '';

        if (!empty($customSigs)) {
            $out[] = '# Custom User-Defined Signatures';
            foreach ($customSigs as $sig) {
                $ptype = $sig['pattern_type'] ?? 'regex';
                $pat   = addcslashes($sig['pattern'] ?? '', '"');
                $out[] = sprintf('custom_rule name="%s" type="%s" pattern="%s" port="%s"',
                    $sig['name'],
                    $ptype,
                    $pat,
                    $sig['port'] ?? 'any'
                );
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Compiles nftables ruleset snippet for Layer 7 DPI filtering.
     */
    public function compileNftablesSnippet(): string
    {
        $settings = $this->settingsModel->getSettings();
        if (empty($settings['enabled'])) {
            return "# Micron OS Layer 7 DPI Engine is globally disabled\n";
        }

        $policies = $this->policyModel->getActivePolicies();
        $apps = $this->appModel->findAll();
        $categories = $this->categoryModel->findAll();

        $appMap = [];
        foreach ($apps as $a) {
            $appMap[$a['id']] = $a;
        }

        // Map category to app list
        $categoryApps = [];
        foreach ($apps as $a) {
            $categoryApps[$a['category_id']][] = $a;
        }

        $lines = [];
        $lines[] = '# =====================================================================';
        $lines[] = '# Micron OS nftables Layer 7 DPI Ruleset';
        $lines[] = '# Table: inet micron_filter, Chain: dpi_forward';
        $lines[] = '# Generated at: ' . date('Y-m-d H:i:s T');
        $lines[] = '# =====================================================================';
        $lines[] = '';
        $lines[] = 'table inet micron_filter {';
        $lines[] = '    chain dpi_forward {';
        $lines[] = '        type filter hook forward priority 10; policy accept;';
        $lines[] = '';

        foreach ($policies as $pol) {
            $action = $pol['action'] ?? 'block';
            $srcIf  = $pol['src_interface'] ?? 'lan';
            $dstIf  = $pol['dst_interface'] ?? 'wan';
            $log    = !empty($pol['log_enabled']);
            $polName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pol['name']);

            $targetApps = [];

            // Add specifically selected apps
            $polApps = !empty($pol['app_ids']) ? json_decode($pol['app_ids'], true) : [];
            if (is_array($polApps)) {
                foreach ($polApps as $appId) {
                    if (isset($appMap[$appId])) {
                        $targetApps[$appId] = $appMap[$appId];
                    }
                }
            }

            // Add apps from selected categories
            $polCats = !empty($pol['category_ids']) ? json_decode($pol['category_ids'], true) : [];
            if (is_array($polCats)) {
                foreach ($polCats as $catId) {
                    if (!empty($categoryApps[$catId])) {
                        foreach ($categoryApps[$catId] as $cApp) {
                            $targetApps[$cApp['id']] = $cApp;
                        }
                    }
                }
            }

            if (empty($targetApps)) {
                continue;
            }

            $lines[] = "        # Policy: {$pol['name']} (Seq: {$pol['sequence']}, Action: {$action})";

            foreach ($targetApps as $app) {
                $mark = $app['mark_hex'];
                $appName = $app['name'];

                $matchExpr = "ct mark {$mark}";
                if ($srcIf !== 'any' && !empty($srcIf)) {
                    $matchExpr = "iifname \"{$srcIf}\" " . $matchExpr;
                }
                if ($dstIf !== 'any' && !empty($dstIf)) {
                    $matchExpr .= " oifname \"{$dstIf}\"";
                }

                if ($action === 'block') {
                    if ($log) {
                        $lines[] = "        {$matchExpr} log prefix \"[MICRON-DPI-BLOCK: {$appName}] \" drop";
                    } else {
                        $lines[] = "        {$matchExpr} drop";
                    }
                } elseif ($action === 'shape') {
                    $bw = (int) ($pol['bandwidth_limit_kbps'] ?? 1024);
                    $lines[] = "        {$matchExpr} limit rate over {$bw} kbytes/second drop";
                    if ($log) {
                        $lines[] = "        {$matchExpr} log prefix \"[MICRON-DPI-SHAPE: {$appName}] \" accept";
                    }
                } elseif ($action === 'allow') {
                    if ($log) {
                        $lines[] = "        {$matchExpr} log prefix \"[MICRON-DPI-PASS: {$appName}] \" accept";
                    } else {
                        $lines[] = "        {$matchExpr} accept";
                    }
                }
            }
            $lines[] = '';
        }

        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines);
    }
}

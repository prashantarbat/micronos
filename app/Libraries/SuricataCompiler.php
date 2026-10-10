<?php

namespace App\Libraries;

use App\Models\IdsIpRuleModel;
use App\Models\IdsSettingsModel;
use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Suricata IDS/IPS Configuration & Rule Compiler
 * =======================================================
 * Compiles dynamic Suricata 7+ configuration (/etc/suricata/suricata.yaml),
 * custom IP whitelist pass rules, custom IP blacklist drop rules, and
 * GeoIP country filtering rules.
 */
class SuricataCompiler
{
    protected ?BaseConnection $db;
    protected IdsSettingsModel $settingsModel;
    protected IdsIpRuleModel $ipRuleModel;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db            = $db ?? \Config\Database::connect();
        $this->settingsModel = new IdsSettingsModel();
        $this->ipRuleModel   = new IdsIpRuleModel();
    }

    /**
     * Compiles /etc/suricata/suricata.yaml content based on active settings.
     */
    public function compile(): string
    {
        $settings = $this->settingsModel->getSettings();
        $mode     = $settings['mode'] ?? 'ips'; // 'ids' (passive) or 'ips' (inline)
        $ifaces   = !empty($settings['interfaces']) ? $settings['interfaces'] : ['wan'];

        $logPayloads = !empty($settings['log_payloads']) ? 'yes' : 'no';
        $logPackets  = !empty($settings['log_packets']) ? 'yes' : 'no';

        $lines = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# Micron Firewall OS (MicronOS) — Compiled Suricata IDS/IPS Config';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# Operational Mode: ' . strtoupper($mode);
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';
        $lines[] = '%YAML 1.1';
        $lines[] = '---';
        $lines[] = 'vars:';
        $lines[] = '  address-groups:';
        $lines[] = '    HOME_NET: "[192.168.0.0/16,10.0.0.0/8,172.16.0.0/12]"';
        $lines[] = '    EXTERNAL_NET: "!$HOME_NET"';
        $lines[] = '    HTTP_SERVERS: "$HOME_NET"';
        $lines[] = '    SMTP_SERVERS: "$HOME_NET"';
        $lines[] = '    SQL_SERVERS: "$HOME_NET"';
        $lines[] = '    DNS_SERVERS: "$HOME_NET"';
        $lines[] = '';
        $lines[] = 'default-log-dir: /var/log/suricata/';
        $lines[] = '';
        $lines[] = 'outputs:';
        $lines[] = '  - eve-log:';
        $lines[] = '      enabled: yes';
        $lines[] = '      filetype: regular';
        $lines[] = '      filename: eve.json';
        $lines[] = '      types:';
        $lines[] = '        - alert:';
        $lines[] = '            payload: ' . $logPayloads;
        $lines[] = '            payload-buffer-size: 4kb';
        $lines[] = '            packet: ' . $logPackets;
        $lines[] = '            metadata: yes';
        $lines[] = '            http-body: ' . $logPayloads;
        $lines[] = '            tagged-packets: yes';
        $lines[] = '        - http:';
        $lines[] = '            extended: yes';
        $lines[] = '        - dns:';
        $lines[] = '            query: yes';
        $lines[] = '            answer: yes';
        $lines[] = '        - tls:';
        $lines[] = '            extended: yes';
        $lines[] = '        - drop:';
        $lines[] = '            alerts: yes';
        $lines[] = '            flows: all';
        $lines[] = '';

        if ($mode === 'ips') {
            // Netfilter Queue (NFQ) inline IPS mode
            $lines[] = 'nfq:';
            $lines[] = '  mode: accept';
            $lines[] = '  repeat-mark: 1';
            $lines[] = '  repeat-mask: 1';
            $lines[] = '  bypass-mark: 2';
            $lines[] = '  bypass-mask: 2';
            $lines[] = '  fail-open: no';
            $lines[] = '';
        }

        // AF-PACKET multi-interface inspection
        $lines[] = 'af-packet:';
        $clusterId = 99;
        foreach ($ifaces as $iface) {
            $lines[] = '  - interface: ' . escapeshellcmd($iface);
            $lines[] = '    threads: auto';
            $lines[] = '    cluster-id: ' . $clusterId++;
            $lines[] = '    cluster-type: cluster_flow';
            $lines[] = '    defrag: yes';
            $lines[] = '    use-mmap: yes';
            $lines[] = '    tpacket-v3: yes';
            if ($mode === 'ips') {
                $lines[] = '    copy-mode: ips';
                $lines[] = '    copy-iface: ' . escapeshellcmd($iface);
            }
        }
        $lines[] = '';

        $lines[] = 'default-rule-path: /var/lib/suricata/rules';
        $lines[] = 'rule-files:';
        $lines[] = '  - custom_whitelist.rules';
        $lines[] = '  - custom_blacklist.rules';
        $lines[] = '  - geoip_filter.rules';
        $lines[] = '  - suricata.rules';
        $lines[] = '  - emerging-threats.rules';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Compiles /var/lib/suricata/rules/custom_whitelist.rules
     * Generates high-priority 'pass' rules for whitelisted IPs and subnets.
     */
    public function compileWhitelistRules(): string
    {
        $rules = $this->ipRuleModel->getActiveRules('whitelist');
        $lines = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# MicronOS Suricata — Custom IP Whitelist (Pass Rules)';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';

        if (empty($rules)) {
            $lines[] = '# No active whitelist rules configured.';
            return implode("\n", $lines) . "\n";
        }

        $sid = 1900001;
        foreach ($rules as $r) {
            $ip   = trim($r['ip_address']);
            $desc = preg_replace('/[^\w\s\-\.\/]/', '', $r['description'] ?: 'Whitelisted Target');
            // Suricata pass rule matches in all directions
            $lines[] = sprintf(
                'pass ip %s any -> any any (msg:"MICRONOS WHITELIST: %s"; sid:%d; rev:1; priority:1;)',
                $ip,
                $desc,
                $sid++
            );
            $lines[] = sprintf(
                'pass ip any any -> %s any (msg:"MICRONOS WHITELIST: %s"; sid:%d; rev:1; priority:1;)',
                $ip,
                $desc,
                $sid++
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Compiles /var/lib/suricata/rules/custom_blacklist.rules
     * Generates high-priority 'drop' rules for blacklisted IPs and subnets.
     */
    public function compileBlacklistRules(): string
    {
        $rules = $this->ipRuleModel->getActiveRules('blacklist');
        $lines = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# MicronOS Suricata — Custom IP Blacklist (Drop Rules)';
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';

        if (empty($rules)) {
            $lines[] = '# No active blacklist rules configured.';
            return implode("\n", $lines) . "\n";
        }

        $sid = 1910001;
        foreach ($rules as $r) {
            $ip   = trim($r['ip_address']);
            $desc = preg_replace('/[^\w\s\-\.\/]/', '', $r['description'] ?: 'Blacklisted Attacker');
            $lines[] = sprintf(
                'drop ip %s any -> any any (msg:"MICRONOS BLACKLIST: Ingress from %s"; classtype:bad-unknown; sid:%d; rev:1; priority:1;)',
                $ip,
                $desc,
                $sid++
            );
            $lines[] = sprintf(
                'drop ip any any -> %s any (msg:"MICRONOS BLACKLIST: Egress to %s"; classtype:bad-unknown; sid:%d; rev:1; priority:1;)',
                $ip,
                $desc,
                $sid++
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Compiles /var/lib/suricata/rules/geoip_filter.rules
     * Generates country-level blocking or filtering rules based on GeoIP codes.
     */
    public function compileGeoipRules(): string
    {
        $settings   = $this->settingsModel->getSettings();
        $mode       = $settings['country_filter_mode'] ?? 'none';
        $action     = strtolower($settings['country_filter_action'] ?? 'drop');
        $countries  = $settings['selected_countries'] ?? [];

        // Normalize action: drop, alert, or reject
        if (!in_array($action, ['drop', 'alert', 'reject'], true)) {
            $action = 'drop';
        }

        $lines = [];
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '# MicronOS Suricata — GeoIP Country Filtering Rules';
        $lines[] = '# Mode: ' . strtoupper($mode) . ' | Action: ' . strtoupper($action);
        $lines[] = '# Generated on: ' . date('Y-m-d H:i:s T');
        $lines[] = '# ----------------------------------------------------------------------';
        $lines[] = '';

        if ($mode === 'none' || empty($countries)) {
            $lines[] = '# Country filtering is disabled or no countries selected.';
            return implode("\n", $lines) . "\n";
        }

        $countryMap = GeoIp::COUNTRIES;
        $sid = 1920001;

        if ($mode === 'blacklist') {
            foreach ($countries as $cc) {
                $ccUpper = strtoupper(trim($cc));
                if (!isset($countryMap[$ccUpper])) {
                    continue;
                }
                $cName = $countryMap[$ccUpper];
                $lines[] = sprintf(
                    '%s ip any any -> any any (msg:"MICRONOS GEOIP BLACKLIST: Traffic from %s [%s]"; geoip:src,%s; classtype:policy-violation; sid:%d; rev:1; priority:2;)',
                    $action,
                    $cName,
                    $ccUpper,
                    $ccUpper,
                    $sid++
                );
                $lines[] = sprintf(
                    '%s ip any any -> any any (msg:"MICRONOS GEOIP BLACKLIST: Traffic to %s [%s]"; geoip:dst,%s; classtype:policy-violation; sid:%d; rev:1; priority:2;)',
                    $action,
                    $cName,
                    $ccUpper,
                    $ccUpper,
                    $sid++
                );
            }
        } elseif ($mode === 'whitelist') {
            // Whitelist mode: alert or drop any traffic originating outside the whitelist
            $allowedList = implode(',', array_map('strtoupper', array_map('trim', $countries)));
            $lines[] = sprintf(
                '%s ip any any -> any any (msg:"MICRONOS GEOIP WHITELIST: Traffic originating outside permitted countries"; geoip:src,!%s; classtype:policy-violation; sid:%d; rev:1; priority:2;)',
                $action,
                $allowedList,
                $sid++
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Compiles all configuration files in one payload
     *
     * @return array<string, string> filepath => file content
     */
    public function compileAll(): array
    {
        return [
            '/etc/suricata/suricata.yaml'                  => $this->compile(),
            '/var/lib/suricata/rules/custom_whitelist.rules' => $this->compileWhitelistRules(),
            '/var/lib/suricata/rules/custom_blacklist.rules' => $this->compileBlacklistRules(),
            '/var/lib/suricata/rules/geoip_filter.rules'     => $this->compileGeoipRules(),
        ];
    }
}

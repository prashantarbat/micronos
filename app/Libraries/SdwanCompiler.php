<?php

namespace App\Libraries;

use App\Models\SdwanMemberModel;
use App\Models\SdwanProfileModel;
use App\Models\SdwanRuleModel;
use App\Models\SdwanSettingsModel;

/**
 * SdwanCompiler — Compiles Dynamic SD-WAN SLA path steering rules
 * into Linux Policy-Based Routing (PBR) and nftables policy marks.
 */
class SdwanCompiler
{
    protected SdwanMemberModel $memberModel;
    protected SdwanProfileModel $profileModel;
    protected SdwanRuleModel $ruleModel;
    protected SdwanSettingsModel $settingsModel;

    public function __construct(
        ?SdwanMemberModel $memberModel = null,
        ?SdwanProfileModel $profileModel = null,
        ?SdwanRuleModel $ruleModel = null,
        ?SdwanSettingsModel $settingsModel = null
    ) {
        $this->memberModel   = $memberModel ?? new SdwanMemberModel();
        $this->profileModel  = $profileModel ?? new SdwanProfileModel();
        $this->ruleModel     = $ruleModel ?? new SdwanRuleModel();
        $this->settingsModel = $settingsModel ?? new SdwanSettingsModel();
    }

    /**
     * Compile complete SD-WAN configuration artifact.
     *
     * @return array{enabled: bool, nftables: string, iproute2: string, summary: array}
     */
    public function compile(): array
    {
        $settings = $this->settingsModel->getSettings();
        $enabled  = (bool) ($settings['enabled'] ?? true);

        if (!$enabled) {
            return [
                'enabled'  => false,
                'nftables' => "# Dynamic SD-WAN Engine is DISABLED\n",
                'iproute2' => "# Dynamic SD-WAN Engine is DISABLED\n",
                'summary'  => ['rules_count' => 0, 'members_count' => 0, 'active_status' => 'disabled'],
            ];
        }

        $members  = $this->memberModel->where('enabled', 1)->orderBy('priority', 'ASC')->findAll();
        $profiles = $this->profileModel->findAll();
        $rules    = $this->ruleModel->where('enabled', 1)->orderBy('priority', 'ASC')->findAll();

        $profMap = [];
        foreach ($profiles as $p) {
            $profMap[$p['id']] = $p;
        }

        $memMap = [];
        $tableBaseId = 101;
        $iprouteCommands = [];
        $iprouteCommands[] = "# === Micron OS SD-WAN Linux Policy Routing Tables ===";

        foreach ($members as $m) {
            $tableId = $tableBaseId++;
            $memMap[$m['id']] = array_merge($m, ['table_id' => $tableId, 'mark_hex' => sprintf('0x%x', $tableId)]);
            $iprouteCommands[] = "ip route replace default via {$m['gateway_ip']} dev {$m['interface']} table {$tableId}";
            $iprouteCommands[] = "ip rule add fwmark {$memMap[$m['id']]['mark_hex']} table {$tableId} priority 1000 2>/dev/null || true";
        }

        // Generate nftables policy routing rules
        $nft = [];
        $nft[] = "# === Micron OS Dynamic SD-WAN Policy Steering Table ===";
        $nft[] = "table ip micron_sdwan {";
        $nft[] = "    chain prerouting_steering {";
        $nft[] = "        type filter hook prerouting priority mangle; policy accept;";
        $nft[] = "        # Bypass RFC 1918 internal subnets from WAN policy steering";
        $nft[] = "        ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8 } return;";

        foreach ($rules as $r) {
            $prof = $profMap[$r['sdwan_profile_id']] ?? null;
            if (!$prof) continue;

            $prefMem = $memMap[$prof['preferred_member_id']] ?? (reset($memMap) ?: null);
            if (!$prefMem) continue;

            $mark = $prefMem['mark_hex'];
            $srcMatch = ($r['source_subnet'] !== '0.0.0.0/0') ? "ip saddr {$r['source_subnet']} " : "";
            $dstMatch = ($r['destination_subnet'] !== '0.0.0.0/0') ? "ip daddr {$r['destination_subnet']} " : "";

            $apps = json_decode($prof['assigned_applications'] ?? '[]', true) ?: [];
            $appComment = !empty($apps) ? " # Apps: " . implode(', ', $apps) : "";

            $nft[] = "        # Rule [{$r['priority']}]: {$r['name']} (Profile: {$prof['name']})";
            $nft[] = "        {$srcMatch}{$dstMatch}meta mark set {$mark} accept;{$appComment}";
        }

        $nft[] = "    }";
        $nft[] = "}";

        return [
            'enabled'  => true,
            'nftables' => implode("\n", $nft),
            'iproute2' => implode("\n", $iprouteCommands),
            'summary'  => [
                'rules_count'   => count($rules),
                'members_count' => count($members),
                'active_status' => 'operational',
            ],
        ];
    }
}

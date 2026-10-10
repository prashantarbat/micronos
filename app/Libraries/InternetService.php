<?php

namespace App\Libraries;

/**
 * InternetService — the Internet Service Database.
 * ================================================
 * An internet service is a named, vendor-maintained catalogue of the IP
 * prefixes a SaaS / cloud platform publishes (Microsoft 365, AWS, Google
 * Cloud, Zoom, …). Referencing one from a firewall policy lets an admin write
 * "allow LAN → Microsoft 365" without hand-maintaining dozens of CIDRs.
 *
 * MicronOS models a service as a firewall address object of type
 * `internet-service` whose value is a comma-separated list of catalogue keys,
 * e.g. "microsoft_365, zoom". The nftables compiler turns each key into a
 * named, interval set (`@isdb_microsoft_365`) that micrond repopulates from the
 * service catalogue, and a local feed can inline the prefixes immediately:
 *
 *   writable/isdb/microsoft_365.list   # one IP or CIDR per line
 */
class InternetService
{
    /**
     * Catalogue: key → [label, category, description].
     *
     * Keys are lower-case slugs and are the stable identifier stored in the
     * address object's value.
     */
    public const SERVICES = [
        'microsoft_365' => [
            'label'       => 'Microsoft 365',
            'category'    => 'Productivity',
            'description' => 'Microsoft 365 / Office 365 service endpoints.',
        ],
        'microsoft_azure' => [
            'label'       => 'Microsoft Azure',
            'category'    => 'Cloud',
            'description' => 'Azure public cloud regional service tags.',
        ],
        'google_cloud' => [
            'label'       => 'Google Cloud',
            'category'    => 'Cloud',
            'description' => 'Google Cloud Platform published address ranges.',
        ],
        'google_workspace' => [
            'label'       => 'Google Workspace',
            'category'    => 'Productivity',
            'description' => 'Gmail, Drive, Meet and the rest of Google Workspace.',
        ],
        'aws' => [
            'label'       => 'Amazon Web Services',
            'category'    => 'Cloud',
            'description' => 'AWS published service prefix lists.',
        ],
        'cloudflare' => [
            'label'       => 'Cloudflare',
            'category'    => 'CDN / Security',
            'description' => 'Cloudflare edge and CDN address ranges.',
        ],
        'zoom' => [
            'label'       => 'Zoom',
            'category'    => 'Conferencing',
            'description' => 'Zoom Meetings media and signalling ranges.',
        ],
        'slack' => [
            'label'       => 'Slack',
            'category'    => 'Collaboration',
            'description' => 'Slack workspace endpoints.',
        ],
        'github' => [
            'label'       => 'GitHub',
            'category'    => 'Developer',
            'description' => 'GitHub.com, API and GitHub Actions runners.',
        ],
        'dropbox' => [
            'label'       => 'Dropbox',
            'category'    => 'Storage',
            'description' => 'Dropbox sync and API endpoints.',
        ],
        'salesforce' => [
            'label'       => 'Salesforce',
            'category'    => 'Business',
            'description' => 'Salesforce CRM published ranges.',
        ],
        'servicenow' => [
            'label'       => 'ServiceNow',
            'category'    => 'Business',
            'description' => 'ServiceNow platform endpoints.',
        ],
        'workday' => [
            'label'       => 'Workday',
            'category'    => 'Business',
            'description' => 'Workday HCM / finance endpoints.',
        ],
        'webex' => [
            'label'       => 'Webex',
            'category'    => 'Conferencing',
            'description' => 'Webex Meetings media and signalling ranges.',
        ],
        'teams' => [
            'label'       => 'Microsoft Teams',
            'category'    => 'Conferencing',
            'description' => 'Teams media and signalling endpoints.',
        ],
        'whatsapp' => [
            'label'       => 'WhatsApp',
            'category'    => 'Messaging',
            'description' => 'WhatsApp client and media ranges.',
        ],
        'facebook' => [
            'label'       => 'Facebook / Meta',
            'category'    => 'Social',
            'description' => 'Meta platform address ranges.',
        ],
        'twitter' => [
            'label'       => 'X / Twitter',
            'category'    => 'Social',
            'description' => 'X (Twitter) platform address ranges.',
        ],
        'netflix' => [
            'label'       => 'Netflix',
            'category'    => 'Streaming',
            'description' => 'Netflix Open Connect address ranges.',
        ],
        'youtube' => [
            'label'       => 'YouTube',
            'category'    => 'Streaming',
            'description' => 'YouTube delivery address ranges.',
        ],
        'spotify' => [
            'label'       => 'Spotify',
            'category'    => 'Streaming',
            'description' => 'Spotify streaming and API endpoints.',
        ],
        'steam' => [
            'label'       => 'Steam',
            'category'    => 'Gaming',
            'description' => 'Steam content delivery ranges.',
        ],
        'cloud_saas_generic' => [
            'label'       => 'Generic SaaS',
            'category'    => 'General',
            'description' => 'Broad well-known SaaS provider ranges.',
        ],
        'tor' => [
            'label'       => 'Tor Exit Nodes',
            'category'    => 'Threat',
            'description' => 'Known Tor exit-node addresses.',
        ],
        'botnet_c2' => [
            'label'       => 'Botnet C2',
            'category'    => 'Threat',
            'description' => 'Reported command-and-control infrastructure.',
        ],
        'torrent_trackers' => [
            'label'       => 'Torrent Trackers',
            'category'    => 'Threat',
            'description' => 'Public BitTorrent tracker infrastructure.',
        ],
    ];

    /** Catalogue entries grouped by category for the UI. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::SERVICES as $key => $svc) {
            $out[$svc['category']][$key] = $svc;
        }
        ksort($out);

        return $out;
    }

    /** True when $key is a known catalogue entry. */
    public static function isKnown(string $key): bool
    {
        return isset(self::SERVICES[strtolower(trim($key))]);
    }

    /** Human label for a key; falls back to the raw key. */
    public static function label(string $key): string
    {
        $key = strtolower(trim($key));

        return self::SERVICES[$key]['label'] ?? $key;
    }

    /**
     * Split a service value into validated, lower-case catalogue keys.
     *
     * @return list<string>
     */
    public static function keys(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $value) as $key) {
            $key = strtolower(trim((string) $key));
            if ($key !== '' && self::isKnown($key) && ! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Keys that are NOT in the catalogue (for error messages). */
    public static function invalidKeys(string $value): array
    {
        $bad = [];
        foreach (preg_split('/[\s,;]+/', $value) as $key) {
            $key = strtolower(trim((string) $key));
            if ($key !== '' && ! self::isKnown($key)) {
                $bad[] = $key;
            }
        }

        return array_values(array_unique($bad));
    }

    /** Canonical stored form of a service value, e.g. "microsoft_365, zoom". */
    public static function normalise(string $value): string
    {
        return implode(', ', self::keys($value));
    }

    /** Human label for a whole value, e.g. "Microsoft 365, Zoom". */
    public static function describe(string $value): string
    {
        $labels = [];
        foreach (self::keys($value) as $key) {
            $labels[] = self::label($key);
        }

        return $labels === [] ? trim($value) : implode(', ', $labels);
    }

    /** nftables set identifier for a service, e.g. "isdb_microsoft_365". */
    public static function setName(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
        $key = trim($key, '_');

        return 'isdb_' . $key;
    }

    /** Directory that holds optional per-service network feeds. */
    public static function dataDir(): string
    {
        return WRITEPATH . 'isdb';
    }

    /** Absolute path of a service's primary feed file, existing or not. */
    public static function feedFile(string $key): string
    {
        return self::dataDir() . '/' . strtolower(trim($key)) . '.list';
    }

    /**
     * Prefixes for a service from the local feed, when present.
     *
     * @return list<string>
     */
    public static function networks(string $key): array
    {
        $key = strtolower(trim($key));
        if (! self::isKnown($key)) {
            return [];
        }

        $out = [];
        foreach ([".list", ".netset", ".txt"] as $ext) {
            $file = self::dataDir() . '/' . $key . $ext;
            if (! is_file($file)) {
                continue;
            }
            $raw = (string) @file_get_contents($file);
            foreach (preg_split('/[\s,]+/', $raw) as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '' && strpos($entry, '#') !== 0 && Cidr::isValid($entry)) {
                    $out[] = $entry;
                }
            }
            break;
        }

        return array_values(array_unique($out));
    }
}

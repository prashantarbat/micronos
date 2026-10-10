<?php

namespace App\Controllers;

use App\Libraries\GeoIp;
use App\Libraries\InternetService;
use App\Libraries\ServiceDatabase;
use App\Libraries\ServiceSources;

/**
 * InternetServices — the Internet Service Database.
 *
 * A catalogue of well-known SaaS / cloud / threat platforms, each backed by a
 * set of published IP prefixes. Services are referenced from firewall address
 * objects of type `internet-service`, so an admin can write "allow LAN →
 * Microsoft 365" without maintaining CIDRs by hand.
 *
 * Each entry's prefixes are refreshed periodically from the platform's own
 * public feed (see App\Libraries\ServiceSources). The `sync` action performs
 * that refresh on demand; the seeded `cron_service_sync` job does it nightly.
 */
class InternetServices extends BaseController
{
    public function index(): string
    {
        $db = \Config\Database::connect();

        $services = $db->table('internet_services')->orderBy('category', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        foreach ($services as &$svc) {
            $decoded             = json_decode((string) ($svc['prefixes'] ?? ''), true);
            $svc['prefix_count'] = is_array($decoded) ? count($decoded) : 0;
            $svc['has_feed']     = ServiceSources::has($svc['id']);
            $svc['feed_url']     = ServiceSources::url($svc['id']) ?? ($svc['source_url'] ?: null);
        }
        unset($svc);

        // Geography objects are refreshed on the same schedule; surface the
        // countries currently referenced by an address object.
        $geoCountries = [];
        foreach ($db->table('firewall_addresses')->where('type', 'geoip')->get()->getResultArray() as $row) {
            foreach (GeoIp::codes((string) ($row['value'] ?? '')) as $code) {
                $geoCountries[$code] = GeoIp::COUNTRIES[$code] ?? $code;
            }
        }
        ksort($geoCountries);

        return view('firewall/internet_services', [
            'title'        => 'Internet Service Database — MicronOS',
            'breadcrumb'   => 'Internet Service Database',
            'services'     => $services,
            'categories'   => array_values(array_unique(array_map(static fn ($s) => $s['category'] ?: 'General', $services))),
            'geoCountries' => $geoCountries,
            'hasCurl'      => function_exists('curl_init'),
        ]);
    }

    /**
     * Downloads each enabled service's public prefix feed, writes the per-set
     * feed file the compiler inlines, and caches the list on the row.
     */
    public function sync(): \CodeIgniter\HTTP\RedirectResponse
    {
        $summary = (new ServiceDatabase())->syncServices();

        $message = sprintf(
            'Internet Service Database synchronized: %d service(s) refreshed, %d failed, %d without a public feed.',
            $summary['synced'],
            $summary['failed'],
            $summary['skipped'],
        );

        return redirect()->back()->with($summary['failed'] > 0 ? 'error' : 'success', $message);
    }

    /**
     * Refreshes geography feeds for the countries referenced by address objects.
     */
    public function syncGeo(): \CodeIgniter\HTTP\RedirectResponse
    {
        $engine  = new ServiceDatabase();
        $codes   = $engine->countriesInUse();

        if ($codes === []) {
            return redirect()->back()->with('error', 'No geography address objects reference a country feed.');
        }

        $summary = $engine->syncCountries($codes);

        $message = sprintf(
            'Geography feeds refreshed: %d country feed(s) updated, %d failed.',
            $summary['synced'],
            $summary['failed'],
        );

        return redirect()->back()->with($summary['failed'] > 0 ? 'error' : 'success', $message);
    }

    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('internet_services')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Internet service not found.');
        }

        $db->table('internet_services')->where('id', $id)->update([
            'enabled'    => ((int) $row['enabled'] === 1) ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()->with('success', "Internet service '{$row['name']}' status toggled.");
    }

    /**
     * Creates a firewall address object of type `internet-service` from a set of
     * selected catalogue keys — the bridge between the database and policy rules.
     */
    public function createAddress(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db      = \Config\Database::connect();
        $name    = trim($this->request->getPost('name') ?: '');
        $keys    = InternetService::keys((string) ($this->request->getPost('services') ?? ''));
        $comment = trim($this->request->getPost('comment') ?: '');

        if ($name === '' || $keys === []) {
            return redirect()->back()->with('error', 'A name and at least one service are required.');
        }

        if ($db->table('firewall_addresses')->where('name', $name)->countAllResults() > 0) {
            return redirect()->back()->with('error', "An address named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('firewall_addresses')->insert([
            'id'         => 'addr_' . bin2hex(random_bytes(6)),
            'name'       => $name,
            'type'       => 'internet-service',
            'value'      => implode(', ', $keys),
            'interface'  => 'any',
            'color'      => '#0ea5e9',
            'comment'    => $comment !== '' ? $comment : 'Internet Service Database object',
            'is_system'  => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->to(base_url('firewall/addresses'))
            ->with('success', "Address object '{$name}' created from " . count($keys) . ' internet service(s).');
    }
}

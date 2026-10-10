<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * SystemUpdate — the appliance-wide update / upgrade page (Admin → System).
 *
 * Distinct from {@see Plugins::updates()}, which moves a single plugin's own
 * packages: this is the OS surface. It asks micrond what an upgrade of the
 * appliance's installed packages would change and can perform it in place. The
 * daemon runs a fixed `apt-get` argv, so the browser can never name a package or
 * repository.
 */
class SystemUpdate extends BaseController
{
    public function index(): string
    {
        $updater = \Config\Services::systemUpdate();
        $info    = $updater->info();

        // The last check is kept in the session until the next one, so the list
        // survives ordinary page loads and not just the redirect that set it.
        $report = session()->get('system_update_report');
        if (! is_array($report)) {
            $report = null;
        }

        $packages = is_array($report['packages'] ?? null) ? $report['packages'] : [];

        return view('system/updates', [
            'title'      => 'System Updates — MicronOS',
            'breadcrumb' => 'System Updates',
            'os'         => (string) ($report['os'] ?? $info['os'] ?? 'MicronOS'),
            'version'    => (string) ($report['version'] ?? $info['version'] ?? ''),
            'kernel'     => (string) ($report['kernel'] ?? $info['kernel'] ?? ''),
            'report'     => $report,
            'packages'   => $packages,
        ]);
    }

    /**
     * POST /system/updates/check — read-only availability check.
     */
    public function check(): RedirectResponse
    {
        $report = \Config\Services::systemUpdate()->check();

        if (($report['success'] ?? false) !== true) {
            return redirect()->to(base_url('system/updates'))
                ->with('error', 'Update check failed: ' . $this->reason($report));
        }

        $count = (int) ($report['count'] ?? count($report['packages'] ?? []));

        session()->set('system_update_report', $report);

        return redirect()->to(base_url('system/updates'))
            ->with('success', $count > 0
                ? sprintf('%d package update(s) available.', $count)
                : 'The appliance is up to date.');
    }

    /**
     * POST /system/updates/upgrade — upgrade every installed package in place.
     */
    public function upgrade(): RedirectResponse
    {
        $result = \Config\Services::systemUpdate()->upgrade();

        if (($result['success'] ?? false) !== true) {
            return redirect()->to(base_url('system/updates'))
                ->with('error', 'System upgrade failed: ' . $this->reason($result));
        }

        $message = 'System upgrade completed.';
        $warnings = $result['warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            $message .= ' ' . implode(' ', array_map('strval', $warnings));
        }

        return redirect()->to(base_url('system/updates'))->with('success', $message);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function reason(array $result): string
    {
        $warnings = $result['warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            return (string) $warnings[0];
        }

        return (string) ($result['message'] ?? 'the daemon rejected the request');
    }
}

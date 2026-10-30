<?php

namespace App\Controllers;

use App\Libraries\CveAuditEngine;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CveAdvisories Controller
 *
 * Provides authenticated vulnerability management for MicronOS & Debian packages,
 * live NIST NVD advisory tracking, and executive vulnerability dashboards.
 */
class CveAdvisories extends BaseController
{
    protected CveAuditEngine $engine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->engine = new CveAuditEngine();
    }

    /**
     * Main dashboard view for CVEs & Security Advisories.
     */
    public function index(): string
    {
        $db = \Config\Database::connect();

        // If no scan has ever been run and table is empty, auto-seed initial baseline scan
        if ($db->tableExists('cve_scans') && $db->table('cve_scans')->countAllResults() === 0) {
            $this->engine->runAuthenticatedScan();
        }

        // Query parameters for filters
        $search   = trim((string) $this->request->getGet('q'));
        $severity = strtoupper(trim((string) $this->request->getGet('severity')));
        $status   = strtolower(trim((string) $this->request->getGet('status')));

        $builder = $db->table('cve_advisories');

        if ($search !== '') {
            $builder->groupStart()
                ->like('id', $search)
                ->orLike('package_name', $search)
                ->orLike('title', $search)
                ->orLike('summary', $search)
                ->groupEnd();
        }

        if (in_array($severity, ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'], true)) {
            $builder->where('severity', $severity);
        }

        if (in_array($status, ['vulnerable', 'patched', 'not_affected'], true)) {
            $builder->where('status', $status);
        }

        $advisories = $builder->orderBy('cvss_score', 'DESC')
            ->orderBy('published_at', 'DESC')
            ->get()
            ->getResultArray();

        // Calculate Executive Metrics
        $allAdv = $db->tableExists('cve_advisories') ? $db->table('cve_advisories')->get()->getResultArray() : [];
        $critCount   = 0;
        $highCount   = 0;
        $medCount    = 0;
        $lowCount    = 0;
        $vulnCount   = 0;
        $patchedCount= 0;

        foreach ($allAdv as $adv) {
            $isVuln = ($adv['status'] === 'vulnerable');
            if ($isVuln) {
                $vulnCount++;
                match ($adv['severity']) {
                    'CRITICAL' => $critCount++,
                    'HIGH'     => $highCount++,
                    'MEDIUM'   => $medCount++,
                    default    => $lowCount++,
                };
            } else {
                $patchedCount++;
            }
        }

        $lastScan = $db->tableExists('cve_scans')
            ? $db->table('cve_scans')->orderBy('created_at', 'DESC')->get()->getRowArray()
            : null;

        $systemInfo = $this->engine->detectSystemInfo();

        return view('security/cve/index', [
            'title'        => 'CVEs & Security Advisories — MicronOS',
            'breadcrumb'   => 'Security: CVEs & Advisories',
            'advisories'   => $advisories,
            'stats'        => [
                'total_packages' => $lastScan['total_packages'] ?? 450,
                'vulnerable'     => $vulnCount,
                'critical'       => $critCount,
                'high'           => $highCount,
                'medium'         => $medCount,
                'low'            => $lowCount,
                'patched'        => $patchedCount,
                'last_scan'      => $lastScan,
            ],
            'systemInfo'   => $systemInfo,
            'filters'      => [
                'q'        => $search,
                'severity' => $severity,
                'status'   => $status,
            ],
        ]);
    }

    /**
     * Trigger authenticated root scan of appliance packages.
     */
    public function runScan(): RedirectResponse|ResponseInterface
    {
        try {
            $result = $this->engine->runAuthenticatedScan();

            if ($this->request->isAJAX()) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => "Authenticated scan complete: {$result['total_packages']} packages audited in {$result['duration_ms']} ms.",
                    'data'    => $result,
                ]);
            }

            return redirect()->to(base_url('security/cve'))
                ->with('success', "Authenticated root scan completed successfully: Audited {$result['total_packages']} packages in {$result['duration_ms']}ms. Found {$result['vulnerabilities_found']} actionable advisories ({$result['critical']} Critical, {$result['high']} High).");
        } catch (\Throwable $e) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'error'   => $e->getMessage(),
                ]);
            }

            return redirect()->to(base_url('security/cve'))
                ->with('error', 'Failed to run vulnerability scan: ' . $e->getMessage());
        }
    }

    /**
     * Refresh online security advisory feeds from NIST NVD / Debian Tracker.
     */
    public function refreshFeed(): RedirectResponse|ResponseInterface
    {
        try {
            $result = $this->engine->refreshFeeds();

            if ($this->request->isAJAX()) {
                return $this->response->setJSON($result);
            }

            return redirect()->to(base_url('security/cve'))
                ->with('success', $result['message']);
        } catch (\Throwable $e) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(500)->setJSON([
                    'success' => false,
                    'error'   => $e->getMessage(),
                ]);
            }

            return redirect()->to(base_url('security/cve'))
                ->with('error', 'Failed to refresh feeds: ' . $e->getMessage());
        }
    }

    /**
     * Export CVE Audit Report as CSV.
     */
    public function exportCsv(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $rows = $db->table('cve_advisories')
            ->orderBy('cvss_score', 'DESC')
            ->orderBy('published_at', 'DESC')
            ->get()
            ->getResultArray();

        $filename = 'MicronOS_CVE_Advisories_' . date('Y-m-d_His') . '.csv';

        $fp = fopen('php://temp', 'w+');
        fputcsv($fp, [
            'CVE ID',
            'Package Name',
            'Installed Version',
            'Fixed Version',
            'Severity',
            'CVSS Score',
            'Attack Vector',
            'Status',
            'NIST NVD Advisory URL',
            'Debian Security Tracker URL',
            'Advisory Title',
            'Summary',
            'Remediation Command',
            'Published Date',
            'Last Scanned',
        ]);

        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['id'],
                $r['package_name'],
                $r['installed_version'],
                $r['fixed_version'] ?? 'N/A',
                $r['severity'],
                $r['cvss_score'],
                $r['attack_vector'],
                $r['status'],
                $r['nvd_url'],
                $r['debian_url'] ?? '',
                $r['title'],
                $r['summary'],
                $r['remediation'],
                $r['published_at'],
                $r['scanned_at'],
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /**
     * Get JSON advisory detail for modal display.
     */
    public function detail(string $cveId): ResponseInterface
    {
        $db = \Config\Database::connect();
        $row = $db->table('cve_advisories')->where('id', $cveId)->get()->getRowArray();

        if (! $row) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Advisory not found']);
        }

        return $this->response->setJSON($row);
    }
}

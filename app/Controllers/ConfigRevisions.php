<?php

namespace App\Controllers;

use App\Libraries\AppVersion;
use App\Libraries\ConfigRevisionEngine;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ConfigRevisions — Automated Configuration Diff & Git-Backed Revision History Controller.
 */
class ConfigRevisions extends BaseController
{
    protected ConfigRevisionEngine $engine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->engine = \Config\Services::configRevisionEngine();
    }

    /**
     * GET /system/revisions — Revisions timeline and dashboard.
     */
    public function index(): string
    {
        $history = $this->engine->getHistory(100);

        return view('revisions/index', [
            'title'       => 'Config Revisions & Diff — ' . AppVersion::getProjectName(),
            'breadcrumb'  => 'Configuration Revisions',
            'history'     => $history,
            'version'     => AppVersion::getVersion(),
            'channel'     => AppVersion::getChannel(),
            'projectName' => AppVersion::getProjectName(),
        ]);
    }

    /**
     * GET /system/revisions/diff/(:segment) — JSON API or modal diff details.
     */
    public function diff(string $hash): ResponseInterface
    {
        $diffData = $this->engine->getDiff($hash);

        if ($this->request->isAJAX() || $this->request->getGet('format') === 'json') {
            return $this->response->setJSON([
                'success' => true,
                'diff'    => $diffData,
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'diff'    => $diffData,
        ]);
    }

    /**
     * POST /system/revisions/rollback/(:segment) — Restore state from a commit.
     */
    public function rollback(string $hash): ResponseInterface
    {
        $author = (string) (session('username') ?? 'ministrator');
        $result = $this->engine->rollback($hash, $author);

        if (($result['success'] ?? false) !== true) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(400)->setJSON($result);
            }
            return redirect()->to(base_url('system/revisions'))
                ->with('error', 'Rollback failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        if ($this->request->isAJAX()) {
            return $this->response->setJSON($result);
        }

        return redirect()->to(base_url('system/revisions'))
            ->with('success', $result['message'] ?? 'Configuration rolled back successfully.');
    }

    /**
     * POST /system/revisions/commit — Create manual checkpoint.
     */
    public function commitManual(): ResponseInterface
    {
        $message = trim((string) $this->request->getPost('message'));
        if ($message === '') {
            $message = 'Manual configuration checkpoint';
        }

        $author = (string) (session('username') ?? 'ministrator');
        $result = $this->engine->commit($author, $message, 'manual', true);

        if ($this->request->isAJAX()) {
            return $this->response->setJSON($result);
        }

        return redirect()->to(base_url('system/revisions'))
            ->with('success', 'Configuration checkpoint recorded as commit ' . substr((string) ($result['commit_hash'] ?? ''), 0, 8) . '. Version updated to ' . ($result['version'] ?? ''));
    }

    /**
     * GET /system/revisions/export/(:segment) — Download JSON snapshot.
     */
    public function export(string $hash): ResponseInterface
    {
        $snapshot = $this->engine->getSnapshot($hash);
        if ($snapshot === null) {
            return redirect()->to(base_url('system/revisions'))
                ->with('error', 'Revision snapshot not found.');
        }

        $shortHash = substr($hash, 0, 8);
        $filename = 'micronos_config_' . $shortHash . '.json';

        return $this->response
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setJSON($snapshot);
    }
}

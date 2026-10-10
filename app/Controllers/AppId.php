<?php

namespace App\Controllers;

use App\Libraries\ConfigRevisionEngine;
use App\Libraries\DpiCompiler;
use App\Libraries\IpcClient;
use App\Models\DpiApplicationModel;
use App\Models\DpiCategoryModel;
use App\Models\DpiPolicyModel;
use App\Models\DpiSettingsModel;
use App\Models\DpiSignatureModel;

/**
 * AppId Controller — Layer 7 Deep Packet Inspection & Application Control
 * Powered by nDPI and nftables ct/meta packet classification.
 */
class AppId extends BaseController
{
    protected IpcClient $ipc;
    protected DpiPolicyModel $policyModel;
    protected DpiApplicationModel $appModel;
    protected DpiCategoryModel $categoryModel;
    protected DpiSettingsModel $settingsModel;
    protected DpiSignatureModel $signatureModel;
    protected DpiCompiler $compiler;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc            = \Config\Services::ipc();
        $this->policyModel    = new DpiPolicyModel();
        $this->appModel       = new DpiApplicationModel();
        $this->categoryModel  = new DpiCategoryModel();
        $this->settingsModel  = new DpiSettingsModel();
        $this->signatureModel = new DpiSignatureModel();
        $this->compiler       = new DpiCompiler();
        $this->revEngine      = new ConfigRevisionEngine();
    }

    public function index()
    {
        $db = \Config\Database::connect();

        // 1. DPI Engine Status from IPC
        $statusRes = $this->ipc->call('dpi.status');
        $dpiStatus = $statusRes['result'] ?? [
            'running'         => false,
            'engine'          => 'nDPI Engine',
            'mode'            => 'inline_block',
            'uptime'          => '0s',
            'active_flows'    => 0,
            'total_analyzed'  => 0,
            'blocked_flows'   => 0,
            'shaped_flows'    => 0,
            'throughput_mbps' => 0.0,
            'memory_usage'    => '0 MB',
        ];

        // 2. Settings & Catalogs
        $settings   = $this->settingsModel->getSettings();
        $categories = $this->categoryModel->getAllWithAppCounts();
        $apps       = $this->appModel->getWithCategory();
        $signatures = $this->signatureModel->findAll();
        $policies   = $this->policyModel->getOrderedPolicies();

        // 3. Resolve app & category names for policies
        $appMap = [];
        foreach ($apps as $a) {
            $appMap[$a['id']] = $a['name'];
        }

        $catMap = [];
        foreach ($categories as $c) {
            $catMap[$c['id']] = $c['name'];
        }

        foreach ($policies as &$pol) {
            $polApps = !empty($pol['app_ids']) ? json_decode($pol['app_ids'], true) : [];
            $polCats = !empty($pol['category_ids']) ? json_decode($pol['category_ids'], true) : [];

            $pol['app_names'] = is_array($polApps) ? array_map(fn($id) => $appMap[$id] ?? $id, $polApps) : [];
            $pol['cat_names'] = is_array($polCats) ? array_map(fn($id) => $catMap[$id] ?? $id, $polCats) : [];
        }

        // 4. Interfaces
        $interfaces = $db->table('interfaces')->select('name, physical_device')->orderBy('name', 'ASC')->get()->getResultArray();

        return view('appid/index', [
            'dpiStatus'  => $dpiStatus,
            'settings'   => $settings,
            'categories' => $categories,
            'apps'       => $apps,
            'signatures' => $signatures,
            'policies'   => $policies,
            'interfaces' => $interfaces,
            'title'      => 'Application Identification (AppID) & Layer 7 DPI',
        ]);
    }

    /**
     * Save or update an Application Control policy rule.
     */
    public function savePolicy()
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Policy name is required.']);
        }

        $appIds = $this->request->getPost('app_ids');
        $catIds = $this->request->getPost('category_ids');

        $data = [
            'name'                 => $name,
            'sequence'             => (int) ($this->request->getPost('sequence') ?: $this->policyModel->getNextSequence()),
            'src_interface'        => $this->request->getPost('src_interface') ?: 'lan',
            'dst_interface'        => $this->request->getPost('dst_interface') ?: 'wan',
            'app_ids'              => is_array($appIds) ? json_encode(array_values($appIds)) : null,
            'category_ids'         => is_array($catIds) ? json_encode(array_values($catIds)) : null,
            'action'               => $this->request->getPost('action') ?: 'block',
            'bandwidth_limit_kbps' => $this->request->getPost('bandwidth_limit_kbps') ? (int) $this->request->getPost('bandwidth_limit_kbps') : null,
            'schedule'             => $this->request->getPost('schedule') ?: 'always',
            'log_enabled'          => (int) ($this->request->getPost('log_enabled') ?? 1),
            'enabled'              => (int) ($this->request->getPost('enabled') ?? 1),
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->policyModel->update($id, $data);
            $actionWord = 'Updated';
        } else {
            $data['id'] = 'pol_dpi_' . bin2hex(random_bytes(4));
            $this->policyModel->insert($data);
            $actionWord = 'Created';
        }

        // Git revision checkpoint
        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$actionWord} Application Control policy '{$name}' (Action: {$data['action']})",
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Policy '{$name}' successfully saved.",
        ]);
    }

    /**
     * Delete an Application Control policy rule.
     */
    public function deletePolicy(string $id)
    {
        $policy = $this->policyModel->find($id);
        if (!$policy) {
            return $this->response->setJSON(['success' => false, 'message' => 'Policy not found.']);
        }

        $this->policyModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted Application Control policy '{$policy['name']}'",
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Policy '{$policy['name']}' deleted.",
        ]);
    }

    /**
     * Toggle policy enabled/disabled state.
     */
    public function togglePolicy(string $id)
    {
        $policy = $this->policyModel->find($id);
        if (!$policy) {
            return $this->response->setJSON(['success' => false, 'message' => 'Policy not found.']);
        }

        $newVal = empty($policy['enabled']) ? 1 : 0;
        $this->policyModel->update($id, ['enabled' => $newVal]);

        $stateWord = $newVal ? 'Enabled' : 'Disabled';
        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$stateWord} Application Control policy '{$policy['name']}'",
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'enabled' => $newVal,
            'message' => "Policy is now {$stateWord}.",
        ]);
    }

    /**
     * Save DPI engine global settings.
     */
    public function saveSettings()
    {
        $data = [
            'id'                       => 1,
            'enabled'                  => (int) ($this->request->getPost('enabled') ?? 0),
            'engine_mode'              => $this->request->getPost('engine_mode') ?: 'inline_block',
            'inspection_depth_packets' => max(1, (int) ($this->request->getPost('inspection_depth_packets') ?: 10)),
            'log_level'                => $this->request->getPost('log_level') ?: 'matched',
            'flow_table_max'           => max(1024, (int) ($this->request->getPost('flow_table_max') ?: 262144)),
            'updated_at'               => date('Y-m-d H:i:s'),
        ];

        $this->settingsModel->replace($data);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Updated DPI Engine global settings (Mode: {$data['engine_mode']}, Depth: {$data['inspection_depth_packets']} pkts)",
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => 'DPI Engine global settings saved successfully.',
        ]);
    }

    /**
     * Save or update custom signature.
     */
    public function saveSignature()
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Signature name is required.']);
        }

        $data = [
            'name'         => $name,
            'category_id'  => $this->request->getPost('category_id') ?: 'custom',
            'pattern_type' => $this->request->getPost('pattern_type') ?: 'regex',
            'pattern'      => trim((string) $this->request->getPost('pattern')),
            'port'         => $this->request->getPost('port') ?: 'any',
            'protocol'     => $this->request->getPost('protocol') ?: 'tcp',
            'enabled'      => (int) ($this->request->getPost('enabled') ?? 1),
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->signatureModel->update($id, $data);
            $actionWord = 'Updated';
        } else {
            $data['id'] = 'sig_' . bin2hex(random_bytes(4));
            $this->signatureModel->insert($data);
            $actionWord = 'Created';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$actionWord} custom DPI signature '{$name}'",
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Custom signature '{$name}' saved.",
        ]);
    }

    /**
     * Delete custom signature.
     */
    public function deleteSignature(string $id)
    {
        $sig = $this->signatureModel->find($id);
        if (!$sig) {
            return $this->response->setJSON(['success' => false, 'message' => 'Signature not found.']);
        }

        $this->signatureModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted custom DPI signature '{$sig['name']}'",
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Custom signature '{$sig['name']}' deleted.",
        ]);
    }

    /**
     * Compile configuration, reload nDPI daemon, and apply nftables ruleset.
     */
    public function apply()
    {
        $ndpiConfig = $this->compiler->compileNdpiConfig();
        $nftSnippet = $this->compiler->compileNftablesSnippet();

        $res = $this->ipc->call('dpi.apply', [
            'ndpi_config'      => $ndpiConfig,
            'nftables_snippet' => $nftSnippet,
        ]);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Applied Layer 7 DPI ruleset and reloaded nDPI engine',
            'dpi'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Layer 7 DPI & Application Control rules applied successfully.',
            'ipc'     => $res['result'] ?? null,
        ]);
    }

    /**
     * Preview compiled configuration.
     */
    public function previewConfig()
    {
        return $this->response->setJSON([
            'success'          => true,
            'ndpi_config'      => $this->compiler->compileNdpiConfig(),
            'nftables_snippet' => $this->compiler->compileNftablesSnippet(),
        ]);
    }

    /**
     * Live flow telemetry JSON endpoint.
     */
    public function liveFlows()
    {
        $res = $this->ipc->call('dpi.live_flows');
        return $this->response->setJSON([
            'success' => true,
            'flows'   => $res['result']['flows'] ?? [],
        ]);
    }

    /**
     * Aggregated telemetry stats JSON endpoint.
     */
    public function telemetryStats()
    {
        $res = $this->ipc->call('dpi.stats');
        return $this->response->setJSON([
            'success' => true,
            'stats'   => $res['result']['stats'] ?? [],
        ]);
    }
}

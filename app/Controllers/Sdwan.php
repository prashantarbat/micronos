<?php

namespace App\Controllers;

use App\Libraries\ConfigRevisionEngine;
use App\Libraries\IpcClient;
use App\Libraries\SdwanCompiler;
use App\Libraries\SdwanEngine;
use App\Models\SdwanMemberModel;
use App\Models\SdwanProfileModel;
use App\Models\SdwanRuleModel;
use App\Models\SdwanSettingsModel;

/**
 * Sdwan Controller — Application-Aware Dynamic SD-WAN (SLA Probing & Path Steering).
 */
class Sdwan extends BaseController
{
    protected IpcClient $ipc;
    protected SdwanMemberModel $memberModel;
    protected SdwanProfileModel $profileModel;
    protected SdwanRuleModel $ruleModel;
    protected SdwanSettingsModel $settingsModel;
    protected SdwanCompiler $compiler;
    protected SdwanEngine $engine;
    protected ConfigRevisionEngine $revEngine;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc           = \Config\Services::ipc();
        $this->memberModel   = new SdwanMemberModel();
        $this->profileModel  = new SdwanProfileModel();
        $this->ruleModel     = new SdwanRuleModel();
        $this->settingsModel = new SdwanSettingsModel();
        $this->compiler      = new SdwanCompiler($this->memberModel, $this->profileModel, $this->ruleModel, $this->settingsModel);
        $this->engine        = new SdwanEngine($this->memberModel, $this->profileModel, $this->ruleModel, $this->settingsModel);
        $this->revEngine     = new ConfigRevisionEngine();
    }

    public function index()
    {
        $members  = $this->memberModel->orderBy('priority', 'ASC')->findAll();
        $profiles = $this->profileModel->findAll();
        $rules    = $this->ruleModel->orderBy('priority', 'ASC')->findAll();
        $settings = $this->settingsModel->getSettings();

        // Calculate decisions per profile
        $steeringDecisions = [];
        foreach ($profiles as $p) {
            $steeringDecisions[$p['id']] = $this->engine->resolveSteeringDecision($p, $members);
        }

        return view('sdwan/index', [
            'members'           => $members,
            'profiles'          => $profiles,
            'rules'             => $rules,
            'settings'          => $settings,
            'steeringDecisions' => $steeringDecisions,
            'title'             => 'Application-Aware Dynamic SD-WAN & SLA Steering',
        ]);
    }

    public function saveMember()
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Member name is required.']);
        }

        $data = [
            'name'        => $name,
            'interface'   => trim((string) $this->request->getPost('interface')),
            'gateway_ip'  => trim((string) $this->request->getPost('gateway_ip')),
            'cost'        => (int) ($this->request->getPost('cost') ?: 10),
            'weight'      => (int) ($this->request->getPost('weight') ?: 100),
            'priority'    => (int) ($this->request->getPost('priority') ?: 1),
            'ping_target' => trim((string) $this->request->getPost('ping_target')) ?: '8.8.8.8',
            'enabled'     => (int) ($this->request->getPost('enabled') ?? 1),
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->memberModel->update($id, $data);
            $action = 'Updated';
        } else {
            $data['id'] = 'mem_' . bin2hex(random_bytes(4));
            $this->memberModel->insert($data);
            $action = 'Added';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$action} SD-WAN uplink member '{$name}' ({$data['interface']})",
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "SD-WAN member '{$name}' saved.",
        ]);
    }

    public function deleteMember(string $id)
    {
        $member = $this->memberModel->find($id);
        if (!$member) {
            return $this->response->setJSON(['success' => false, 'message' => 'Member not found.']);
        }

        $this->memberModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted SD-WAN member '{$member['name']}'",
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Member '{$member['name']}' deleted.",
        ]);
    }

    public function saveProfile()
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Profile name is required.']);
        }

        $assignedApps = $this->request->getPost('assigned_applications');

        $data = [
            'name'                  => $name,
            'description'           => trim((string) $this->request->getPost('description')),
            'sla_max_loss_pct'      => (float) ($this->request->getPost('sla_max_loss_pct') ?: 1.0),
            'sla_max_latency_ms'    => (float) ($this->request->getPost('sla_max_latency_ms') ?: 100.0),
            'sla_max_jitter_ms'     => (float) ($this->request->getPost('sla_max_jitter_ms') ?: 15.0),
            'preferred_member_id'   => $this->request->getPost('preferred_member_id') ?: null,
            'backup_member_id'      => $this->request->getPost('backup_member_id') ?: null,
            'assigned_applications' => is_array($assignedApps) ? json_encode(array_values($assignedApps)) : (string) $assignedApps,
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->profileModel->update($id, $data);
            $action = 'Updated';
        } else {
            $data['id'] = 'prof_' . bin2hex(random_bytes(4));
            $this->profileModel->insert($data);
            $action = 'Created';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$action} SD-WAN SLA profile '{$name}'",
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "SLA Profile '{$name}' saved.",
        ]);
    }

    public function deleteProfile(string $id)
    {
        $profile = $this->profileModel->find($id);
        if (!$profile) {
            return $this->response->setJSON(['success' => false, 'message' => 'Profile not found.']);
        }

        $this->profileModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted SD-WAN SLA profile '{$profile['name']}'",
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Profile '{$profile['name']}' deleted.",
        ]);
    }

    public function saveRule()
    {
        $id = $this->request->getPost('id');
        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Rule name is required.']);
        }

        $data = [
            'name'               => $name,
            'priority'           => (int) ($this->request->getPost('priority') ?: 10),
            'source_subnet'      => trim((string) $this->request->getPost('source_subnet')) ?: '0.0.0.0/0',
            'destination_subnet' => trim((string) $this->request->getPost('destination_subnet')) ?: '0.0.0.0/0',
            'sdwan_profile_id'   => $this->request->getPost('sdwan_profile_id'),
            'strategy'           => $this->request->getPost('strategy') ?: 'best_quality',
            'enabled'            => (int) ($this->request->getPost('enabled') ?? 1),
        ];

        if (!empty($id)) {
            $data['id'] = $id;
            $this->ruleModel->update($id, $data);
            $action = 'Updated';
        } else {
            $data['id'] = 'rule_' . bin2hex(random_bytes(4));
            $this->ruleModel->insert($data);
            $action = 'Added';
        }

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "{$action} SD-WAN path steering rule '{$name}'",
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Steering rule '{$name}' saved.",
        ]);
    }

    public function deleteRule(string $id)
    {
        $rule = $this->ruleModel->find($id);
        if (!$rule) {
            return $this->response->setJSON(['success' => false, 'message' => 'Rule not found.']);
        }

        $this->ruleModel->delete($id);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            "Deleted SD-WAN path steering rule '{$rule['name']}'",
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => "Rule '{$rule['name']}' deleted.",
        ]);
    }

    public function saveSettings()
    {
        $data = [
            'id'                      => 1,
            'enabled'                 => (int) ($this->request->getPost('enabled') ?? 1),
            'probe_interval_seconds'  => max(1, (int) ($this->request->getPost('probe_interval_seconds') ?: 5)),
            'probe_packet_count'      => max(3, (int) ($this->request->getPost('probe_packet_count') ?: 10)),
            'jitter_smoothing_factor' => (float) ($this->request->getPost('jitter_smoothing_factor') ?: 0.85),
            'default_strategy'        => $this->request->getPost('default_strategy') ?: 'best_quality',
            'updated_at'              => date('Y-m-d H:i:s'),
        ];

        $this->settingsModel->replace($data);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Updated Dynamic SD-WAN engine settings',
            'routing'
        );

        return $this->response->setJSON([
            'success' => true,
            'message' => 'SD-WAN settings saved successfully.',
        ]);
    }

    public function probeNow()
    {
        $results = $this->engine->probeAll();
        return $this->response->setJSON([
            'success' => true,
            'results' => $results,
        ]);
    }

    public function apply()
    {
        $compiled = $this->compiler->compile();
        $this->ipc->call('sdwan.apply', ['compiled' => $compiled]);

        $this->revEngine->createRevision(
            session()->get('username') ?? 'ministrator',
            'Applied SD-WAN SLA path steering policies and kernel route marks',
            'routing'
        );

        return $this->response->setJSON([
            'success'  => true,
            'message'  => 'SD-WAN SLA path steering policies applied successfully.',
            'compiled' => $compiled,
        ]);
    }

    public function metrics()
    {
        $members = $this->memberModel->where('enabled', 1)->findAll();
        return $this->response->setJSON([
            'success' => true,
            'members' => $members,
        ]);
    }
}

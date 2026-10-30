<?php

namespace App\Controllers;

use App\Models\QosShaperModel;
use App\Models\QosRuleModel;
use App\Models\InterfaceModel;
use App\Libraries\IpcClient;

class Qos extends BaseController
{
    protected QosShaperModel $shaperModel;
    protected QosRuleModel $ruleModel;
    protected InterfaceModel $interfaceModel;
    protected IpcClient $ipc;

    public function __construct()
    {
        $this->shaperModel    = new QosShaperModel();
        $this->ruleModel      = new QosRuleModel();
        $this->interfaceModel = new InterfaceModel();
        $this->ipc            = \Config\Services::ipc();
    }

    public function index()
    {
        $shapers = $this->shaperModel->findAll();
        $rules   = $this->ruleModel->findAll();

        // Seed default WAN shaper with CAKE if none exist
        if (empty($shapers)) {
            $wanId = $this->shaperModel->insert([
                'interface'           => 'wan0',
                'enabled'             => 1,
                'bandwidth_down_mbps' => 100.0,
                'bandwidth_up_mbps'   => 50.0,
                'scheduler'           => 'cake',
                'diffserv'            => 'diffserv4',
                'flow_isolation'      => 'triple-isolate',
                'ack_filter'          => 1,
                'rtt_ms'              => 100,
                'description'         => 'WAN Ingress/Egress Bufferbloat Elimination (CAKE)',
            ]);

            $this->ruleModel->insert([
                'shaper_id'       => $wanId,
                'name'            => 'VoIP & Realtime Audio (SIP/RTP)',
                'priority'        => 1,
                'protocol'        => 'udp',
                'src_ip'          => 'any',
                'dst_ip'          => 'any',
                'dst_port'        => '5060,10000-20000',
                'dscp_class'      => 'EF',
                'bandwidth_share' => 15,
                'enabled'         => 1,
            ]);

            $this->ruleModel->insert([
                'shaper_id'       => $wanId,
                'name'            => 'Interactive Gaming & SSH/WireGuard',
                'priority'        => 2,
                'protocol'        => 'udp',
                'src_ip'          => 'any',
                'dst_ip'          => 'any',
                'dst_port'        => '51820,22',
                'dscp_class'      => 'CS6',
                'bandwidth_share' => 20,
                'enabled'         => 1,
            ]);

            $this->ruleModel->insert([
                'shaper_id'       => $wanId,
                'name'            => 'Bulk Downloads & P2P (Low Priority)',
                'priority'        => 8,
                'protocol'        => 'any',
                'src_ip'          => 'any',
                'dst_ip'          => 'any',
                'dst_port'        => 'any',
                'dscp_class'      => 'CS1',
                'bandwidth_share' => 5,
                'enabled'         => 1,
            ]);

            $shapers = $this->shaperModel->findAll();
            $rules   = $this->ruleModel->findAll();
        }

        $interfaces = $this->interfaceModel->findAll();
        if (empty($interfaces)) {
            $interfaces = [
                ['name' => 'wan0', 'label' => 'WAN (Internet)'],
                ['name' => 'lan0', 'label' => 'LAN (Internal)'],
            ];
        }

        $qosStatus = $this->ipc->call('qos.status')['result'] ?? [];
        $scheduleModel = new \App\Models\ScheduleModel();
        $schedules = $scheduleModel->orderBy('name', 'ASC')->findAll();

        $data = [
            'title'      => 'Traffic Shaping & QoS (CAKE / FQ-CoDel)',
            'shapers'    => $shapers,
            'rules'      => $rules,
            'interfaces' => $interfaces,
            'schedules'  => $schedules,
            'qosStatus'  => $qosStatus,
        ];

        return view('qos/index', $data);
    }

    public function saveShaper()
    {
        $id = $this->request->getPost('id');
        $data = [
            'interface'           => $this->request->getPost('interface'),
            'enabled'             => $this->request->getPost('enabled') ? 1 : 0,
            'bandwidth_down_mbps' => (float) $this->request->getPost('bandwidth_down_mbps'),
            'bandwidth_up_mbps'   => (float) $this->request->getPost('bandwidth_up_mbps'),
            'scheduler'           => $this->request->getPost('scheduler'),
            'diffserv'            => $this->request->getPost('diffserv'),
            'flow_isolation'      => $this->request->getPost('flow_isolation'),
            'ack_filter'          => $this->request->getPost('ack_filter') ? 1 : 0,
            'rtt_ms'              => (int) $this->request->getPost('rtt_ms'),
            'description'         => $this->request->getPost('description'),
        ];

        if ($id) {
            $this->shaperModel->update($id, $data);
        } else {
            $this->shaperModel->insert($data);
        }

        [$key, $message] = $this->ipcFlash(
            $this->applier()->qos(),
            'Traffic Shaper queue policy updated and loaded.',
            'Saved, but the QoS apply'
        );

        return redirect()->to('/qos')->with($key, $message);
    }

    public function deleteShaper($id)
    {
        $this->shaperModel->delete($id);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->qos(),
            'Traffic Shaper removed.',
            'Removed, but the QoS apply'
        );

        return redirect()->to('/qos')->with($key, $message);
    }

    public function saveRule()
    {
        $id = $this->request->getPost('id');
        $data = [
            'shaper_id'       => (int) $this->request->getPost('shaper_id'),
            'name'            => $this->request->getPost('name'),
            'priority'        => (int) $this->request->getPost('priority'),
            'protocol'        => $this->request->getPost('protocol') ?? 'any',
            'src_ip'          => $this->request->getPost('src_ip') ?: 'any',
            'dst_ip'          => $this->request->getPost('dst_ip') ?: 'any',
            'dst_port'        => $this->request->getPost('dst_port') ?: 'any',
            'dscp_class'      => $this->request->getPost('dscp_class') ?? 'EF',
            'bandwidth_share' => (int) $this->request->getPost('bandwidth_share'),
            'schedule'        => $this->request->getPost('schedule') ?: 'always',
            'enabled'         => $this->request->getPost('enabled') ? 1 : 0,
        ];

        if ($id) {
            $this->ruleModel->update($id, $data);
        } else {
            $this->ruleModel->insert($data);
        }

        [$key, $message] = $this->ipcFlash(
            $this->applier()->qos(),
            'Traffic classification rule saved.',
            'Saved, but the QoS apply'
        );

        return redirect()->to('/qos')->with($key, $message);
    }

    public function deleteRule($id)
    {
        $this->ruleModel->delete($id);

        [$key, $message] = $this->ipcFlash(
            $this->applier()->qos(),
            'Traffic classification rule deleted.',
            'Deleted, but the QoS apply'
        );

        return redirect()->to('/qos')->with($key, $message);
    }

    public function apply()
    {
        [$key, $message] = $this->ipcFlash(
            $this->applier()->qos(),
            'Kernel TC qdisc queues and DSCP mangle rules applied successfully.',
            'QoS apply'
        );

        return redirect()->to('/qos')->with($key, $message);
    }
}

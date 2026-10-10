<?php

namespace App\Controllers;

use App\Models\ScheduleModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Schedule Controller — Manages reusable time/day schedule objects for:
 *   - Firewall Rules & Access Policies (/firewall/rules)
 *   - Destination NAT Port-Forwards & 1:1 NAT (/nat)
 *   - QoS Traffic Shaper & Priority Queues (/qos)
 *   - DoS / DDoS Mitigation Policies (/firewall/dos)
 */
class Schedule extends BaseController
{
    protected ScheduleModel $scheduleModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->scheduleModel = new ScheduleModel();
    }

    /**
     * List all Schedule Objects
     */
    public function index(): string
    {
        $db = \Config\Database::connect();
        $schedules = $this->scheduleModel->orderBy('name', 'ASC')->findAll();

        // Calculate usage references across subsystems
        $usage = [];
        foreach ($schedules as $s) {
            $name = $s['name'];
            $fwCount = $db->table('firewall_rules')->where('schedule', $name)->countAllResults();
            $natCount = $db->tableExists('nat_dnat') ? $db->table('nat_dnat')->where('schedule', $name)->countAllResults() : 0;
            $qosCount = $db->tableExists('qos_rules') ? $db->table('qos_rules')->where('schedule', $name)->countAllResults() : 0;
            $dosCount = $this->dosHasSchedule($db) ? $db->table('dos_policies')->where('schedule', $name)->countAllResults() : 0;

            $usage[$s['id']] = [
                'firewall' => $fwCount,
                'nat'      => $natCount,
                'qos'      => $qosCount,
                'dos'      => $dosCount,
                'total'    => $fwCount + $natCount + $qosCount + $dosCount,
            ];
        }

        return view('firewall/schedules', [
            'title'      => 'Firewall Schedules — MicronOS',
            'breadcrumb' => 'Policy: Schedules',
            'schedules'  => $schedules,
            'usage'      => $usage,
        ]);
    }

    /**
     * Create a new Schedule Object
     */
    public function store(): RedirectResponse
    {
        $name = trim((string) $this->request->getPost('name'));
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($name));

        if ($name === '') {
            return redirect()->to(base_url('firewall/schedules'))->with('error', 'Schedule name is required.');
        }

        if ($this->scheduleModel->where('name', $name)->countAllResults() > 0) {
            return redirect()->to(base_url('firewall/schedules'))->with('error', "A schedule named '{$name}' already exists.");
        }

        $type = $this->request->getPost('type') === 'onetime' ? 'onetime' : 'recurring';
        $id   = 'sched_' . bin2hex(random_bytes(6));

        $data = [
            'id'             => $id,
            'name'           => $name,
            'type'           => $type,
            'description'    => trim((string) ($this->request->getPost('description') ?: '')),
            'is_system'      => 0,
        ];

        if ($type === 'recurring') {
            $days = $this->request->getPost('days');
            if (empty($days) || in_array('all', (array) $days, true)) {
                $daysStr = 'all';
            } else {
                $daysStr = implode(',', array_intersect((array) $days, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']));
            }
            $data['days_of_week'] = $daysStr ?: 'all';
            
            $startTime = trim((string) $this->request->getPost('start_time') ?: '00:00');
            $endTime   = trim((string) $this->request->getPost('end_time') ?: '23:59');
            $data['start_time'] = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
            $data['end_time']   = strlen($endTime) === 5 ? $endTime . ':59' : $endTime;
            $data['start_datetime'] = null;
            $data['end_datetime']   = null;
        } else {
            $data['days_of_week'] = 'all';
            $data['start_time']   = '00:00:00';
            $data['end_time']     = '23:59:59';
            $data['start_datetime'] = $this->request->getPost('start_datetime') ?: null;
            $data['end_datetime']   = $this->request->getPost('end_datetime') ?: null;
        }

        $this->scheduleModel->insert($data);

        return redirect()->to(base_url('firewall/schedules'))
            ->with('success', "Schedule '{$name}' created successfully.");
    }

    /**
     * Update an existing Schedule Object
     */
    public function update(string $id): RedirectResponse
    {
        $schedule = $this->scheduleModel->find($id);
        if (! $schedule) {
            return redirect()->to(base_url('firewall/schedules'))->with('error', 'Schedule not found.');
        }

        $type = $this->request->getPost('type') === 'onetime' ? 'onetime' : 'recurring';
        $data = [
            'type'        => $type,
            'description' => trim((string) ($this->request->getPost('description') ?: '')),
        ];

        // Only allow renaming if not system protected
        if ((int) $schedule['is_system'] === 0) {
            $name = trim((string) $this->request->getPost('name'));
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($name));
            if ($name !== '' && $name !== $schedule['name']) {
                $dup = $this->scheduleModel->where('name', $name)->where('id !=', $id)->countAllResults();
                if ($dup > 0) {
                    return redirect()->to(base_url('firewall/schedules'))->with('error', "A schedule named '{$name}' already exists.");
                }
                $data['name'] = $name;

                // Cascade rename to rules if name changed
                $db = \Config\Database::connect();
                $db->table('firewall_rules')->where('schedule', $schedule['name'])->update(['schedule' => $name]);
                if ($db->tableExists('nat_dnat')) {
                    $db->table('nat_dnat')->where('schedule', $schedule['name'])->update(['schedule' => $name]);
                }
                if ($db->tableExists('qos_rules')) {
                    $db->table('qos_rules')->where('schedule', $schedule['name'])->update(['schedule' => $name]);
                }
                if ($this->dosHasSchedule($db)) {
                    $db->table('dos_policies')->where('schedule', $schedule['name'])->update(['schedule' => $name]);
                }
            }
        }

        if ($type === 'recurring') {
            $days = $this->request->getPost('days');
            if (empty($days) || in_array('all', (array) $days, true)) {
                $daysStr = 'all';
            } else {
                $daysStr = implode(',', array_intersect((array) $days, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']));
            }
            $data['days_of_week'] = $daysStr ?: 'all';

            $startTime = trim((string) $this->request->getPost('start_time') ?: '00:00');
            $endTime   = trim((string) $this->request->getPost('end_time') ?: '23:59');
            $data['start_time'] = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
            $data['end_time']   = strlen($endTime) === 5 ? $endTime . ':59' : $endTime;
            $data['start_datetime'] = null;
            $data['end_datetime']   = null;
        } else {
            $data['days_of_week'] = 'all';
            $data['start_time']   = '00:00:00';
            $data['end_time']     = '23:59:59';
            $data['start_datetime'] = $this->request->getPost('start_datetime') ?: null;
            $data['end_datetime']   = $this->request->getPost('end_datetime') ?: null;
        }

        $this->scheduleModel->update($id, $data);

        return redirect()->to(base_url('firewall/schedules'))
            ->with('success', 'Schedule updated successfully.');
    }

    /**
     * Enable / disable a Schedule Object.
     *
     * Disabling pauses every Firewall policy, DNAT rule and QoS rule that
     * references the schedule — they are left out of the compiled ruleset
     * until the schedule is enabled again.
     */
    public function toggle(string $id): RedirectResponse
    {
        $schedule = $this->scheduleModel->find($id);
        if (! $schedule) {
            return redirect()->to(base_url('firewall/schedules'))->with('error', 'Schedule not found.');
        }

        if ((int) $schedule['is_system'] === 1 || strtolower($schedule['name']) === 'always') {
            return redirect()->to(base_url('firewall/schedules'))->with('error', 'System protected schedule cannot be disabled.');
        }

        $newState = (int) ($schedule['enabled'] ?? 1) === 1 ? 0 : 1;
        $this->scheduleModel->update($id, ['enabled' => $newState]);

        $msg = $newState === 1
            ? "Schedule '{$schedule['name']}' enabled. Rules using it are active again within their time window."
            : "Schedule '{$schedule['name']}' disabled. Rules using it are paused.";

        return redirect()->to(base_url('firewall/schedules'))->with('success', $msg);
    }

    /**
     * Delete a Schedule Object
     */
    public function delete(string $id): RedirectResponse
    {
        $schedule = $this->scheduleModel->find($id);
        if (! $schedule) {
            return redirect()->to(base_url('firewall/schedules'))->with('error', 'Schedule not found.');
        }

        if ((int) $schedule['is_system'] === 1 || strtolower($schedule['name']) === 'always') {
            return redirect()->to(base_url('firewall/schedules'))->with('error', 'System protected schedule cannot be deleted.');
        }

        $db = \Config\Database::connect();
        // Reset any referencing rules back to 'always' before deleting
        $db->table('firewall_rules')->where('schedule', $schedule['name'])->update(['schedule' => 'always']);
        if ($db->tableExists('nat_dnat')) {
            $db->table('nat_dnat')->where('schedule', $schedule['name'])->update(['schedule' => 'always']);
        }
        if ($db->tableExists('qos_rules')) {
            $db->table('qos_rules')->where('schedule', $schedule['name'])->update(['schedule' => 'always']);
        }
        if ($this->dosHasSchedule($db)) {
            $db->table('dos_policies')->where('schedule', $schedule['name'])->update(['schedule' => 'always']);
        }

        $this->scheduleModel->delete($id);

        return redirect()->to(base_url('firewall/schedules'))
            ->with('success', "Schedule '{$schedule['name']}' deleted. Active policies using it have reverted to 'always'.");
    }

    /**
     * Whether dos_policies exists and has been migrated with a schedule column.
     */
    protected function dosHasSchedule($db): bool
    {
        return $db->tableExists('dos_policies') && $db->fieldExists('schedule', 'dos_policies');
    }
}

<?php

namespace App\Controllers;

use App\Libraries\BackupEngine;
use App\Libraries\IpcClient;
use App\Models\UserModel;

/**
 * Backup Controller — AES-256-GCM encrypted system configuration snapshots and restoration
 */
class Backup extends BaseController
{
    private IpcClient $ipc;
    private BackupEngine $engine;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
        $this->engine = new BackupEngine();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();
        $backups = $db->table('system_backups')->orderBy('created_at', 'DESC')->get()->getResultArray();

        return view('backup/index', [
            'title'      => 'Backup & Restore — MicronOS',
            'breadcrumb' => 'Backup & Restore',
            'backups'    => $backups,
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    public function create(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $password = $this->request->getPost('encryption_password') ?: null;
        $description = trim($this->request->getPost('description') ?: 'Manual Configuration Snapshot');

        // §7.6 R5: the archive password is credential-grade — it is what stands
        // between a recovered archive and every connector secret it carries.
        if ($password !== null) {
            if (strlen($password) < 12) {
                return redirect()->to(base_url('backup'))
                    ->with('error', 'The backup password must be at least 12 characters; it protects connector credentials, not just configuration.');
            }

            $user = session('user_id') ? (new UserModel())->find(session('user_id')) : null;
            if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
                return redirect()->to(base_url('backup'))
                    ->with('error', 'The backup password must not be the same as your login password.');
            }
        }

        try {
            $backupData = $this->engine->createBackup($password);

            $backupDir = WRITEPATH . 'backups/';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $filePath = $backupDir . $backupData['filename'];
            file_put_contents($filePath, $backupData['content']);

            $backupId = 'bkp_' . bin2hex(random_bytes(8));
            $db->table('system_backups')->insert([
                'id'                  => $backupId,
                'filename'            => $backupData['filename'],
                'file_path'           => $filePath,
                'file_size_bytes'     => $backupData['size'],
                'is_encrypted'         => $backupData['is_encrypted'] ? 1 : 0,
                'credentials_included' => !empty($backupData['credentials_included']) ? 1 : 0,
                'sha256_checksum'     => $backupData['sha256'],
                'backup_type'         => 'full',
                'storage_destination' => 'local',
                'created_by'          => session()->get('username') ?? 'Ministrator',
                'description'         => $description,
                'created_at'          => date('Y-m-d H:i:s')
            ]);

            $credentialNote = !empty($backupData['credentials_included'])
                ? ' Connector credentials and the master key are included inside the encrypted envelope; store the password separately.'
                : '';

            return redirect()->to(base_url('backup'))
                ->with('success', "Configuration backup '{$backupData['filename']}' created successfully (" . round($backupData['size'] / 1024, 1) . " KB).{$credentialNote}");
        } catch (\Throwable $e) {
            return redirect()->to(base_url('backup'))
                ->with('error', 'Failed to create backup: ' . $e->getMessage());
        }
    }

    public function download(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $backup = $db->table('system_backups')->where('id', $id)->get()->getRowArray();

        if (!$backup || !file_exists($backup['file_path'])) {
            return $this->response->setStatusCode(404)->setBody('Backup archive not found.');
        }

        $content = file_get_contents($backup['file_path']);
        return $this->response
            ->setHeader('Content-Type', 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $backup['filename'] . '"')
            ->setBody($content);
    }

    /**
     * The tables each selective-restore section covers.
     *
     * Every table named here must be one the archive actually carries, or the
     * operator picks a section and silently does not get it — asserted by
     * `BackupRepositoryRestoreTest::testEverySelectableSectionNamesATableTheArchiveCarries`.
     *
     * @var array<string, list<string>>
     */
    protected const RESTORE_SECTIONS = [
        'firewall'     => ['firewall_rules', 'nat_dnat', 'nat_snat_pools', 'nat_nptv6', 'nat_settings'],
        'vpn'          => ['openvpn_servers', 'wireguard_tunnels', 'wireguard_peers', 'ipsec_tunnels'],
        'ha'           => ['ha_settings', 'ha_virtual_ips'],
        'qos'          => ['qos_shapers', 'qos_rules'],
        'certificates' => ['pki_authorities', 'pki_certificates'],
        'identity'     => ['users', 'roles', 'permissions', 'role_permissions', 'user_roles', 'ldap_servers'],
        'webfilter'    => ['webfilter_settings', 'webfilter_categories'],
        'network'      => ['interfaces'],
        // The curated plugin repositories and the signing keys that anchor them
        // (§5.2). Restoring them is what lets a rebuilt appliance reach its
        // Class C plugin packages again.
        'repositories' => ['plugin_repos'],
    ];

    /**
     * Translate the posted `sections[]` into the table list a restore is limited
     * to. An empty result means "every table in the archive".
     *
     * @param mixed $sections
     *
     * @return list<string>
     */
    protected function selectedTablesFor($sections): array
    {
        if (!is_array($sections) || $sections === [] || in_array('all', $sections, true)) {
            return [];
        }

        $tables = [];
        foreach ($sections as $section) {
            foreach (self::RESTORE_SECTIONS[(string) $section] ?? [] as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    public function restore(): \CodeIgniter\HTTP\RedirectResponse
    {
        $file = $this->request->getFile('backup_file');
        $password = $this->request->getPost('encryption_password') ?: null;

        if (!$file || !$file->isValid()) {
            return redirect()->to(base_url('backup'))->with('error', 'Please upload a valid backup file.');
        }

        try {
            $rawContent = file_get_contents($file->getTempName());
            
            if ($password) {
                $decrypted = $this->engine->decryptPayload($rawContent, $password);
                if (!$decrypted) {
                    return redirect()->to(base_url('backup'))->with('error', 'Decryption failed. Invalid encryption password.');
                }
                $rawContent = $decrypted;
            }

            $data = json_decode($rawContent, true);
            if (!$data || !isset($data['tables'])) {
                return redirect()->to(base_url('backup'))->with('error', 'Corrupt or incompatible backup package format.');
            }

            $nicMap = $this->request->getPost('nic_map') ?? [];
            if (is_string($nicMap)) {
                $nicMap = json_decode($nicMap, true) ?: [];
            }
            $srcNics = $this->request->getPost('nic_map_src');
            $dstNics = $this->request->getPost('nic_map_dst');
            if (is_array($srcNics) && is_array($dstNics)) {
                foreach ($srcNics as $idx => $src) {
                    $src = trim((string)$src);
                    $dst = trim((string)($dstNics[$idx] ?? ''));
                    if ($src !== '' && $dst !== '') {
                        $nicMap[$src] = $dst;
                    }
                }
            }

            $selectedTables = $this->selectedTablesFor($this->request->getPost('sections'));

            // §7.6: replacing an existing connector master key is an explicit,
            // deliberate decision — never a side effect of a restore.
            $replaceKey = (bool) $this->request->getPost('replace_master_key');

            [$result, $repoNote] = $this->applyRestore($data, $nicMap, $selectedTables, $replaceKey);

            [$flashType, $flashMessage] = $this->restoreFlash($result, $repoNote);

            return redirect()->to(base_url('backup'))->with($flashType, $flashMessage);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('backup'))
                ->with('error', 'Restoration error: ' . $e->getMessage());
        }
    }

    /**
     * Restore a decoded archive and reconcile the daemon with what it restored.
     *
     * Split out of `restore()` so the sequence is exercisable without an upload:
     * `UploadedFile::isValid()` requires `is_uploaded_file()`, which no feature
     * test can satisfy, so the tests drive this method instead of the upload path.
     *
     * The reconcile runs *before* the commit so the commit captures the sources
     * the restore introduced rather than the ones it replaced.
     *
     * @param array<string, mixed> $data           decoded archive
     * @param array<string, string> $nicMap
     * @param list<string>          $selectedTables
     *
     * @return array{0: array<string, mixed>, 1: array{ok: bool, message: string}|null}
     */
    protected function applyRestore(array $data, array $nicMap, array $selectedTables, bool $replaceKey): array
    {
        $result = $this->engine->restore($data, $nicMap, $selectedTables, $replaceKey);

        $repoNote = $this->reapplyRepositories($result);

        $this->ipc->call('system.commit');

        return [$result, $repoNote];
    }

    /**
     * Re-apply the curated plugin repositories a restore brought back.
     *
     * The registry is a table here, but the appliance's apt state is files the
     * daemon generated: `/etc/apt/sources.list.d/micron-<id>.sources`, its
     * keyring and its pin (§5.2). Restoring the rows alone would leave a Class C
     * plugin `blocked` for a reason the operator cannot see on the Repositories
     * page — "registered and enabled" in the database while the sources file the
     * manifest's `requires.repos` needs does not exist on the appliance.
     *
     * Reconciliation is declarative and idempotent: the enabled set is applied
     * and any file MicronOS generated for a repository the archive does not carry
     * is retired. Nothing is fetched from the vendor, because the archived rows
     * carry the vendor's armored key inline.
     *
     * @param array<string, mixed> $result the restore report
     *
     * Protected rather than private: the archive upload is read through
     * `UploadedFile::isValid()`, which requires `is_uploaded_file()` and so can
     * never be satisfied by a feature test. This method is the seam the restore
     * tests drive, rather than weakening the upload check to make it testable.
     *
     * @return array{ok: bool, message: string}|null null when the archive carried
     *                                               no repository registry at all
     */
    protected function reapplyRepositories(array $result): ?array
    {
        if (!in_array('plugin_repos', $result['restored_tables'] ?? [], true)) {
            return null;
        }

        try {
            $applied = \Config\Services::repoRegistry()->apply();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Curated plugin repositories could not be re-applied: ' . $e->getMessage()];
        }

        if (($applied['success'] ?? false) !== true) {
            $detail = (string) ($applied['message'] ?? 'the daemon refused the plan');

            return ['ok' => false, 'message' => 'The curated plugin repositories were restored but NOT applied to the appliance (' . $detail . '); plugins needing them stay blocked until an apply on the Repositories page succeeds.'];
        }

        $count = count($applied['repos'] ?? []);

        return [
            'ok'      => true,
            'message' => $count === 0
                ? 'The archive carried no enabled curated plugin repository, so the daemon was reconciled to none.'
                : sprintf(
                    '%d curated plugin %s re-applied to the appliance with the archived signing %s.',
                    $count,
                    $count === 1 ? 'repository was' : 'repositories were',
                    $count === 1 ? 'key' : 'keys',
                ),
        ];
    }

    /**
     * Translates a restore report into a flash type and message, so a degraded
     * restore that leaves connectors needing credentials is visible rather than
     * reported as an unqualified success (§7.6).
     *
     * @param array<string, mixed>                 $result
     * @param array{ok: bool, message: string}|null $repoNote
     *
     * @return array{0: string, 1: string} [flash type, message]
     */
    protected function restoreFlash(array $result, ?array $repoNote = null): array
    {
        if (empty($result['success'])) {
            return ['error', 'Restore transaction failed; the previous configuration was kept.'];
        }

        $verify  = is_array($result['verification'] ?? null) ? $result['verification'] : [];
        $message = match ($result['key_action'] ?? 'none') {
            'installed'        => 'Configuration restored. The connector master key was installed from the archive and credentials were verified.',
            'unchanged'        => 'Configuration restored. The connector master key matched this appliance.',
            'replaced'         => 'Configuration restored. The connector master key was replaced, as confirmed.',
            'skipped_conflict' => 'Configuration restored, but connector credentials were NOT restored: the archive carries a different master key. Re-enter every connector credential, or retry with key replacement explicitly confirmed.',
            'absent'           => 'Configuration restored. The archive carries no connector master key, so connector credentials are unavailable and must be re-entered.',
            default            => 'Configuration successfully restored from archive.',
        };

        if (($verify['checked'] ?? 0) > 0 && empty($verify['ok'])) {
            $message .= ' Post-restore verification failed for ' . count($verify['failed'] ?? []) . ' credential(s); re-enter them.';
        }

        $warnings = array_slice($result['warnings'] ?? [], 0, 3);
        if ($warnings !== []) {
            $message .= ' (' . implode('; ', $warnings) . ')';
        }

        if ($repoNote !== null) {
            $message .= ' ' . $repoNote['message'];
        }

        $needsAttention = !empty($result['needs_credentials'])
            || (($verify['checked'] ?? 0) > 0 && empty($verify['ok']))
            || ($repoNote !== null && $repoNote['ok'] === false);

        return [$needsAttention ? 'error' : 'success', $message];
    }

    public function delete(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $backup = $db->table('system_backups')->where('id', $id)->get()->getRowArray();

        if ($backup) {
            if (file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }
            $db->table('system_backups')->where('id', $id)->delete();
            return redirect()->to(base_url('backup'))->with('success', 'Backup removed.');
        }

        return redirect()->to(base_url('backup'))->with('error', 'Backup record not found.');
    }
}

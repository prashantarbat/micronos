<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * MicronOS Encrypted Backup & Hardware NIC Remapping Engine
 * =========================================================
 * Manages atomic appliance configuration backups, AES-256-GCM encryption with PBKDF2,
 * SHA-256 integrity validation, and intelligent NIC translation when restoring
 * configurations onto dissimilar hardware.
 */
class BackupEngine
{
    /**
     * The marker that identifies an encrypted archive.
     *
     * Single-sourced because three separate jobs depend on recognising it: the
     * writer that produces the envelope, the restorer that must decrypt it, and
     * the verifier that must know a passphrase is required before it can
     * recompute the payload digest.
     */
    public const ENVELOPE_MAGIC = 'MICRON_ENC_V1';

    protected ?BaseConnection $db;

    protected ConnectorKeyStore $keyStore;

    protected SecretsVault $secretsVault;

    public function __construct(?BaseConnection $db = null, ?ConnectorKeyStore $keyStore = null)
    {
        $this->db           = $db ?? \Config\Database::connect();
        $this->keyStore     = $keyStore ?? new ConnectorKeyStore();
        $this->secretsVault = new SecretsVault($this->keyStore);
    }

    /**
     * Creates a full configuration backup package.
     *
     * @param string|null $encryptionPassword Optional passphrase to encrypt with AES-256-GCM
     * @return array Backup metadata and payload ['filename' => string, 'content' => string, 'sha256' => string, 'is_encrypted' => bool]
     */
    public function createBackup(?string $encryptionPassword = null): array
    {
        // Connector credentials travel only inside the password-encrypted
        // envelope (§7.6 R1/R2), so this must be known before the payload is
        // assembled.
        $isEncrypted = $encryptionPassword !== null && $encryptionPassword !== '';

        $data = [
            'format'               => 2,
            'micronos_version'     => '1.0.0',
            'timestamp'            => date('c'),
            'hostname'             => gethostname() ?: 'micronos-appliance',
            'is_encrypted'         => $isEncrypted,
            'credentials_included' => false,
            'hardware'             => [
                'interfaces' => $this->db->table('interfaces')->get()->getResultArray(),
            ],
            'tables'               => [
                'interfaces'            => $this->db->table('interfaces')->get()->getResultArray(),
                'firewall_rules'        => $this->db->table('firewall_rules')->get()->getResultArray(),
                'nat_dnat'              => $this->db->table('nat_dnat')->get()->getResultArray(),
                'nat_snat_pools'          => $this->db->table('nat_snat_pools')->get()->getResultArray(),
                // The selective-restore "firewall" section covers NAT NPTv6 and
                // the global NAT settings, so the archive has to carry them or
                // choosing that section silently leaves them behind.
                'nat_nptv6'             => $this->db->table('nat_nptv6')->get()->getResultArray(),
                'nat_settings'          => $this->db->table('nat_settings')->get()->getResultArray(),
                'ipsec_tunnels'         => $this->db->table('ipsec_tunnels')->get()->getResultArray(),
                'wireguard_tunnels'     => $this->db->table('wireguard_tunnels')->get()->getResultArray(),
                'wireguard_peers'       => $this->db->table('wireguard_peers')->get()->getResultArray(),
                'openvpn_servers'       => $this->db->table('openvpn_servers')->get()->getResultArray(),
                'webfilter_settings'    => $this->db->table('webfilter_settings')->get()->getResultArray(),
                'webfilter_categories'  => $this->db->table('webfilter_categories')->get()->getResultArray(),
                'ha_settings'           => $this->db->table('ha_settings')->get()->getResultArray(),
                'ha_virtual_ips'        => $this->db->table('ha_virtual_ips')->get()->getResultArray(),
                'qos_shapers'           => $this->db->table('qos_shapers')->get()->getResultArray(),
                'qos_rules'             => $this->db->table('qos_rules')->get()->getResultArray(),
                'pki_authorities'       => $this->db->table('pki_authorities')->get()->getResultArray(),
                'pki_certificates'      => $this->db->table('pki_certificates')->get()->getResultArray(),
                'roles'                 => $this->db->table('roles')->get()->getResultArray(),
                'permissions'           => $this->db->table('permissions')->get()->getResultArray(),
                'role_permissions'      => $this->db->table('role_permissions')->get()->getResultArray(),
                'users'                 => $this->db->table('users')->get()->getResultArray(),
                'user_roles'            => $this->db->table('user_roles')->get()->getResultArray(),
                'ldap_servers'          => $this->db->table('ldap_servers')->get()->getResultArray(),
            ]
        ];

        // Connector state. Non-secret configuration is always archived; the
        // `connector_secrets` ciphertext rows are archived only inside the
        // encrypted envelope, so a plaintext archive never contains a connector
        // credential or the means to read one (§7.6 R1/R2).
        if ($this->db->tableExists('connector_instances')) {
            $data['tables']['connector_instances'] = $this->db->table('connector_instances')->get()->getResultArray();
        }
        if ($this->db->tableExists('connector_targets')) {
            $data['tables']['connector_targets'] = $this->db->table('connector_targets')->get()->getResultArray();
        }

        // Curated plugin repositories (§5.2). Archived in *both* archive forms,
        // unlike the connector ciphertext below, because a repository row holds
        // no secret: `key_armor` is the vendor's armored *public* signing key
        // (which the vendor publishes at `key_url` anyway) and `key_fingerprint`
        // is the trust anchor the operator declared out of band.
        //
        // The rows have to travel with the configuration rather than be
        // re-registered by hand, for the reason §5.2 caches the key inline in the
        // first place: a repository is a trust anchor, and a restore onto a
        // rebuilt appliance must reproduce it exactly — including, for a plaintext
        // archive, the case where the vendor no longer serves the key, or the
        // appliance cannot reach the vendor at all.
        if ($this->db->tableExists('plugin_repos')) {
            $data['tables']['plugin_repos'] = $this->db->table('plugin_repos')->get()->getResultArray();
        }

        // Installed plugins, their capability grants and their settings (§10,
        // §13). The first two hold no secret — a manifest is the public shape of
        // a plugin and a grant is a capability name the operator already approved
        // — so they travel in both archive forms, exactly like the repository
        // registry above: a rebuilt appliance must come back knowing which
        // plugins it owed its packages to without the operator re-approving every
        // capability by hand.
        if ($this->db->tableExists('plugins')) {
            $data['tables']['plugins'] = $this->db->table('plugins')->get()->getResultArray();
        }
        if ($this->db->tableExists('plugin_grants')) {
            $data['tables']['plugin_grants'] = $this->db->table('plugin_grants')->get()->getResultArray();
        }

        // Settings are the one mixed table: a plugin may hold an API key or token
        // (`is_secret`) beside ordinary configuration. The non-secret rows are
        // configuration and travel in both forms; the secret rows get the §7.6
        // encrypted-only treatment — held out of a plaintext archive exactly as
        // `connector_secrets` is, because PL7 puts a plugin's secret-valued
        // settings in the same credential class. The two sets are split here, and
        // the secret half is added back below only when the archive is encrypted.
        $secretPluginSettings = [];
        if ($this->db->tableExists('plugin_settings')) {
            $pluginSettings = $this->db->table('plugin_settings')->get()->getResultArray();

            $data['tables']['plugin_settings'] = array_values(array_filter(
                $pluginSettings,
                static fn (array $row): bool => (int) ($row['is_secret'] ?? 0) === 0,
            ));

            $secretPluginSettings = array_values(array_filter(
                $pluginSettings,
                static fn (array $row): bool => (int) ($row['is_secret'] ?? 0) === 1,
            ));
        }

        $credentialsIncluded = false;

        if ($isEncrypted) {
            if ($this->db->tableExists('connector_secrets')) {
                $secretRows = $this->db->table('connector_secrets')->get()->getResultArray();
                if ($secretRows !== []) {
                    $data['tables']['connector_secrets'] = $secretRows;
                    $credentialsIncluded                 = true;
                }
            }

            // Secret-valued plugin settings, which a plaintext archive never
            // carries. Merged into the same `plugin_settings` key rather than a
            // second one, because a restore writes a table as a whole: two keys
            // naming the same table would truncate each other and the second
            // insert would drop the first set of rows.
            if ($secretPluginSettings !== []) {
                $data['tables']['plugin_settings'] = array_merge(
                    $data['tables']['plugin_settings'] ?? [],
                    $secretPluginSettings,
                );
                $credentialsIncluded = true;
            }

            // The master key travels in a dedicated top-level `secrets` object,
            // deliberately outside `tables`, so a restore can never insert key
            // material into a database table by accident (§7.6).
            $masterKey = $this->keyStore->read();
            if ($masterKey !== null) {
                $data['secrets'] = [
                    'master_key'  => bin2hex($masterKey),
                    'key_version' => 1,
                    'created_at'  => date('c'),
                ];
                $credentialsIncluded = true;
            }
        }

        $data['credentials_included'] = $credentialsIncluded;

        $jsonPayload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $rawSha256 = hash('sha256', $jsonPayload);

        if ($isEncrypted) {
            $finalPayload = $this->encryptPayload($jsonPayload, $encryptionPassword);
            $filename = 'micronos-backup-' . date('Ymd-His') . '.mbk.enc';
        } else {
            $finalPayload = $jsonPayload;
            $filename = 'micronos-backup-' . date('Ymd-His') . '.mbk';
        }

        return [
            'filename'             => $filename,
            'content'              => $finalPayload,
            'sha256'               => $rawSha256,
            'is_encrypted'         => $isEncrypted,
            'credentials_included' => $credentialsIncluded,
            'size'                 => strlen($finalPayload)
        ];
    }

    /**
     * Inspects a backup package and detects any NIC interface differences between the backup and current machine.
     */
    public function inspectHardwareDifferences(array $backupData, array $currentSystemNics): array
    {
        $backupNics = $backupData['hardware']['interfaces'] ?? ($backupData['tables']['interfaces'] ?? []);
        $backupDevs = array_unique(array_filter(array_column($backupNics, 'physical_device')));
        
        $currentDevs = array_unique(array_filter(array_column($currentSystemNics, 'name')));

        $missingInCurrent = array_diff($backupDevs, $currentDevs);
        $newInCurrent = array_diff($currentDevs, $backupDevs);

        return [
            'backup_devices'     => array_values($backupDevs),
            'current_devices'    => array_values($currentDevs),
            'missing_devices'    => array_values($missingInCurrent),
            'new_devices'        => array_values($newInCurrent),
            'remapping_required' => !empty($missingInCurrent)
        ];
    }

    /**
     * Restores backup data with optional NIC remapping and selective tables.
     *
     * Implements the connector key policy (§7.6): a key carried by the archive
     * is installed, matched, or — only with an explicit confirmation —
     * replaced; when no usable key can be established the connector ciphertext
     * tables are skipped rather than written undecryptable; and after restoring,
     * every connector secret is decryption-verified.
     *
     * @param array $backupData Decoded backup array
     * @param array $nicMap Associative map e.g. ['ens18' => 'eth0', 'ens19' => 'eth1']
     * @param array $selectedTables Optional list of table names to selectively restore
     * @param bool  $replaceKey True only when the operator confirmed replacing the
     *                          connector master key with the archive's key
     *
     * @return array{success: bool, key_action: string, needs_credentials: bool,
     *               verification: array, restored_tables: list<string>, warnings: list<string>}
     */
    public function restore(array $backupData, array $nicMap = [], array $selectedTables = [], bool $replaceKey = false): array
    {
        $tables               = is_array($backupData['tables'] ?? null) ? $backupData['tables'] : [];
        $payloadKey           = $this->payloadMasterKey($backupData);
        $carriesConnectorRows = $this->hasConnectorTables($tables);

        $warnings         = [];
        $needsCredentials = false;

        // 1. Establish the master key before any ciphertext is touched.
        $keyAction = 'none';
        if ($payloadKey !== null) {
            $current = $this->keyStore->read();
            if ($current === null) {
                if ($this->keyStore->write($payloadKey)) {
                    $keyAction = 'installed';
                } else {
                    $keyAction        = 'skipped_conflict';
                    $needsCredentials = true;
                    $warnings[]       = 'the connector master key could not be installed';
                }
            } elseif (hash_equals($current, $payloadKey)) {
                $keyAction = 'unchanged';
            } elseif ($replaceKey) {
                if ($this->keyStore->write($payloadKey, true)) {
                    $keyAction = 'replaced';
                } else {
                    $keyAction        = 'skipped_conflict';
                    $needsCredentials = true;
                    $warnings[]       = 'the connector master key could not be replaced';
                }
            } else {
                $keyAction        = 'skipped_conflict';
                $needsCredentials = true;
                $warnings[]       = 'the archive carries a different connector master key; connector credentials were not restored (explicit replacement was not confirmed)';
            }
        } elseif ($carriesConnectorRows) {
            $keyAction        = 'absent';
            $needsCredentials = true;
            $warnings[]       = 'the archive carries no connector master key; connector credentials were not restored';
        }

        // Connector ciphertext is restored only when a usable key is in place.
        $skipConnectorTables = in_array($keyAction, ['skipped_conflict', 'absent'], true);

        $this->db->transStart();
        $restoredTables = [];

        foreach ($tables as $tableName => $rows) {
            if ($skipConnectorTables && $this->isConnectorTable((string) $tableName)) {
                $warnings[] = "skipped {$tableName}: no usable connector master key";
                continue;
            }

            if (!empty($selectedTables) && !in_array($tableName, $selectedTables, true)) {
                continue;
            }

            if (!$this->db->tableExists($tableName)) {
                $warnings[] = "skipped {$tableName}: table is not present on this appliance";
                continue;
            }

            if (empty($rows)) {
                continue;
            }

            // Apply NIC remapping if restoring interfaces table
            if ($tableName === 'interfaces' && !empty($nicMap)) {
                foreach ($rows as &$row) {
                    $origDev = $row['physical_device'] ?? '';
                    if (isset($nicMap[$origDev])) {
                        $row['physical_device'] = $nicMap[$origDev];
                    }
                }
                unset($row);
            }

            if ($tableName === 'plugin_settings') {
                // Never a blanket truncate: a plaintext archive deliberately omits
                // the secret rows, so truncating would delete the appliance's own
                // plugin secrets rather than simply leave them unrestored.
                $this->restorePluginSettings($rows);
            } else {
                $this->db->table($tableName)->truncate();
                foreach ($rows as $row) {
                    $this->db->table($tableName)->insert($row);
                }
            }

            $restoredTables[] = $tableName;
        }

        $this->db->transComplete();
        $success = $this->db->transStatus();

        // 2. Post-restore verification is mandatory (§7.6): a restore must never
        // leave a connector that silently fails to authenticate.
        $verification = ['ok' => true, 'checked' => 0, 'failed' => [], 'reason' => null];
        $secretRows   = (!$skipConnectorTables && is_array($tables['connector_secrets'] ?? null))
            ? $tables['connector_secrets']
            : [];
        if ($secretRows !== []) {
            $verification = $this->secretsVault->verifyRows($secretRows);
            if (!$verification['ok']) {
                $needsCredentials = true;
                $warnings[]       = 'post-restore verification failed for one or more connector credentials';
            }
        }

        if ($needsCredentials) {
            $this->markConnectorsNeedsCredentials();
        }

        return [
            'success'           => $success,
            'key_action'        => $keyAction,
            'needs_credentials' => $needsCredentials,
            'verification'      => $verification,
            'restored_tables'   => $restoredTables,
            'warnings'          => $warnings,
        ];
    }

    /**
     * Writes the `plugin_settings` rows an archive carried, without destroying
     * the secret rows a plaintext archive could not carry.
     *
     * Every other table is restored by truncate-then-insert, and that is correct
     * when the archive holds the whole table. `plugin_settings` can hold less
     * than the whole table: a plaintext archive carries the non-secret rows only,
     * so truncating would delete the appliance's own plugin secrets — the
     * opposite of what §7.6 asks for, which is that a plaintext archive be free
     * of secrets, not a destroyer of them.
     *
     * An archive that does carry at least one secret row can only have come from
     * the encrypted envelope, and therefore holds the complete table; that case
     * is reproduced exactly. Otherwise the rows the archive names are replaced
     * in place and everything else is left standing.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function restorePluginSettings(array $rows): void
    {
        $carriesSecrets = false;
        foreach ($rows as $row) {
            if ((int) ($row['is_secret'] ?? 0) === 1) {
                $carriesSecrets = true;
                break;
            }
        }

        if ($carriesSecrets) {
            $this->db->table('plugin_settings')->truncate();
        } else {
            foreach ($rows as $row) {
                $this->db->table('plugin_settings')
                    ->where('plugin_id', $row['plugin_id'] ?? '')
                    ->where('setting_key', $row['setting_key'] ?? '')
                    ->delete();
            }
        }

        foreach ($rows as $row) {
            $this->db->table('plugin_settings')->insert($row);
        }
    }

    /**
     * The raw master key carried by a decoded payload, or null when the archive
     * has no `secrets.master_key`.
     *
     * @param array<string, mixed> $backupData
     */
    private function payloadMasterKey(array $backupData): ?string
    {
        $hex = $backupData['secrets']['master_key'] ?? null;

        return is_string($hex) ? ConnectorKeyStore::normalise($hex) : null;
    }

    /**
     * @param array<string, mixed> $tables
     */
    private function hasConnectorTables(array $tables): bool
    {
        foreach (array_keys($tables) as $name) {
            if ($this->isConnectorTable((string) $name)) {
                return true;
            }
        }

        return false;
    }

    private function isConnectorTable(string $table): bool
    {
        return str_starts_with($table, 'connector_');
    }

    /**
     * Flags connector instances whose credentials must be re-entered. Guarded:
     * the connector tables do not exist until Phase 2, so this is a safe no-op
     * today and becomes the visible `needs_credentials` state once they do.
     */
    private function markConnectorsNeedsCredentials(): void
    {
        if (!$this->db->tableExists('connector_instances')
            || !$this->db->fieldExists('last_status', 'connector_instances')) {
            return;
        }

        $this->db->table('connector_instances')->update(['last_status' => 'needs_credentials']);
    }

    /**
     * Encrypts plaintext using AES-256-GCM and PBKDF2.
     */
    protected function encryptPayload(string $plaintext, string $password): string
    {
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
        $iv = random_bytes(12);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        $bundle = [
            'magic'      => self::ENVELOPE_MAGIC,
            'salt'       => base64_encode($salt),
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext)
        ];

        return json_encode($bundle);
    }

    /**
     * Decrypts AES-256-GCM backup package.
     */
    public function decryptPayload(string $encryptedJson, string $password): ?string
    {
        $bundle = json_decode($encryptedJson, true);
        if (!$bundle || ($bundle['magic'] ?? '') !== self::ENVELOPE_MAGIC) {
            return null;
        }

        $salt = base64_decode($bundle['salt']);
        $iv = base64_decode($bundle['iv']);
        $tag = base64_decode($bundle['tag']);
        $ciphertext = base64_decode($bundle['ciphertext']);

        $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext !== false ? $plaintext : null;
    }

    /**
     * Whether an archive on disk is an encrypted envelope.
     *
     * Deliberately structural (the envelope's own `magic` field) rather than a
     * substring search, so a plaintext payload that merely mentions the marker
     * is not mistaken for an encrypted one.
     */
    public function isEncryptedArchive(string $raw): bool
    {
        $bundle = json_decode($raw, true);

        return is_array($bundle) && ($bundle['magic'] ?? '') === self::ENVELOPE_MAGIC;
    }

    /**
     * The digest of a configuration payload: what `system_backups.sha256_checksum`
     * records, and therefore what an archive is checked against.
     *
     * It covers the **plaintext** payload, not the bytes on disk. For a plaintext
     * archive the two are the same thing; for an encrypted one they are not, so
     * the digest simply cannot be recomputed without the passphrase. That is the
     * one subtlety every caller has to get right, which is why it is defined once
     * here rather than open-coded next to each comparison.
     */
    public function payloadDigest(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * The digest recorded for an archive, looked up by its file name. Returns an
     * empty string when the archive is unregistered or was recorded without one
     * — in both cases there is nothing to compare against.
     */
    public function recordedDigest(string $filename): string
    {
        $row = $this->db->table('system_backups')
            ->where('filename', basename($filename))
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        return is_array($row) ? (string) ($row['sha256_checksum'] ?? '') : '';
    }

    /**
     * Every archive listed in the register, newest first, with the file name as
     * the tie-break so the order is stable for rows written in the same second.
     *
     * @return list<array<string, mixed>>
     */
    public function registeredArchives(): array
    {
        return $this->db->table('system_backups')
            ->orderBy('created_at', 'DESC')
            ->orderBy('filename', 'ASC')
            ->get()
            ->getResultArray();
    }
}

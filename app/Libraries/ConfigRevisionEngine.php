<?php

namespace App\Libraries;

use App\Models\ConfigRevisionModel;
use CodeIgniter\Database\BaseConnection;

/**
 * ConfigRevisionEngine — Automated Configuration Diff & Git-Backed Revision History.
 *
 * Provides:
 * 1. Git repository synchronization of appliance state.
 * 2. Unified diff generation with additions/deletions line parsing.
 * 3. Atomic rollback to any historical commit.
 * 4. Automatic integration with Micron OS semantic versioning (0.1.23+).
 */
class ConfigRevisionEngine
{
    protected ?BaseConnection $db;
    protected string $repoPath;
    protected ConfigRevisionModel $revisionModel;

    /**
     * Managed tables included in configuration revision snapshots.
     */
    protected array $trackedTables = [
        'system_settings',
        'interfaces',
        'interface_bonds',
        'wireless_interfaces',
        'firewall_rules',
        'dos_policies',
        'firewall_schedules',
        'nat_dnat',
        'nat_snat_pools',
        'nat_nptv6',
        'nat_settings',
        'dhcp_servers',
        'dns_domain_overrides',
        'ha_settings',
        'ha_virtual_ips',
        'qos_shapers',
        'ipsec_tunnels',
        'wireguard_tunnels',
        'openvpn_servers',
        'webfilter_settings',
        'multicast_settings',
        'dpi_policies',
        'dpi_custom_signatures',
        'dpi_settings',
        'ssl_inspection_profiles',
        'ssl_bypass_domains',
        'ssl_mitm_settings',
    ];

    public function __construct(?BaseConnection $db = null, ?string $repoPath = null)
    {
        $this->db = $db ?? \Config\Database::connect();
        $this->repoPath = $repoPath ?? (defined('WRITEPATH') ? WRITEPATH . 'config_repo' : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'config_repo');
        $this->revisionModel = new ConfigRevisionModel();

        $this->ensureGitRepo();
    }

    /**
     * Ensure the Git repository directory and initialization exists.
     */
    public function ensureGitRepo(): bool
    {
        if (! is_dir($this->repoPath)) {
            @mkdir($this->repoPath, 0755, true);
        }

        $gitDir = $this->repoPath . DIRECTORY_SEPARATOR . '.git';
        if (! is_dir($gitDir)) {
            $this->runGit('init');
            $this->runGit('config user.name "Micron OS Engine"');
            $this->runGit('config user.email "engine@micron-os.local"');
            $this->runGit('config core.autocrlf false');
            $this->runGit('config core.safecrlf false');
        }

        return is_dir($gitDir);
    }

    /**
     * Export complete appliance state from database into structured array.
     */
    public function exportState(?string $subsystem = null): array
    {
        $data = [
            'project'   => AppVersion::getProjectName(),
            'version'   => AppVersion::getVersion(),
            'channel'   => AppVersion::getChannel(),
            'timestamp' => date('c'),
            'subsystem' => $subsystem ?? 'all',
            'tables'    => [],
        ];

        foreach ($this->trackedTables as $table) {
            if ($this->db->tableExists($table)) {
                $rows = $this->db->table($table)->get()->getResultArray();
                // Normalize rows: sort by primary key or id for deterministic JSON diffs
                usort($rows, static function ($a, $b) {
                    $valA = $a['id'] ?? ($a['rule_order'] ?? ($a['setting_key'] ?? reset($a)));
                    $valB = $b['id'] ?? ($b['rule_order'] ?? ($b['setting_key'] ?? reset($b)));
                    return $valA <=> $valB;
                });
                $data['tables'][$table] = $rows;
            }
        }

        return $data;
    }

    /**
     * Create an atomic Git commit of the configuration state and record revision.
     *
     * @param string $author Operator username (e.g. "ministrator")
     * @param string $message Descriptive summary of the change
     * @param string $subsystem Affected subsystem ("interfaces", "firewall", "general")
     * @param bool   $incrementVersion Whether to bump the patch version (0.1.23 -> 0.1.24)
     * @return array
     */
    public function commit(
        string $author = 'ministrator',
        string $message = 'Update system configuration',
        string $subsystem = 'general',
        bool $incrementVersion = true
    ): array {
        $this->ensureGitRepo();

        $configFile = $this->repoPath . DIRECTORY_SEPARATOR . 'config.json';
        $state = $this->exportState($subsystem);

        // Check if there are differences with existing file
        $currentJson = is_file($configFile) ? (string) file_get_contents($configFile) : '';
        $newJsonTemp = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // If file exists, check if only metadata differs or if there are real changes
        $hasRepoCommits = $this->hasCommits();

        if ($hasRepoCommits && $currentJson === $newJsonTemp) {
            return [
                'success'   => true,
                'unchanged' => true,
                'message'   => 'No configuration changes detected.',
                'version'   => AppVersion::getVersion(),
            ];
        }

        // Increment version patch for the edit if requested
        if ($incrementVersion) {
            $newVersion = AppVersion::incrementPatch();
            $state['version'] = $newVersion;
        }

        $newJson = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($configFile, $newJson);

        // Git stage and commit
        $this->runGit('add config.json');
        
        $authorSafe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $author) ?: 'ministrator';
        $msgSafe = addslashes($message);
        
        $this->runGit("commit -m \"{$msgSafe}\" --author=\"{$authorSafe} <{$authorSafe}@micron-os.local>\"");

        $commitHash = trim($this->runGit('rev-parse HEAD'));
        if (strlen($commitHash) !== 40) {
            // Fallback hash if git rev-parse failed
            $commitHash = sha1($newJson . microtime());
        }

        // Compute diff statistics
        $diffStats = $this->getDiffSummary($commitHash);

        // Record in database
        $revId = $this->revisionModel->recordRevision(
            $commitHash,
            $authorSafe,
            $message,
            $subsystem,
            'config.json',
            $diffStats
        );

        return [
            'success'     => true,
            'id'          => $revId,
            'commit_hash' => $commitHash,
            'version'     => AppVersion::getVersion(),
            'channel'     => AppVersion::getChannel(),
            'author'      => $authorSafe,
            'message'     => $message,
            'subsystem'   => $subsystem,
            'diff_stats'  => $diffStats,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Alias for commit() to create a configuration revision.
     */
    public function createRevision(
        string $author = 'ministrator',
        string $message = 'Update system configuration',
        string $subsystem = 'general',
        bool $incrementVersion = true
    ): array {
        return $this->commit($author, $message, $subsystem, $incrementVersion);
    }

    /**
     * Compute summary of additions and deletions for a given commit.
     */
    public function getDiffSummary(string $commitHash): array
    {
        $diffOutput = $this->runGit("show --stat --oneline {$commitHash}");
        $rawDiff    = $this->runGit("show {$commitHash} -- config.json");

        $additions = 0;
        $deletions = 0;

        $lines = explode("\n", $rawDiff);
        foreach ($lines as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            if (str_starts_with($line, '+')) {
                $additions++;
            } elseif (str_starts_with($line, '-')) {
                $deletions++;
            }
        }

        return [
            'additions' => $additions,
            'deletions' => $deletions,
            'summary'   => "+{$additions} / -{$deletions}",
        ];
    }

    /**
     * Get line-by-line unified diff between a commit and its parent (or another commit).
     *
     * @return array{
     *   commit_hash: string,
     *   parent_hash: ?string,
     *   additions: int,
     *   deletions: int,
     *   lines: list<array{type: string, old_num: ?int, new_num: ?int, content: string}>
     * }
     */
    public function getDiff(string $commitHash, ?string $parentHash = null): array
    {
        $this->ensureGitRepo();

        if ($parentHash !== null && $parentHash !== '') {
            $cmd = "diff {$parentHash} {$commitHash} -- config.json";
        } else {
            $cmd = "show {$commitHash} -- config.json";
        }

        $rawDiff = $this->runGit($cmd);
        $diffLines = explode("\n", $rawDiff);

        $parsedLines = [];
        $additions = 0;
        $deletions = 0;
        $oldNum = 0;
        $newNum = 0;
        $inHunk = false;

        foreach ($diffLines as $line) {
            $rline = rtrim($line, "\r");

            // Check for hunk header: @@ -old,count +new,count @@
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $rline, $matches)) {
                $inHunk = true;
                $oldNum = (int) $matches[1];
                $newNum = (int) $matches[2];

                $parsedLines[] = [
                    'type'    => 'header',
                    'old_num' => null,
                    'new_num' => null,
                    'content' => $rline,
                ];
                continue;
            }

            if (! $inHunk) {
                continue;
            }

            if (str_starts_with($rline, '+')) {
                $additions++;
                $parsedLines[] = [
                    'type'    => 'add',
                    'old_num' => null,
                    'new_num' => $newNum++,
                    'content' => substr($rline, 1),
                ];
            } elseif (str_starts_with($rline, '-')) {
                $deletions++;
                $parsedLines[] = [
                    'type'    => 'delete',
                    'old_num' => $oldNum++,
                    'new_num' => null,
                    'content' => substr($rline, 1),
                ];
            } else {
                $content = str_starts_with($rline, ' ') ? substr($rline, 1) : $rline;
                $parsedLines[] = [
                    'type'    => 'context',
                    'old_num' => $oldNum++,
                    'new_num' => $newNum++,
                    'content' => $content,
                ];
            }
        }

        return [
            'commit_hash' => $commitHash,
            'parent_hash' => $parentHash,
            'additions'   => $additions,
            'deletions'   => $deletions,
            'lines'       => $parsedLines,
            'raw'         => $rawDiff,
        ];
    }

    /**
     * Restore database tables from a historical Git commit.
     *
     * @param string $commitHash Commit SHA to rollback to
     * @param string $author Operator initiating the rollback
     * @return array
     */
    public function rollback(string $commitHash, string $author = 'ministrator'): array
    {
        $this->ensureGitRepo();

        $jsonContent = $this->runGit("show {$commitHash}:config.json", false);
        if (($pos = strpos($jsonContent, '{')) !== false) {
            $jsonContent = substr($jsonContent, $pos);
        }
        $state = json_decode($jsonContent, true);

        if (! is_array($state) || ! isset($state['tables'])) {
            return [
                'success' => false,
                'error'   => 'Target commit does not contain a valid Micron OS config.json snapshot.',
            ];
        }

        $this->db->transStart();

        foreach ($state['tables'] as $table => $rows) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            // Clean existing table and restore snapshot rows
            $this->db->table($table)->emptyTable();

            if (! empty($rows) && is_array($rows)) {
                $this->db->table($table)->insertBatch($rows);
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return [
                'success' => false,
                'error'   => 'Database transaction failed during configuration rollback.',
            ];
        }

        // Commit the restored state to git as a rollback commit
        $shortHash = substr($commitHash, 0, 8);
        $commitResult = $this->commit(
            $author,
            "Rollback to revision {$shortHash}",
            'rollback',
            true // Bump patch version for the rollback edit
        );

        return [
            'success'        => true,
            'rolled_back_to' => $commitHash,
            'new_commit'     => $commitResult['commit_hash'] ?? null,
            'version'        => AppVersion::getVersion(),
            'message'        => "Configuration successfully restored to revision {$shortHash}.",
        ];
    }

    /**
     * Get revision history with combined Git and DB records.
     */
    public function getHistory(int $limit = 50): array
    {
        $dbRecords = $this->revisionModel->getRecentRevisions($limit);
        $results = [];

        foreach ($dbRecords as $rev) {
            $diffStats = null;
            if (! empty($rev['diff_summary'])) {
                $diffStats = json_decode((string) $rev['diff_summary'], true);
            }

            $results[] = [
                'id'          => $rev['id'],
                'commit_hash' => $rev['commit_hash'],
                'short_hash'  => substr((string) $rev['commit_hash'], 0, 8),
                'author'      => $rev['author'],
                'message'     => $rev['message'],
                'subsystem'   => $rev['subsystem'],
                'diff_stats'  => $diffStats,
                'created_at'  => $rev['created_at'],
            ];
        }

        return $results;
    }

    /**
     * Retrieve the JSON snapshot at a specific commit.
     */
    public function getSnapshot(string $commitHash): ?array
    {
        $json = $this->runGit("show {$commitHash}:config.json", false);
        if (($pos = strpos($json, '{')) !== false) {
            $json = substr($json, $pos);
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Check if repository has any commits yet.
     */
    protected function hasCommits(): bool
    {
        $res = $this->runGit('rev-parse --verify HEAD');
        return strlen(trim($res)) === 40;
    }

    /**
     * Execute a Git command safely inside the repository directory.
     */
    protected function runGit(string $cmd, bool $captureStderr = true): string
    {
        $cwd = escapeshellarg($this->repoPath);
        if ($captureStderr) {
            $redirect = '2>&1';
        } else {
            $redirect = (PHP_OS_FAMILY === 'Windows') ? '2>NUL' : '2>/dev/null';
        }
        $fullCmd = "git -C {$cwd} {$cmd} {$redirect}";

        $output = [];
        $exitCode = 0;
        @exec($fullCmd, $output, $exitCode);

        return implode("\n", $output);
    }
}

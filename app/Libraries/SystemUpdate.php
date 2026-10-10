<?php

namespace App\Libraries;

/**
 * The management-plane side of the appliance-wide update / upgrade surface.
 *
 * This is the OS-level counterpart to {@see PackageManager}: where the plugin
 * manager moves a single extension's packages, this asks micrond what an
 * appliance upgrade would change and then performs it. The library never shells
 * out — the daemon runs a fixed `apt-get` argv with no caller-supplied package
 * or repository input (see `system.updates_check` / `system.upgrade`).
 */
final class SystemUpdate
{
    private IpcClient $ipc;

    public function __construct(?IpcClient $ipc = null)
    {
        $this->ipc = $ipc ?? new IpcClient();
    }

    /**
     * Read-only availability check: refresh apt metadata and report the
     * packages an upgrade would move, plus the current appliance release.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        return $this->call('system.updates_check', []);
    }

    /**
     * Upgrade every installed package in place (no removals, no source changes).
     *
     * @return array<string, mixed>
     */
    public function upgrade(): array
    {
        return $this->call('system.upgrade', []);
    }

    /**
     * The appliance's identifying information (release, kernel) for the page
     * header. Falls back to an empty array when the daemon cannot answer.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->call('system.info', []);
    }

    /**
     * Dispatch an RPC and flatten the JSON-RPC envelope into a result array,
     * preserving the daemon's own `success`/`status` fields when present.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $response = $this->ipc->call($method, $params);

        $error = $response['error'] ?? null;
        if (is_array($error) && ! empty($error)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => (string) ($error['message'] ?? 'the daemon rejected the request'),
            ];
        }

        $result = $response['result'] ?? null;
        if (! is_array($result)) {
            return ['success' => false, 'status' => 'error', 'message' => 'the daemon returned no result'];
        }

        return $result;
    }
}

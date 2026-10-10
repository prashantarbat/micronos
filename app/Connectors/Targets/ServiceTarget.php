<?php

namespace App\Connectors\Targets;

use App\Connectors\Manifest;
use App\Libraries\IpcClient;

/**
 * Target adapter for the `service` target (specification §9.3).
 *
 * A manifest's `service` source may only name a `system_services` id and an
 * action. The RPC method is a constant here and is never read from manifest
 * data — this is the whole point of the F2 split. The engine calls
 * `connector.service` (strict `CONNECTOR_SAFE_UNITS` allowlist in micrond), so
 * a poisoned manifest cannot reach `system.service` and cannot stop nftables,
 * unbound, strongswan, wireguard, keepalived or FRR: micrond refuses the unit
 * with no command run.
 *
 * Mirrored on the PHP side: `Manifest::validateSource()` rejects any key a
 * service source does not accept, so a `method`/`rpc`/`unit` field is refused
 * at load time. Both checks are deliberate — the micrond side is the security
 * boundary; the manifest side makes the attempt loud instead of silent.
 */
final class ServiceTarget implements TargetInterface
{
    /**
     * The connector-scoped service RPC.
     *
     * Deliberately NOT configurable and NOT derived from the manifest. The
     * admin path (`system.service`) must never be reachable from here.
     */
    public const RPC = 'connector.service';

    private IpcClient $ipc;

    public function __construct(IpcClient $ipc)
    {
        $this->ipc = $ipc;
    }

    public function target(): string
    {
        return 'service';
    }

    public function apply(Manifest $manifest, array $source): array
    {
        $service = (string) ($source['service'] ?? '');
        $action  = (string) ($source['action'] ?? '');

        if ($service === '' || ! in_array($action, Manifest::SERVICE_ACTIONS, true)) {
            return $this->result($source, $service, $action, false, 'error',
                'service source is missing a valid service id or action');
        }

        $response = $this->ipc->call(self::RPC, [
            'service' => $service,
            'action'  => $action,
        ]);

        if (! empty($response['error'])) {
            return $this->result($source, $service, $action, false, 'failed',
                '[micrond] ' . ($response['error']['message'] ?? 'unknown daemon error'));
        }

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];

        // A refusal (e.g. a unit outside CONNECTOR_SAFE_UNITS) comes back as a
        // well-formed result with success=false and a message, not a transport
        // error — surface it verbatim so the operator sees why nothing ran.
        $success = ($result['success'] ?? false) === true;
        $message = (string) ($result['message'] ?? '');
        if ($message === '') {
            $message = $success
                ? "{$action} {$service}"
                : 'daemon refused the service action';
        }

        return [
            'source_id' => (string) ($source['id'] ?? ''),
            'target'    => 'service',
            'ref'       => $service,
            'action'    => $action,
            'success'   => $success,
            'status'    => $success ? 'applied' : 'failed',
            'message'   => $message,
            'state'     => isset($result['state']) ? (string) $result['state'] : null,
            'steps'     => $this->stringList($result['steps'] ?? []),
            'warnings'  => $this->stringList($result['warnings'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{source_id: string, target: string, ref: string, action: string,
     *               success: bool, status: string, message: string, state: null,
     *               steps: list<string>, warnings: list<string>}
     */
    private function result(array $source, string $service, string $action,
                            bool $success, string $status, string $message): array
    {
        return [
            'source_id' => (string) ($source['id'] ?? ''),
            'target'    => 'service',
            'ref'       => $service,
            'action'    => $action,
            'success'   => $success,
            'status'    => $status,
            'message'   => $message,
            'state'     => null,
            'steps'     => [],
            'warnings'  => [],
        ];
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}

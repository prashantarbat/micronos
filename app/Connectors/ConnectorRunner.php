<?php

namespace App\Connectors;

use App\Connectors\Targets\FetchTarget;
use App\Connectors\Targets\ServiceTarget;
use App\Connectors\Targets\TargetInterface;
use App\Libraries\ConnectorEgressGuard;
use InvalidArgumentException;

/**
 * The connector engine skeleton.
 *
 * It does the narrow, load-bearing part of the engine (specification §8.2):
 * resolve a manifest, then hand each source to the target adapter that owns its
 * `target` value. This is where the two security controls meet their live
 * code paths: `service` sources go through {@see ServiceTarget} (the F2
 * `connector.service` allowlist), and every network source goes through
 * {@see FetchTarget} and the {@see ConnectorEgressGuard} (the F3/F5 SSRF
 * guard). Parse → validate → apply remain Phase 1, so fetched sources are
 * reported as `staged`.
 */
final class ConnectorRunner
{
    private ManifestRegistry $registry;

    /** @var array<string, TargetInterface> target name => adapter */
    private array $targets;

    /**
     * @param ManifestRegistry|null                $registry Manifest source (defaults to the shipped catalog).
     * @param array<string, TargetInterface>|null  $targets  Adapter map, keyed by target name.
     * @param ConnectorEgressGuard|null            $guard    Egress guard for fetch sources.
     * @param string|null                          $stageDir Fetch staging directory.
     */
    public function __construct(
        ?ManifestRegistry $registry = null,
        ?array $targets = null,
        ?ConnectorEgressGuard $guard = null,
        ?string $stageDir = null,
    ) {
        $this->registry = $registry ?? new ManifestRegistry();

        if ($targets !== null) {
            $this->targets = $targets;

            return;
        }

        $guard = $guard ?? new ConnectorEgressGuard();

        $this->targets = [
            'service'      => new ServiceTarget(\Config\Services::ipc()),
            'dnsbl'        => new FetchTarget('dnsbl', $guard, $stageDir),
            'addressgroup' => new FetchTarget('addressgroup', $guard, $stageDir),
            'pki'          => new FetchTarget('pki', $guard, $stageDir),
        ];
    }

    /**
     * @return list<string> Target names this engine can currently apply.
     */
    public function supportedTargets(): array
    {
        return array_keys($this->targets);
    }

    /**
     * Run every source of a shipped connector.
     *
     * @return array{connector: string, name: string, success: bool, sources: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException when no manifest has that id
     */
    public function run(string $connectorId): array
    {
        $manifest = $this->registry->get($connectorId);
        if ($manifest === null) {
            throw new InvalidArgumentException("unknown connector: {$connectorId}");
        }

        return $this->runManifest($manifest);
    }

    /**
     * Run every source of an already-loaded manifest.
     *
     * @return array{connector: string, name: string, success: bool, sources: list<array<string, mixed>>}
     */
    public function runManifest(Manifest $manifest): array
    {
        $results = [];
        $success = true;

        foreach ($manifest->sources() as $source) {
            $target = (string) ($source['target'] ?? '');

            if (! isset($this->targets[$target])) {
                $success   = false;
                $results[] = [
                    'source_id' => (string) ($source['id'] ?? ''),
                    'target'    => $target,
                    'ref'       => '',
                    'success'   => false,
                    'status'    => 'skipped',
                    'message'   => "target \"{$target}\" not implemented in this engine build",
                    'steps'     => [],
                    'warnings'  => [],
                ];
                continue;
            }

            $result = $this->targets[$target]->apply($manifest, $source);

            if (($result['success'] ?? false) !== true) {
                $success = false;
            }

            $results[] = $result;
        }

        return [
            'connector' => $manifest->id(),
            'name'      => $manifest->name(),
            'success'   => $success,
            'sources'   => $results,
        ];
    }
}

<?php

namespace App\Connectors\Targets;

use App\Connectors\FeedParser;
use App\Connectors\Manifest;
use App\Libraries\ConnectorEgressGuard;

/**
 * Target adapter for network fetch sources (`dnsbl`, `addressgroup`, `pki`).
 *
 * This is the only place a connector performs an outbound request, and it does
 * so exclusively through {@see ConnectorEgressGuard}: scheme allowlist,
 * resolve-and-pin against the internal-address denylist, per-hop redirect
 * validation and stream/ratio caps. No connector-supplied data selects the
 * transport or bypasses the guard.
 *
 * After a successful fetch the raw artifact is parsed by {@see FeedParser} into
 * a canonical typed set (domains or CIDRs): invalid entries are dropped and
 * counted, the set is de-duplicated and sorted, and only the typed set is
 * retained — the raw untrusted body is deleted either way. The result is
 * reported as `staged`; applying the set to the data plane (`dnsbl.apply`,
 * `addressgroup.sync`) is the next Phase 1 step.
 */
final class FetchTarget implements TargetInterface
{
    private string $target;
    private ConnectorEgressGuard $guard;
    private string $stageDir;
    private FeedParser $parser;

    public function __construct(
        string $target,
        ConnectorEgressGuard $guard,
        ?string $stageDir = null,
        ?FeedParser $parser = null,
    ) {
        $this->target   = $target;
        $this->guard    = $guard;
        $this->stageDir = rtrim($stageDir ?? (defined('WRITEPATH') ? WRITEPATH . 'connectors/stage' : sys_get_temp_dir() . '/micron-connectors'), '/\\');
        $this->parser   = $parser ?? new FeedParser();
    }

    public function target(): string
    {
        return $this->target;
    }

    public function apply(Manifest $manifest, array $source): array
    {
        $url     = (string) ($source['url'] ?? '');
        $format  = (string) ($source['format'] ?? '');
        $base    = $this->safeName($manifest->id()) . '__' . $this->safeName((string) ($source['id'] ?? 'source'));
        $ref     = $base . '.set';
        $expected = $this->expectedType();

        if (! is_dir($this->stageDir) && ! @mkdir($this->stageDir, 0750, true) && ! is_dir($this->stageDir)) {
            return $this->result($source, $base, false, 'failed', 'cannot create the connector staging directory');
        }

        $feedPath = $this->stageDir . '/' . $base . '.feed';
        $fetch    = $this->guard->fetch($url, ['destination' => $feedPath]);

        if (($fetch['ok'] ?? false) !== true) {
            return $this->result($source, $base, false, 'failed', 'egress refused or fetch failed: ' . ($fetch['error'] ?? 'unknown error'));
        }

        $parsed = $this->parser->parseFile($format, $feedPath, $expected);

        // The raw untrusted body is never retained — only the validated set.
        if (is_file($feedPath)) {
            @unlink($feedPath);
        }

        if (($parsed['ok'] ?? false) !== true) {
            return $this->result($source, $base, false, 'failed', 'parse failed: ' . ($parsed['error'] ?? 'unknown error'));
        }

        if ($expected !== null && $parsed['type'] !== $expected) {
            return $this->result($source, $base, false, 'failed', sprintf(
                'format "%s" produces %s entries but target "%s" needs %s',
                $format,
                $parsed['type'],
                $this->target,
                $expected,
            ));
        }

        $accepted = (int) $parsed['counts']['accepted'];
        if ($accepted === 0) {
            return $this->result($source, $base, false, 'failed', 'no valid entries after parsing');
        }

        $content = $this->renderSet($manifest, $source, $parsed);
        if (! $this->writeAtomic($this->stageDir . '/' . $ref, $content)) {
            return $this->result($source, $base, false, 'failed', 'cannot write the typed set');
        }

        return array_merge($this->result($source, $ref, true, 'staged', sprintf(
            'staged %d %s entries (%d dropped) from %d bytes',
            $accepted,
            $parsed['type'],
            (int) $parsed['counts']['dropped'],
            (int) $fetch['bytes_in'],
        )), [
            'type'    => (string) $parsed['type'],
            'entries' => $accepted,
            'dropped' => (int) $parsed['counts']['dropped'],
            'bytes'   => strlen($content),
            'sha256'  => hash('sha256', $content),
            'warnings' => $this->warnings($parsed),
        ]);
    }

    /**
     * The canonical type this target must receive, or null when unconstrained.
     * A `dnsbl` feed must be domains; an address group must be addresses.
     */
    private function expectedType(): ?string
    {
        return match ($this->target) {
            'dnsbl'        => 'domain',
            'addressgroup' => 'address',
            default        => null,
        };
    }

    /**
     * @param array<string, mixed> $parsed
     *
     * @return list<string>
     */
    private function warnings(array $parsed): array
    {
        $warnings = [];

        if (! empty($parsed['truncated'])) {
            $warnings[] = 'entry cap reached; the set was truncated';
        }

        $samples = is_array($parsed['dropped_samples'] ?? null) ? $parsed['dropped_samples'] : [];
        if ($samples !== []) {
            $warnings[] = 'dropped samples: ' . implode(', ', array_slice($samples, 0, 5));
        }

        $warnings[] = 'apply to the data plane is not implemented in this engine build';

        return $warnings;
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function renderSet(Manifest $manifest, array $source, array $parsed): string
    {
        $lines = [
            '# MicronOS connector set — generated by the connector engine, do not edit',
            sprintf('# connector: %s  source: %s', $manifest->id(), (string) ($source['id'] ?? '')),
            sprintf('# target: %s  format: %s  type: %s', $this->target, (string) ($source['format'] ?? ''), (string) $parsed['type']),
            sprintf('# entries: %d  dropped: %d', (int) $parsed['counts']['accepted'], (int) $parsed['counts']['dropped']),
        ];

        foreach ($parsed['entries'] as $entry) {
            $lines[] = (string) $entry;
        }

        return implode("\n", $lines) . "\n";
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{source_id: string, target: string, ref: string, success: bool,
     *               status: string, message: string, steps: list<string>, warnings: list<string>}
     */
    private function result(array $source, string $ref, bool $success, string $status, string $message): array
    {
        return [
            'source_id' => (string) ($source['id'] ?? ''),
            'target'    => $this->target,
            'ref'       => $ref,
            'success'   => $success,
            'status'    => $status,
            'message'   => $message,
            'steps'     => [],
            'warnings'  => [],
        ];
    }

    private function safeName(string $name): string
    {
        return substr(preg_replace('/[^a-z0-9_-]/', '_', strtolower($name)) ?? 'x', 0, 64);
    }
}

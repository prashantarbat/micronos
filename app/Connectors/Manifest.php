<?php

namespace App\Connectors;

use InvalidArgumentException;

/**
 * A validated connector manifest.
 *
 * Manifests are first-party, shipped with the appliance under
 * `app/Connectors/definitions/<id>/manifest.json` and never fetched at runtime
 * (see docs/MICRONOS_CONNECTOR_ABSTRACTION_SPECIFICATION.md §5 and §13).
 *
 * Validation happens once, at load time, not at first use, so a malformed or
 * hostile manifest is refused before any of its data can reach a target
 * adapter. Two properties matter for the security model:
 *
 *  - `target` and `format` are closed enums — an unknown value is refused.
 *  - Each target permits only its own keys. A `service` source may therefore
 *    carry a service id and an action, and nothing else: it cannot smuggle a
 *    `method`/`rpc`/`unit` field, and it can never name the RPC. The adapter
 *    chooses `connector.service`; the manifest only chooses the service id.
 */
final class Manifest
{
    /** Targets a source may declare. Closed enum. */
    public const TARGETS = ['dnsbl', 'addressgroup', 'service', 'pki'];

    /** Parsers a fetch source may declare. Closed enum (§5). */
    public const FORMATS = [
        'plain-ip-list', 'plain-domain-list', 'hostfile',
        'json-ip-list', 'json-domain-list', 'csv', 'rfc1035-spf',
    ];

    /** Actions a `service` source may request. Closed enum. */
    public const SERVICE_ACTIONS = ['start', 'stop', 'restart'];

    private const ID_RE         = '/^[a-z0-9][a-z0-9-]{1,63}$/';
    private const SOURCE_ID_RE  = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    private const SERVICE_ID_RE = '/^[a-z0-9][a-z0-9@._-]{0,63}$/';

    /**
     * Keys a source of a given target may carry, beyond the shared `id`/`target`.
     *
     * @var array<string, list<string>>
     */
    private const TARGET_KEYS = [
        'dnsbl'        => ['url', 'format', 'feed_name', 'category'],
        'addressgroup' => ['url', 'format', 'group_name'],
        'service'      => ['service', 'action'],
        'pki'          => ['url', 'format'],
    ];

    /** @var array<string, mixed> The raw manifest, as loaded. */
    private array $raw;

    /** @var list<array<string, mixed>> Normalised, validated sources. */
    private array $sources;

    /**
     * @param array<string, mixed>       $raw
     * @param list<array<string, mixed>> $sources
     */
    private function __construct(array $raw, array $sources)
    {
        $this->raw     = $raw;
        $this->sources = $sources;
    }

    /**
     * Parse and validate a decoded manifest.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidArgumentException when the manifest fails validation
     */
    public static function fromArray(array $raw): self
    {
        if (($raw['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('connector manifest: unsupported or missing "schema" (expected 1)');
        }

        $id = $raw['id'] ?? null;
        if (! is_string($id) || ! preg_match(self::ID_RE, $id)) {
            throw new InvalidArgumentException('connector manifest: invalid "id"');
        }

        foreach (['name', 'version'] as $field) {
            if (! isset($raw[$field]) || ! is_string($raw[$field]) || trim($raw[$field]) === '') {
                throw new InvalidArgumentException("connector manifest: missing \"{$field}\"");
            }
        }

        $rawSources = $raw['sources'] ?? null;
        if (! is_array($rawSources) || $rawSources === []) {
            throw new InvalidArgumentException('connector manifest: "sources" must be a non-empty array');
        }

        $sources = [];
        $seen    = [];
        foreach ($rawSources as $index => $source) {
            if (! is_array($source)) {
                throw new InvalidArgumentException("connector manifest: source #{$index} is not an object");
            }
            $normalised = self::validateSource($source, $index);
            if (isset($seen[$normalised['id']])) {
                throw new InvalidArgumentException("connector manifest: duplicate source id \"{$normalised['id']}\"");
            }
            $seen[$normalised['id']] = true;
            $sources[]               = $normalised;
        }

        return new self($raw, $sources);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function validateSource(array $source, int $index): array
    {
        $sid = $source['id'] ?? null;
        if (! is_string($sid) || ! preg_match(self::SOURCE_ID_RE, $sid)) {
            throw new InvalidArgumentException("connector manifest: source #{$index} has an invalid \"id\"");
        }

        $target = $source['target'] ?? null;
        if (! is_string($target) || ! in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException("connector manifest: source \"{$sid}\" has an unknown target");
        }

        // Reject anything outside the target's own key set — this is what keeps
        // a manifest from smuggling an RPC name or a raw systemd unit into the
        // adapter.
        $allowed = array_merge(['id', 'target'], self::TARGET_KEYS[$target]);
        foreach (array_keys($source) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(
                    "connector manifest: source \"{$sid}\" declares \"{$key}\", which target \"{$target}\" does not accept"
                );
            }
        }

        $normalised = ['id' => $sid, 'target' => $target];

        switch ($target) {
            case 'service':
                $service = $source['service'] ?? null;
                if (! is_string($service) || ! preg_match(self::SERVICE_ID_RE, $service)) {
                    throw new InvalidArgumentException("connector manifest: service source \"{$sid}\" has an invalid \"service\" id");
                }
                $action = $source['action'] ?? 'restart';
                if (! is_string($action) || ! in_array($action, self::SERVICE_ACTIONS, true)) {
                    throw new InvalidArgumentException("connector manifest: service source \"{$sid}\" has an unsupported \"action\"");
                }
                $normalised['service'] = $service;
                $normalised['action']  = $action;
                break;

            case 'dnsbl':
            case 'addressgroup':
            case 'pki':
                $url = $source['url'] ?? null;
                if (! is_string($url) || ! self::isHttpUrl($url)) {
                    throw new InvalidArgumentException("connector manifest: source \"{$sid}\" needs an http(s) \"url\"");
                }
                $format = $source['format'] ?? null;
                if (! is_string($format) || ! in_array($format, self::FORMATS, true)) {
                    throw new InvalidArgumentException("connector manifest: source \"{$sid}\" has an unknown \"format\"");
                }
                $normalised['url']    = $url;
                $normalised['format'] = $format;
                foreach (['feed_name', 'category', 'group_name'] as $key) {
                    if (isset($source[$key]) && is_string($source[$key])) {
                        $normalised[$key] = $source[$key];
                    }
                }
                break;
        }

        return $normalised;
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== '';
    }

    public function id(): string
    {
        return (string) $this->raw['id'];
    }

    public function name(): string
    {
        return (string) $this->raw['name'];
    }

    public function version(): string
    {
        return (string) $this->raw['version'];
    }

    /**
     * @return list<string> Related system_services ids (page-header widget).
     */
    public function includes(): array
    {
        $includes = $this->raw['includes'] ?? [];

        return is_array($includes)
            ? array_values(array_filter($includes, 'is_string'))
            : [];
    }

    /**
     * @return list<array<string, mixed>> Validated sources.
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return array<string, mixed> The raw manifest.
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}

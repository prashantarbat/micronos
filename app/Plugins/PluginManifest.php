<?php

namespace App\Plugins;

use InvalidArgumentException;

/**
 * A validated plugin manifest (`plugin.json`).
 *
 * Plugins are signed, first-party artefacts shipped with the appliance under
 * `app/Plugins/definitions/<id>/plugin.json` — never fetched at runtime (see
 * docs/MICRONOS_PLUGIN_AND_PACKAGE_MANAGER_SPECIFICATION.md §4). Validation
 * happens once, at load time, so a malformed or hostile bundle is refused
 * before any of its lifecycle steps can run.
 *
 * Three properties carry the security model:
 *
 *  - `class` and every lifecycle `action` are closed enums — an unknown value
 *    is refused. There is deliberately no `shell.run`/`exec` action.
 *  - Capabilities are validated against a fixed grammar, so a plugin can only
 *    *declare* a bounded surface — and declaring it is not the same as being
 *    granted it (§7).
 *  - Unknown top-level or `requires`/`provides` keys are refused, so a bundle
 *    cannot smuggle a field the engine does not understand.
 */
final class PluginManifest
{
    /** Extension classes. Closed enum. */
    public const CLASSES = ['A', 'B', 'C'];

    /** Lifecycle actions. Closed enum — no arbitrary execution. */
    public const ACTIONS = [
        'apt.install', 'apt.remove',
        'system.enable', 'system.disable', 'system.start', 'system.stop',
        'template.render', 'file.copy', 'dir.ensure',
        'pki.issue', 'dns.setupstream', 'nft.set',
    ];

    private const ID_RE = '/^[a-z0-9][a-z0-9-]{1,63}$/';

    /** Capability grammar — anything outside it is refused. */
    private const CAPABILITY_RES = [
        '/^system\.service:[A-Za-z0-9][A-Za-z0-9@._-]{0,63}$/',
        '/^root\.rpc:[a-z0-9][a-z0-9._-]{0,63}$/',
        '/^kernel\.module:[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/',
        '/^firewall\.ifgroup:[A-Za-z0-9+*._-]{1,16}$/',
        '/^fs\.write:\//',
    ];

    private const SIMPLE_CAPABILITIES = ['net.raw', 'dns.upstream', 'firewall.rules'];

    private const TOP_KEYS    = ['schema', 'id', 'name', 'version', 'class', 'vendor',
        'description', 'min_appliance', 'max_appliance', 'requires', 'provides',
        'capabilities', 'lifecycle'];
    private const REQUIRES_KEYS = ['packages', 'repos', 'kernel_modules', 'binaries'];
    private const PROVIDES_KEYS = ['services', 'menu', 'settings_schema', 'status_source'];

    /** @var array<string, mixed> */
    private array $raw;

    /** @var list<array<string, mixed>> */
    private array $lifecycle;

    /** @var list<string> */
    private array $capabilities;

    /**
     * @param array<string, mixed>       $raw
     * @param list<array<string, mixed>> $lifecycle
     * @param list<string>               $capabilities
     */
    private function __construct(array $raw, array $lifecycle, array $capabilities)
    {
        $this->raw          = $raw;
        $this->lifecycle    = $lifecycle;
        $this->capabilities = $capabilities;
    }

    /**
     * Parse and validate a decoded plugin manifest.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidArgumentException when the manifest fails validation
     */
    public static function fromArray(array $raw): self
    {
        if (($raw['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('plugin manifest: unsupported or missing "schema" (expected 1)');
        }

        $id = $raw['id'] ?? null;
        if (! is_string($id) || ! preg_match(self::ID_RE, $id)) {
            throw new InvalidArgumentException('plugin manifest: invalid "id"');
        }

        foreach (['name', 'version'] as $field) {
            if (! isset($raw[$field]) || ! is_string($raw[$field]) || trim($raw[$field]) === '') {
                throw new InvalidArgumentException("plugin manifest: missing \"{$field}\"");
            }
        }

        $class = $raw['class'] ?? null;
        if (! is_string($class) || ! in_array($class, self::CLASSES, true)) {
            throw new InvalidArgumentException('plugin manifest: "class" must be one of A, B, C');
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::TOP_KEYS, true)) {
                throw new InvalidArgumentException("plugin manifest: unknown top-level key \"{$key}\"");
            }
        }

        self::validateRequires($id, $raw['requires'] ?? []);
        self::validateProvides($id, $raw['provides'] ?? []);

        $capabilities = self::validateCapabilities($id, $raw['capabilities'] ?? []);
        $lifecycle    = self::validateLifecycle($id, $raw['lifecycle'] ?? null);

        return new self($raw, $lifecycle, $capabilities);
    }

    /**
     * @param array<string, mixed> $requires
     */
    private static function validateRequires(string $id, mixed $requires): void
    {
        if ($requires === [] || $requires === null) {
            return;
        }
        if (! is_array($requires)) {
            throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" has a malformed \"requires\"");
        }
        foreach (array_keys($requires) as $key) {
            if (! in_array($key, self::REQUIRES_KEYS, true)) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" requires unknown key \"{$key}\"");
            }
            if (! is_array($requires[$key])) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" requires.{$key} must be an array");
            }
            foreach ($requires[$key] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" requires.{$key} must contain strings");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $provides
     */
    private static function validateProvides(string $id, mixed $provides): void
    {
        if ($provides === [] || $provides === null) {
            return;
        }
        if (! is_array($provides)) {
            throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" has a malformed \"provides\"");
        }
        foreach (array_keys($provides) as $key) {
            if (! in_array($key, self::PROVIDES_KEYS, true)) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides unknown key \"{$key}\"");
            }
        }
        foreach (['services', 'menu'] as $listKey) {
            if (isset($provides[$listKey]) && ! is_array($provides[$listKey])) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.{$listKey} must be an array");
            }
        }
        foreach (['settings_schema', 'status_source'] as $strKey) {
            if (isset($provides[$strKey]) && (! is_string($provides[$strKey]) || trim($provides[$strKey]) === '')) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.{$strKey} must be a non-empty string");
            }
        }

        // A menu entry is a link into the plugin's own namespace. The path is
        // pinned under /plugins/ so a bundle cannot inject a nav link to an
        // arbitrary route, and no field outside the closed set is accepted.
        if (isset($provides['menu'])) {
            foreach ($provides['menu'] as $index => $entry) {
                if (! is_array($entry)) {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.menu #{$index} is not an object");
                }
                foreach (array_keys($entry) as $key) {
                    if (! in_array($key, ['path', 'label', 'permission'], true)) {
                        throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.menu #{$index} has unknown key \"{$key}\"");
                    }
                }
                $path = $entry['path'] ?? null;
                if (! is_string($path) || ! preg_match('#^/plugins/[a-z0-9][a-z0-9/-]*$#', $path) || str_contains($path, '..')) {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.menu #{$index} has an invalid \"path\"");
                }
                $label = $entry['label'] ?? null;
                if (! is_string($label) || trim($label) === '') {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.menu #{$index} needs a non-empty \"label\"");
                }
                if (isset($entry['permission']) && (! is_string($entry['permission']) || trim($entry['permission']) === '')) {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" provides.menu #{$index} has an invalid \"permission\"");
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function validateCapabilities(string $id, mixed $capabilities): array
    {
        if ($capabilities === null) {
            return [];
        }
        if (! is_array($capabilities)) {
            throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" has a malformed \"capabilities\"");
        }

        $out = [];
        foreach ($capabilities as $capability) {
            if (! is_string($capability) || ! self::isValidCapability($capability)) {
                throw new InvalidArgumentException(
                    "plugin manifest: plugin \"{$id}\" declares an invalid capability " . var_export($capability, true)
                );
            }
            $out[] = $capability;
        }

        return array_values(array_unique($out));
    }

    public static function isValidCapability(string $capability): bool
    {
        if (in_array($capability, self::SIMPLE_CAPABILITIES, true)) {
            return true;
        }
        foreach (self::CAPABILITY_RES as $re) {
            if (preg_match($re, $capability) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function validateLifecycle(string $id, mixed $lifecycle): array
    {
        if ($lifecycle === null) {
            return [];
        }
        if (! is_array($lifecycle) || $lifecycle === []) {
            throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" has a malformed \"lifecycle\"");
        }

        $out = [];
        foreach ($lifecycle as $index => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" lifecycle #{$index} is not an object");
            }
            $step   = $entry['step'] ?? null;
            $action = $entry['action'] ?? null;
            if (! is_string($step) || trim($step) === '') {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" lifecycle #{$index} has no \"step\"");
            }
            if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" lifecycle \"{$step}\" has an unknown action");
            }

            self::validateLifecycleAction($id, $step, $action, $entry);
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function validateLifecycleAction(string $id, string $step, string $action, array $entry): void
    {
        if (in_array($action, ['apt.install', 'apt.remove'], true)) {
            $packages = $entry['packages'] ?? null;
            if (! is_array($packages) || $packages === []) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" step \"{$step}\" needs \"packages\"");
            }
            foreach ($packages as $package) {
                if (! is_string($package) || ! preg_match('/^[a-z0-9][a-z0-9+._-]*$/', $package)) {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" step \"{$step}\" has an invalid package name");
                }
            }

            return;
        }

        if (in_array($action, ['system.enable', 'system.disable', 'system.start', 'system.stop'], true)) {
            if (! self::isUnitString($entry['unit'] ?? null)) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" step \"{$step}\" needs a valid \"unit\"");
            }

            return;
        }

        if ($action === 'template.render' || $action === 'file.copy') {
            foreach (['src', 'dst'] as $key) {
                if (! isset($entry[$key]) || ! is_string($entry[$key]) || trim($entry[$key]) === '') {
                    throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" step \"{$step}\" needs \"{$key}\"");
                }
            }

            return;
        }

        if ($action === 'dir.ensure') {
            if (! isset($entry['path']) || ! is_string($entry['path']) || ! str_starts_with($entry['path'], '/')) {
                throw new InvalidArgumentException("plugin manifest: plugin \"{$id}\" step \"{$step}\" needs an absolute \"path\"");
            }
        }
    }

    private static function isUnitString(mixed $unit): bool
    {
        return is_string($unit) && preg_match('/^[A-Za-z0-9][A-Za-z0-9@._-]{0,63}$/', $unit) === 1;
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

    public function class(): string
    {
        return (string) $this->raw['class'];
    }

    public function vendor(): string
    {
        return (string) ($this->raw['vendor'] ?? '');
    }

    public function description(): string
    {
        return (string) ($this->raw['description'] ?? '');
    }

    /**
     * Navigation entries the plugin contributes (`provides.menu`).
     *
     * Each entry is `{path, label, permission}`; paths are pinned under
     * `/plugins/` at validation time, so they always resolve to a page this
     * engine renders.
     *
     * @return list<array{path: string, label: string, permission: string}>
     */
    public function menu(): array
    {
        $menu = $this->raw['provides']['menu'] ?? [];
        if (! is_array($menu)) {
            return [];
        }

        $out = [];
        foreach ($menu as $entry) {
            if (! is_array($entry) || ! isset($entry['path'], $entry['label'])) {
                continue;
            }
            $out[] = [
                'path'       => (string) $entry['path'],
                'label'      => (string) $entry['label'],
                'permission' => isset($entry['permission']) ? (string) $entry['permission'] : '',
            ];
        }

        return $out;
    }

    /**
     * Debian packages the plugin requires (union of `requires.packages` and any
     * `apt.install` lifecycle step).
     *
     * @return list<string>
     */
    public function packages(): array
    {
        $packages = $this->raw['requires']['packages'] ?? [];
        $packages = is_array($packages) ? $packages : [];

        foreach ($this->lifecycle as $entry) {
            if (($entry['action'] ?? null) === 'apt.install' && is_array($entry['packages'] ?? null)) {
                $packages = array_merge($packages, $entry['packages']);
            }
        }

        return array_values(array_unique(array_filter($packages, 'is_string')));
    }

    /**
     * Curated repository ids the plugin requires (`requires.repos`).
     *
     * The manifest only *names* a repository: whether one is registered and
     * enabled is appliance state, resolved by {@see \App\Libraries\RepoRegistry}.
     * A plugin whose repositories are absent is blocked, never installed.
     *
     * @return list<string>
     */
    public function repos(): array
    {
        $repos = $this->raw['requires']['repos'] ?? [];

        return is_array($repos) ? array_values(array_filter($repos, 'is_string')) : [];
    }

    /**
     * systemd units the plugin may control (from `system.*` lifecycle steps).
     *
     * @return list<string>
     */
    public function units(): array
    {
        $units = [];
        foreach ($this->lifecycle as $entry) {
            $action = $entry['action'] ?? null;
            if (is_string($action)
                && in_array($action, ['system.enable', 'system.disable', 'system.start', 'system.stop'], true)
                && is_string($entry['unit'] ?? null)) {
                $units[] = $entry['unit'];
            }
        }

        return array_values(array_unique($units));
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lifecycle(): array
    {
        return $this->lifecycle;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}

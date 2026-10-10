<?php

namespace App\Libraries;

/**
 * Connector fetch egress guard (specification §13; threat model §5.3).
 *
 * Every feed fetch — manifest-defined or operator-supplied — must pass through
 * here. It enforces one fixed pipeline:
 *
 *   1. scheme allowlist (`https` by default; `file:`/`gopher:`/`dict:`/`data:`/
 *      `ftp:`/`http:` are refused before any client call)            [T2.4]
 *   2. resolve the host once and validate **every** returned address against the
 *      internal-address denylist, then connect to the validated IP, pinned
 *      (never validate-then-re-resolve)                              [T2.1/T2.2]
 *   3. redirects: capped, and **every hop re-runs steps 1–2**; `Authorization`
 *      is stripped on cross-origin hops and cross-origin hops are refused
 *      outright when the caller marked the request as carrying sensitive auth
 *                                                                     [T2.3/T2.6]
 *   4. stream caps: compressed bytes, decompressed bytes and compression ratio,
 *      streamed straight to the staging file (never buffered)         [T2.7]
 *   5. TLS verification mandatory; there is no insecure fallback        [T2.8]
 *   6. the response body is never returned — only status, byte counts and a
 *      digest                                                          [T2.9]
 *
 * Testability: the DNS resolver and the HTTP transport are injectable, so the
 * redirect, pinning and cap logic are exercised deterministically without
 * network access. The default transport is cURL with `CURLOPT_RESOLVE`, which is
 * what makes the pin effective while preserving TLS SNI.
 */
final class ConnectorEgressGuard
{
    /** Default scheme allowlist. `http` is opt-in and never the default. */
    public const DEFAULT_SCHEMES = ['https'];

    public const DEFAULT_MAX_REDIRECTS          = 5;
    public const DEFAULT_MAX_BYTES              = 16 * 1024 * 1024;    // compressed
    public const DEFAULT_MAX_DECOMPRESSED_BYTES = 128 * 1024 * 1024;   // decompressed
    public const DEFAULT_MAX_RATIO              = 200;                 // decompressed : compressed
    public const DEFAULT_CONNECT_TIMEOUT        = 15;
    public const DEFAULT_TIMEOUT                = 60;

    /**
     * IPv4 networks that may never be reached. Includes loopback, RFC1918,
     * link-local (which covers the 169.254.169.254 cloud-metadata endpoint),
     * CGNAT, multicast, benchmarking, documentation and reserved space.
     *
     * @var list<string>
     */
    private const DENY_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    /**
     * IPv6 networks that may never be reached. IPv4-mapped addresses
     * (`::ffff:a.b.c.d`) and NAT64 (`64:ff9b::/96`) are unwrapped and checked
     * against the IPv4 list as well, so they cannot be used to smuggle a private
     * v4 target past the guard.
     *
     * @var list<string>
     */
    private const DENY_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64',
        '2001::/32', '2001:2::/48', '2001:10::/28', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var callable(string): list<string> */
    private $resolver;

    /** @var callable|null */
    private $transport;

    /** @var list<string> */
    private array $schemes;

    private int $maxRedirects;
    private int $maxBytes;
    private int $maxDecompressedBytes;
    private int $maxRatio;
    private int $connectTimeout;
    private int $timeout;
    private string $userAgent;

    /**
     * @param callable(string): list<string>|null $resolver  Host → list of IPs.
     * @param callable|null                       $transport One HTTP hop.
     * @param array<string, mixed>                $options
     */
    public function __construct(?callable $resolver = null, ?callable $transport = null, array $options = [])
    {
        $this->resolver = $resolver ?? self::defaultResolver(...);
        $this->transport = $transport;

        $this->schemes              = array_map('strtolower', $options['schemes'] ?? self::DEFAULT_SCHEMES);
        $this->maxRedirects         = (int) ($options['max_redirects'] ?? self::DEFAULT_MAX_REDIRECTS);
        $this->maxBytes             = (int) ($options['max_bytes'] ?? self::DEFAULT_MAX_BYTES);
        $this->maxDecompressedBytes = (int) ($options['max_decompressed_bytes'] ?? self::DEFAULT_MAX_DECOMPRESSED_BYTES);
        $this->maxRatio             = (int) ($options['max_ratio'] ?? self::DEFAULT_MAX_RATIO);
        $this->connectTimeout       = (int) ($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT);
        $this->timeout              = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->userAgent            = (string) ($options['user_agent'] ?? 'MicronOS-Connector/1.0');
    }

    // ------------------------------------------------------------------
    // Denylist
    // ------------------------------------------------------------------

    /**
     * True when an address is internal, reserved or otherwise not publicly
     * routable. Fails closed: an unparseable address is denied.
     */
    public static function isDenied(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            return self::matchesAny($packed, self::DENY_V4);
        }

        // IPv4-mapped (::ffff:a.b.c.d) — unwrap and apply the v4 policy.
        if (self::isV4Mapped($packed)) {
            return self::matchesAny(substr($packed, 12, 4), self::DENY_V4);
        }

        return self::matchesAny($packed, self::DENY_V6);
    }

    /**
     * Validates a URL without performing a request: scheme, host, resolution,
     * and the denylist over every resolved address.
     *
     * @return array{ok: bool, url: string, scheme: string, host: string, port: int,
     *               origin: string, pinned: list<string>, error: string|null}
     */
    public function check(string $url): array
    {
        $syntax = $this->checkSyntax($url);
        if (! $syntax['ok']) {
            // Keep the check() contract uniform: callers may read `pinned`.
            return $syntax + ['pinned' => []];
        }

        $parts     = self::parseUrl($url) ?? [];
        $addresses = $this->resolveHost($syntax['host']);
        if ($addresses === []) {
            return $this->checkFailure($url, 'host did not resolve', $parts);
        }

        $pinned = [];
        foreach ($addresses as $address) {
            if (self::isDenied($address)) {
                // Every resolved address is validated — a host resolving to any
                // internal address is refused outright, not partially trusted.
                return $this->checkFailure($url, 'host resolves to a non-public address', $parts);
            }
            $pinned[] = $address;
        }

        return [
            'ok'     => true,
            'url'    => $syntax['url'],
            'scheme' => $syntax['scheme'],
            'host'   => $syntax['host'],
            'port'   => $syntax['port'],
            'origin' => $syntax['origin'],
            'pinned' => $pinned,
            'error'  => null,
        ];
    }

    /**
     * Syntactic validation **without DNS**: scheme allowlist plus a literal-IP
     * and well-known-internal-name denylist.
     *
     * Used where a resolution would be wrong — e.g. an operator saving a feed URL
     * while the appliance is offline and cannot resolve anything. It is a fast,
     * deterministic first line only; {@see check()} remains authoritative at
     * fetch time because it validates every resolved address.
     *
     * @return array{ok: bool, url: string, scheme: string, host: string, port: int,
     *               origin: string, error: string|null}
     */
    public function checkSyntax(string $url): array
    {
        $parts = self::parseUrl($url);
        if ($parts === null) {
            return $this->syntaxFailure($url, 'malformed url');
        }

        if (! in_array($parts['scheme'], $this->schemes, true)) {
            return $this->syntaxFailure($url, 'scheme not allowed: ' . $parts['scheme'], $parts);
        }

        // A literal address can be judged without resolving anything.
        if (filter_var($parts['host'], FILTER_VALIDATE_IP) !== false && self::isDenied($parts['host'])) {
            return $this->syntaxFailure($url, 'host is a non-public address', $parts);
        }

        if (self::isInternalHostname($parts['host'])) {
            return $this->syntaxFailure($url, 'host is a well-known internal name', $parts);
        }

        return [
            'ok'     => true,
            'url'    => $url,
            'scheme' => $parts['scheme'],
            'host'   => $parts['host'],
            'port'   => $parts['port'],
            'origin' => $parts['origin'],
            'error'  => null,
        ];
    }

    /** Reserved names that never belong in a feed URL (defence in depth). */
    private static function isInternalHostname(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || $host === 'localhost.localdomain') {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Fetch
    // ------------------------------------------------------------------

    /**
     * Fetches a URL through the full pipeline and streams the body to
     * `$options['destination']`. The body is never returned.
     *
     * Options:
     *  - destination    (string|null) staging file to stream into
     *  - headers        (list<string>) request headers, e.g. "Authorization: ..."
     *  - sensitive_auth (bool) refuse cross-origin redirects rather than strip
     *
     * @param array<string, mixed> $options
     *
     * @return array{ok: bool, status: int, url: string, file: string|null, bytes_in: int,
     *               bytes_out: int, sha256: string|null, hops: int, error: string|null}
     */
    public function fetch(string $url, array $options = []): array
    {
        $destination = isset($options['destination']) && is_string($options['destination'])
            ? $options['destination']
            : null;
        $headers   = is_array($options['headers'] ?? null) ? array_values($options['headers']) : [];
        $sensitive = (bool) ($options['sensitive_auth'] ?? false);

        $current = $url;
        $origin  = null;
        $hops    = 0;

        while (true) {
            $check = $this->check($current);
            if (! $check['ok']) {
                return $this->fetchFailure($url, $check['error'] ?? 'url refused', $hops);
            }

            $hopHeaders = $headers;
            if ($origin !== null && $origin !== $check['origin']) {
                if ($sensitive) {
                    return $this->fetchFailure($url, 'refused cross-origin redirect while sending credentials', $hops);
                }
                $hopHeaders = self::stripAuthHeaders($headers);
            }
            $origin ??= $check['origin'];

            $hop = $this->performHop($check, $hopHeaders, $destination, $sensitive);

            if ($hop['error'] !== null) {
                $this->discard($hop['tmp'] ?? null);

                return $this->fetchFailure($url, $hop['error'], $hops + 1);
            }

            $status   = $hop['status'];
            $location = $hop['headers']['location'] ?? null;

            if ($status >= 300 && $status < 400 && is_string($location) && $location !== '') {
                $hops++;
                $this->discard($hop['tmp'] ?? null); // a redirect body is never the artifact

                if ($hops > $this->maxRedirects) {
                    return $this->fetchFailure($url, 'too many redirects', $hops);
                }

                $next = self::resolveRedirect($check['url'], $location);
                if ($next === null) {
                    return $this->fetchFailure($url, 'invalid redirect target', $hops);
                }
                $current = $next;

                continue;
            }

            // Final response. Anything >= 400 is a failure, but we only report
            // the status code — never the upstream body or error text (T2.9).
            if ($status >= 400 || $status === 0) {
                $this->discard($hop['tmp'] ?? null);

                return $this->fetchFailure($url, 'upstream returned status ' . $status, $hops);
            }

            // Only the final, accepted hop is promoted to the staging path, so a
            // failed fetch never leaves a partial or empty artifact behind.
            if ($destination !== null && ($hop['tmp'] ?? null) !== null) {
                if (! @rename($hop['tmp'], $destination)) {
                    $this->discard($hop['tmp']);

                    return $this->fetchFailure($url, 'cannot finalise staging file', $hops);
                }
            }

            return [
                'ok'        => true,
                'status'    => $status,
                'url'       => $check['url'],
                'file'      => $destination,
                'bytes_in'  => $hop['bytes_in'],
                'bytes_out' => $hop['bytes_out'],
                'sha256'    => $hop['sha256'],
                'hops'      => $hops,
                'error'     => null,
            ];
        }
    }

    /**
     * Performs a single (non-redirect-following) hop against the pinned IP.
     *
     * @param array<string, mixed> $check
     * @param list<string>         $headers
     *
     * @return array{status: int, headers: array<string, string>, bytes_in: int,
     *               bytes_out: int, sha256: string|null, error: string|null}
     */
    private function performHop(array $check, array $headers, ?string $destination, bool $sensitive): array
    {
        $tmp      = null;
        $handle   = null;
        $hash     = hash_init('sha256');
        $bytesOut = 0;
        $capHit   = null;

        if ($destination !== null) {
            $tmp = $destination . '.part';
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
                return ['status' => 0, 'headers' => [], 'bytes_in' => 0, 'bytes_out' => 0, 'sha256' => null,
                    'error' => 'cannot open staging file'];
            }
        }

        // The sink is where the caps are actually enforced mid-stream.
        $sink = function (string $chunk) use (&$bytesOut, &$capHit, &$hash, $handle): int {
            $length = strlen($chunk);
            if ($bytesOut + $length > $this->maxDecompressedBytes) {
                $capHit = 'decompressed size cap exceeded';

                return 0; // aborts the transport
            }

            $bytesOut += $length;
            hash_update($hash, $chunk);

            if ($handle !== null) {
                fwrite($handle, $chunk);
            }

            return $length;
        };

        $request = [
            'url'             => $check['url'],
            'scheme'          => $check['scheme'],
            'host'            => $check['host'],
            'port'            => $check['port'],
            'ip'              => $check['pinned'][0] ?? '',
            'headers'         => $headers,
            'destination'     => $destination,
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'max_bytes'       => $this->maxBytes,
            'user_agent'      => $this->userAgent,
            'sensitive_auth'  => $sensitive,
        ];

        try {
            $result = $this->transport !== null
                ? ($this->transport)($request, $sink)
                : $this->curlTransport($request, $sink);
        } catch (\Throwable $e) {
            if ($handle !== null) {
                fclose($handle);
            }
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }

            return ['status' => 0, 'headers' => [], 'bytes_in' => 0, 'bytes_out' => 0, 'sha256' => null,
                'error' => 'transport failure'];
        }

        $bytesIn = (int) ($result['bytes_in'] ?? 0);
        $error   = $result['error'] ?? null;

        if ($capHit !== null) {
            $error = $capHit;
        } elseif ($error === null && $bytesIn > $this->maxBytes) {
            $error = 'compressed size cap exceeded';
        } elseif ($error === null && $bytesIn > 0 && $bytesOut > $bytesIn * $this->maxRatio) {
            $error = 'decompression ratio cap exceeded';
        }

        if ($handle !== null) {
            fclose($handle);
        }

        $sha256 = null;
        if ($error !== null) {
            $this->discard($tmp);
            $tmp = null;
        } else {
            $sha256 = hash_final($hash);
        }

        return [
            'status'    => (int) ($result['status'] ?? 0),
            'headers'   => is_array($result['headers'] ?? null) ? $result['headers'] : [],
            'bytes_in'  => $bytesIn,
            'bytes_out' => $bytesOut,
            'sha256'    => $sha256,
            'tmp'       => $tmp,
            'error'     => $error,
        ];
    }

    // ------------------------------------------------------------------
    // Default transport (cURL)
    // ------------------------------------------------------------------

    /**
     * One HTTP hop with cURL.
     *
     * `CURLOPT_RESOLVE` pins the hostname to the validated IP — this is what
     * defeats DNS rebinding, because cURL neither re-resolves nor follows
     * redirects on its own here. `CURLOPT_FOLLOWLOCATION` is off; the guard
     * owns redirect handling so every hop is validated.
     *
     * @param array<string, mixed> $request
     * @param callable(string): int $sink
     *
     * @return array{status: int, headers: array<string, string>, bytes_in: int, error: string|null}
     */
    private function curlTransport(array $request, callable $sink): array
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for guarded connector fetches');
        }

        $ch = curl_init();
        $headers = [];

        $resolve = sprintf(
            '%s:%d:%s',
            $request['host'],
            $request['port'],
            str_contains($request['ip'], ':') ? '[' . $request['ip'] . ']' : $request['ip'],
        );

        $protocols = self::protocolMask($this->schemes);

        $userHeaders = array_values(array_filter(
            $request['headers'],
            static fn ($h) => is_string($h) && trim($h) !== '',
        ));

        curl_setopt_array($ch, [
            CURLOPT_URL            => $request['url'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => [$resolve],
            CURLOPT_PROTOCOLS      => $protocols,
            CURLOPT_REDIR_PROTOCOLS => $protocols,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $request['connect_timeout'],
            CURLOPT_TIMEOUT        => $request['timeout'],
            CURLOPT_USERAGENT      => $request['user_agent'],
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXFILESIZE    => $request['max_bytes'],
            CURLOPT_HTTPHEADER     => $userHeaders,
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use ($sink): int {
                return $sink($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                if (preg_match('#^HTTP/#i', $line) === 1) {
                    $headers = []; // new status line → new header block

                    return $length;
                }
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ]);

        curl_exec($ch);

        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $bytesIn  = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $errno    = curl_errno($ch);
        curl_close($ch);

        // A write-callback abort surfaces as CURLE_WRITE_ERROR; the guard's sink
        // has already recorded the concrete cap, so treat the transport result
        // as non-fatal and let the guard decide.
        $error = null;
        if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            $error = 'transport error';
        }

        return [
            'status'   => $status,
            'headers'  => $headers,
            'bytes_in' => $bytesIn,
            'error'    => $error,
        ];
    }

    // ------------------------------------------------------------------
    // DNS resolution
    // ------------------------------------------------------------------

    /**
     * Resolves a host to a list of IPs. A literal IP is returned as-is so the
     * denylist still applies; a hostname is resolved once.
     *
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolved = ($this->resolver)($host);
        if (! is_array($resolved)) {
            return [];
        }

        $clean = [];
        foreach ($resolved as $ip) {
            $ip = trim((string) $ip);
            // strip an IPv6 zone id, e.g. fe80::1%eth0
            if (($pos = strpos($ip, '%')) !== false) {
                $ip = substr($ip, 0, $pos);
            }
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $clean[] = $ip;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Default resolver: getaddrinfo via gethostbynamel + AAAA records.
     *
     * @return list<string>
     */
    private static function defaultResolver(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    // ------------------------------------------------------------------
    // URL helpers
    // ------------------------------------------------------------------

    /**
     * @return array{url: string, scheme: string, host: string, port: int, origin: string}|null
     */
    private static function parseUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host   = strtolower($parts['host']);

        // parse_url keeps the brackets on an IPv6 literal (https://[::1]/).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === '') {
            return null;
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'http' ? 80 : 443);

        $origin = $scheme . '://' . $host . ':' . $port;

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'origin' => $origin];
    }

    /**
     * Resolves a Location header (absolute or relative) against the current URL.
     */
    private static function resolveRedirect(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $base_parts = parse_url($base);
        if ($base_parts === false || ! isset($base_parts['scheme'], $base_parts['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $base_parts['scheme'] . ':' . $location;
        }

        $root = $base_parts['scheme'] . '://' . $base_parts['host']
            . (isset($base_parts['port']) ? ':' . $base_parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $root . $location;
        }

        $dir = isset($base_parts['path']) ? rtrim(dirname($base_parts['path']), '/') : '';
        $dir = $dir === '.' ? '' : $dir;

        return $root . $dir . '/' . $location;
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function stripAuthHeaders(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            static fn ($h) => is_string($h) && preg_match('/^\s*(authorization|proxy-authorization|cookie)\s*:/i', $h) !== 1,
        ));
    }

    /**
     * @param list<string> $schemes
     */
    private static function protocolMask(array $schemes): int
    {
        $mask = 0;
        foreach ($schemes as $scheme) {
            if ($scheme === 'https') {
                $mask |= defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2;
            } elseif ($scheme === 'http') {
                $mask |= defined('CURLPROTO_HTTP') ? CURLPROTO_HTTP : 1;
            }
        }

        return $mask;
    }

    // ------------------------------------------------------------------
    // CIDR matching
    // ------------------------------------------------------------------

    /**
     * @param list<string> $networks
     */
    private static function matchesAny(string $packed, array $networks): bool
    {
        foreach ($networks as $network) {
            [$address, $prefix] = explode('/', $network, 2);
            $packedNetwork = @inet_pton($address);
            if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packed)) {
                continue;
            }

            $prefix = (int) $prefix;
            $bytes  = intdiv($prefix, 8);
            $bits   = $prefix % 8;

            if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
                continue;
            }

            if ($bits !== 0 && $bytes < strlen($packed)) {
                $mask = 0xFF << (8 - $bits) & 0xFF;
                if ((ord($packed[$bytes]) & $mask) !== (ord($packedNetwork[$bytes]) & $mask)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    private static function isV4Mapped(string $packed): bool
    {
        if (strlen($packed) !== 16) {
            return false;
        }

        return substr($packed, 0, 10) === str_repeat("\x00", 10)
            && substr($packed, 10, 2) === "\xff\xff";
    }

    // ------------------------------------------------------------------
    // Result helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $parts
     *
     * @return array{ok: bool, url: string, scheme: string, host: string, port: int,
     *               origin: string, pinned: list<string>, error: string|null}
     */
    private function checkFailure(string $url, string $error, ?array $parts = null): array
    {
        return $this->syntaxFailure($url, $error, $parts) + ['pinned' => []];
    }

    /**
     * @param array<string, mixed>|null $parts
     *
     * @return array{ok: bool, url: string, scheme: string, host: string, port: int,
     *               origin: string, error: string}
     */
    private function syntaxFailure(string $url, string $error, ?array $parts = null): array
    {
        return [
            'ok'     => false,
            'url'    => $url,
            'scheme' => (string) ($parts['scheme'] ?? ''),
            'host'   => (string) ($parts['host'] ?? ''),
            'port'   => (int) ($parts['port'] ?? 0),
            'origin' => (string) ($parts['origin'] ?? ''),
            'error'  => $error,
        ];
    }

    /**
     * @return array{ok: bool, status: int, url: string, file: string|null, bytes_in: int,
     *               bytes_out: int, sha256: string|null, hops: int, error: string|null}
     */
    private function discard(?string $tmp): void
    {
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp);
        }
    }

    /**
     * @return array{ok: bool, status: int, url: string, file: string|null, bytes_in: int,
     *               bytes_out: int, sha256: string|null, hops: int, error: string|null}
     */
    private function fetchFailure(string $url, string $error, int $hops): array
    {
        return [
            'ok'        => false,
            'status'    => 0,
            'url'       => $url,
            'file'      => null,
            'bytes_in'  => 0,
            'bytes_out' => 0,
            'sha256'    => null,
            'hops'      => $hops,
            'error'     => $error,
        ];
    }
}

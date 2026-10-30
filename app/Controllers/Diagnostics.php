<?php

namespace App\Controllers;

use App\Libraries\IpcClient;

/**
 * Diagnostics Controller — Network troubleshooting tools (Ping, Traceroute, DNS, ARP, state table, audit log)
 *
 * On Linux appliance, commands are dispatched via micrond IPC daemon.
 * On Windows dev, realistic mock output is returned for rapid prototyping.
 */
class Diagnostics extends BaseController
{
    private IpcClient $ipc;

    /**
     * Real device name → the friendly description the GUI shows for it, resolved
     * from the `interfaces` table once per request (see friendlyInterfaceNames()).
     *
     * @var array<string, string>|null
     */
    private ?array $interfaceNames = null;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();
        $auditLogs = $db->table('audit_logs')->orderBy('created_at', 'DESC')->limit(20)->get()->getResultArray();

        // On Linux: parse /proc/net/arp — on Windows dev: use realistic mock data
        $arpEntries   = $this->getArpTable();
        $ndpEntries   = $this->getNdpTable();
        $routingTable = $this->getRoutingTable();

        // System stats (real on Linux via /proc, mock on Windows dev)
        $stats = $this->getSystemStats();

        // Conntrack state count
        $conntrackStates = $this->getConntrackStatesList();
        $conntrackCount = count($conntrackStates);

        return view('diag/index', [
            'title'          => 'Diagnostics & Tools — MicronOS',
            'breadcrumb'     => 'Diagnostics',
            'auditLogs'      => $auditLogs,
            'arpEntries'     => $arpEntries,
            'ndpEntries'     => $ndpEntries,
            'routingTable'   => $routingTable,
            'conntrackCount' => $conntrackCount,
            'stats'          => $stats,
            'probeSources'   => $this->probeSourceAddresses(),
            'isMock'         => $this->ipc->isMock(),
        ]);
    }

    /**
     * The addresses the Ping and Traceroute pages may source their probes from.
     *
     * pfSense offers every address the appliance actually owns on both pages;
     * here that is each configured interface's current address (the live one
     * when the daemon reports it, otherwise the configured static address). An
     * interface with no address is left out rather than offered as a value that
     * cannot be used.
     *
     * @return list<array{value: string, label: string}>
     */
    private function probeSourceAddresses(): array
    {
        try {
            $rows = \Config\Database::connect()->table('interfaces')->get()->getResultArray();
            $live = $this->ipc->getInterfaces();
        } catch (\Throwable $e) {
            return [];
        }

        $liveByDev = [];
        foreach ((array) ($live['interfaces'] ?? []) as $item) {
            $dev = (string) ($item['device'] ?? $item['name'] ?? '');
            if ($dev !== '') {
                $liveByDev[$dev] = $item;
            }
        }

        $sources = [];
        foreach ($rows as $row) {
            $dev    = (string) ($row['physical_device'] ?? '');
            $candidates = [
                (string) ($liveByDev[$dev]['ip'] ?? ''),
                (string) ($row['ipv4_address'] ?? ''),
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $sources[] = [
                        'value' => $candidate,
                        'label' => trim((string) $row['name']) . ' (' . $candidate . ')',
                    ];
                    break;
                }
            }
        }

        return $sources;
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: ICMP Ping
    // ─────────────────────────────────────────────────────────────────
    /** Maximum pings, and the longest gap between them (pfSense's own limits). */
    private const PING_MAX_COUNT = 10;

    private const PING_MAX_WAIT = 10;

    private const PING_DEFAULT_COUNT = 3;

    /** Live-capture limits: frames per session, and the BPF filter's byte ceiling. */
    private const CAPTURE_MAX_FRAMES = 50;

    private const CAPTURE_MAX_FILTER = 512;

    /**
     * ICMP echo (`POST diag/ping`).
     *
     * The field set is pfSense's Ping page: a hostname, an IP protocol, an
     * optional source address, the maximum number of pings and the seconds
     * between them. The request is validated here for a fast, specific error and
     * again in the daemon, which builds the `ping` command as an argv list — the
     * daemon never lets a caller name a flag, and neither does this.
     */
    public function ping(): \CodeIgniter\HTTP\ResponseInterface
    {
        $host = trim((string) $this->request->getPost('host'));
        if ($host === '') {
            // `target` is the button's older field name.
            $host = trim((string) $this->request->getPost('target'));
        }

        $proto  = strtolower(trim((string) $this->request->getPost('ipproto')));
        $proto  = $proto === '' ? 'ipv4' : $proto;
        $source = trim((string) $this->request->getPost('sourceip'));
        // A missing count takes the default; anything present but out of range is
        // refused by `pingInputError` rather than silently clamped, as pfSense
        // refuses it.
        $countRaw = $this->request->getPost('count');
        $waitRaw  = $this->request->getPost('wait');
        $count    = ($countRaw === null || $countRaw === '') ? self::PING_DEFAULT_COUNT : (int) $countRaw;
        $wait     = ($waitRaw === null || $waitRaw === '') ? 1 : (int) $waitRaw;

        $invalid = $this->pingInputError($host, $proto, $source, $count, $wait);
        if ($invalid !== null) {
            return $this->json([
                'error'  => $invalid,
                'output' => $invalid,
            ], 422);
        }

        $response = $this->ipc->call('diag.ping', [
            'host'     => $host,
            'ipproto'  => $proto,
            'sourceip' => $source,
            'count'    => $count,
            'wait'     => $wait,
        ]);

        $result = $response['result'] ?? null;

        // The daemon re-validates; when it refuses, surface its reason rather
        // than falling through to simulated output.
        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the ping request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['output'])) {
            return $this->json(['output' => (string) $result['output'], 'mock' => false]);
        }

        // Windows dev fallback — simulate realistic RTT output in the shape the
        // chosen protocol and source address would produce.
        return $this->simulated([
            'output' => $this->mockPingOutput($host, $proto, $source, $count, $wait),
            'mock'   => $this->ipc->isMock(),
        ]);
    }

    /**
     * The ping form's validation errors, or null when every field is usable.
     *
     * Messages follow pfSense's own wording so an operator who knows its Ping
     * page sees the same explanation for the same mistake.
     */
    private function pingInputError(string $host, string $proto, string $source, int $count, int $wait): ?string
    {
        $invalid = $this->targetInputError($host, $proto);
        if ($invalid !== null) {
            return $invalid;
        }

        if ($count < 1 || $count > self::PING_MAX_COUNT) {
            return 'Maximum number of pings must be between 1 and ' . self::PING_MAX_COUNT . '.';
        }
        if ($wait < 1 || $wait > self::PING_MAX_WAIT) {
            return 'Seconds between pings must be between 1 and ' . self::PING_MAX_WAIT . '.';
        }

        return $this->sourceInputError($source, $proto);
    }

    /**
     * The hostname and IP-protocol checks the Ping and Traceroute forms share,
     * or null when the pair is usable.
     *
     * Messages follow pfSense's own wording so an operator who knows its
     * Diagnostics pages sees the same explanation for the same mistake.
     */
    private function targetInputError(string $host, string $proto): ?string
    {
        if (! in_array($proto, ['ipv4', 'ipv6'], true)) {
            return 'IP Protocol must be IPv4 or IPv6.';
        }

        if ($host === '') {
            return 'Hostname is required.';
        }

        $literal = $this->ipLiteral($host);
        $isV6    = $literal !== null && str_contains($literal, ':');
        $isV4    = $literal !== null && ! $isV6;

        if ($literal === null && ! $this->isHostname($host)) {
            return 'Hostname must be a valid hostname or IP address.';
        }
        if ($proto === 'ipv4' && $isV6) {
            return 'When using IPv4, the target host must be an IPv4 address or hostname.';
        }
        if ($proto === 'ipv6' && $isV4) {
            return 'When using IPv6, the target host must be an IPv6 address or hostname.';
        }

        return null;
    }

    /**
     * The source-address check both forms share, or null when it is usable.
     *
     * An empty source means "automatically selected", which is always allowed.
     */
    private function sourceInputError(string $source, string $proto): ?string
    {
        if ($source === '') {
            return null;
        }

        $addr = $this->ipLiteral($source);
        if ($addr === null) {
            return 'Source address must be a valid IP address.';
        }
        if (str_contains($addr, ':') && $proto !== 'ipv6') {
            return 'An IPv6 source address requires the IPv6 protocol.';
        }
        if (! str_contains($addr, ':') && $proto !== 'ipv4') {
            return 'An IPv4 source address requires the IPv4 protocol.';
        }

        return null;
    }

    /**
     * A checkbox as an unchecked/checked boolean.
     *
     * An unchecked box posts nothing at all and a checked one posts its value,
     * so anything other than a truthy spelling means "off".
     */
    private function checkbox(string $field): bool
    {
        $value = strtolower(trim((string) $this->request->getPost($field)));

        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * The IP literal at the front of a target, ignoring an IPv6 `%scope`, or
     * null when the value is not an address at all.
     */
    private function ipLiteral(string $value): ?string
    {
        $base = explode('%', $value, 2)[0];

        return filter_var($base, FILTER_VALIDATE_IP) !== false ? $base : null;
    }

    /** True for a syntactically valid hostname (a single label is allowed). */
    private function isHostname(string $value): bool
    {
        return (bool) preg_match(
            '/^(?=.{1,253}\.?$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?$/',
            $value
        );
    }

    /** Simulated `ping` output for the Windows dev box, matching the request. */
    private function mockPingOutput(string $host, string $proto, string $source, int $count, int $wait): string
    {
        if ($proto === 'ipv6') {
            $output = "PING6 {$host} ({$host}) 56 data bytes\n";
            $ttl    = 64;
        } else {
            $output = "PING {$host} ({$host}) 56(84) bytes of data.\n";
            $ttl    = 118;
        }

        if ($source !== '') {
            $output .= "--- {$host} from {$source} ---\n";
        }

        for ($i = 1; $i <= $count; $i++) {
            $rtt = round(10 + lcg_value() * 5, 2);
            $output .= "64 bytes from {$host}: icmp_seq={$i} ttl={$ttl} time={$rtt} ms\n";
        }

        $output .= "\n--- {$host} ping statistics ---\n";
        $output .= "{$count} packets transmitted, {$count} received, 0% packet loss, time " . ($count * $wait * 1000) . "ms\n";

        return $output;
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: Traceroute
    // ─────────────────────────────────────────────────────────────────
    /** Hop ceiling and default, and the per-probe wait (pfSense's own limits). */
    private const TRACEROUTE_MAX_TTL = 64;

    private const TRACEROUTE_DEFAULT_TTL = 18;

    /**
     * Path trace (`POST diag/traceroute`).
     *
     * The field set is pfSense's Traceroute page: a hostname, an IP protocol,
     * a source address, the maximum number of hops, and the Reverse Address
     * Lookup and Use ICMP switches. As with Ping the request is validated here
     * for a fast, specific error and again in the daemon, which builds the
     * `traceroute` command as an argv list.
     */
    public function traceroute(): \CodeIgniter\HTTP\ResponseInterface
    {
        $host = trim((string) $this->request->getPost('host'));
        if ($host === '') {
            // `target` is the button's older field name.
            $host = trim((string) $this->request->getPost('target'));
        }

        $proto  = strtolower(trim((string) $this->request->getPost('ipproto')));
        $proto  = $proto === '' ? 'ipv4' : $proto;
        $source = trim((string) $this->request->getPost('sourceip'));
        // pfSense's Source Address select opens on "Any".
        if (strcasecmp($source, 'any') === 0) {
            $source = '';
        }

        // A missing hop count takes the default; anything present but out of
        // range is refused rather than silently clamped, as pfSense refuses it.
        $ttlRaw = $this->request->getPost('ttl');
        $ttl    = ($ttlRaw === null || $ttlRaw === '') ? self::TRACEROUTE_DEFAULT_TTL : (int) $ttlRaw;

        $resolve = $this->checkbox('resolve');
        $useicmp = $this->checkbox('useicmp');

        $invalid = $this->tracerouteInputError($host, $proto, $source, $ttl);
        if ($invalid !== null) {
            return $this->json([
                'error'  => $invalid,
                'output' => $invalid,
            ], 422);
        }

        $response = $this->ipc->call('diag.traceroute', [
            'host'     => $host,
            'ipproto'  => $proto,
            'sourceip' => $source,
            'ttl'      => $ttl,
            'resolve'  => $resolve,
            'useicmp'  => $useicmp,
        ]);

        $result = $response['result'] ?? null;

        // The daemon re-validates; when it refuses, surface its reason rather
        // than falling through to simulated output.
        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the traceroute request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['output'])) {
            return $this->json(['output' => (string) $result['output'], 'mock' => false]);
        }

        // Windows dev fallback — a trace in the shape the chosen protocol,
        // source address, hop limit and switches would produce.
        return $this->simulated([
            'output' => $this->mockTracerouteOutput($host, $proto, $source, $ttl, $resolve, $useicmp),
            'mock'   => $this->ipc->isMock(),
        ]);
    }

    /**
     * The traceroute form's validation errors, or null when every field is
     * usable.
     */
    private function tracerouteInputError(string $host, string $proto, string $source, int $ttl): ?string
    {
        $invalid = $this->targetInputError($host, $proto);
        if ($invalid !== null) {
            return $invalid;
        }

        if ($ttl < 1 || $ttl > self::TRACEROUTE_MAX_TTL) {
            return 'Maximum number of hops must be between 1 and ' . self::TRACEROUTE_MAX_TTL . '.';
        }

        return $this->sourceInputError($source, $proto);
    }

    /** Simulated `traceroute` output for the Windows dev box, matching the request. */
    private function mockTracerouteOutput(string $host, string $proto, string $source, int $ttl, bool $resolve, bool $useicmp): string
    {
        if ($proto === 'ipv6') {
            $hops = [
                ['fe80::1',                     'gateway.local',      0.6],
                ['2001:558:feed::1',            'isp-edge.net',       6.4],
                ['2600:0:1:2::5b1',             'isp-core.net',      11.2],
                ['2001:4860:1:1::1',            'google-icmp-1.net', 21.8],
                ['2607:f8b0:4004:c07::65',      $host,               24.1],
            ];
        } else {
            $hops = [
                ['192.168.1.1',    'gateway.local',     0.4],
                ['10.0.0.1',       'isp-edge.net',      4.2],
                ['172.16.32.1',    'isp-core.net',      7.8],
                ['195.66.228.162', 'ae-6.r00.londen01.uk.bb.gin.ntt.net', 14.3],
                ['129.250.66.9',   'ae-4.r01.amstnl02.nl.bb.gin.ntt.net', 19.1],
                ['129.250.2.148',  'as-1.r20.nycmny17.us.bb.gin.ntt.net', 83.6],
                [$host,            $host,               88.4],
            ];
        }

        $probe = $useicmp ? 'ICMP' : 'UDP';
        $output = "traceroute to {$host} ({$host}), {$ttl} hops max, 60 byte packets ({$probe})\n";
        if ($source !== '') {
            $output .= "traceroute: sourcing from {$source}\n";
        }
        if (! $resolve) {
            $output .= " (numeric output, reverse lookups disabled)\n";
        }

        foreach (array_slice($hops, 0, max(1, $ttl)) as $i => [$ip, $hostname, $rtt]) {
            // Without reverse lookup the hop is shown as a bare address; the
            // `-n` in the daemon's command is what makes that happen for real.
            $name = $resolve && $hostname !== $ip
                ? "{$hostname} ({$ip})"
                : $ip;

            $output .= sprintf(" %2d  %s  %.3f ms  %.3f ms  %.3f ms\n",
                $i + 1, $name,
                $rtt + (lcg_value() - 0.5),
                $rtt + (lcg_value() - 0.5),
                $rtt + (lcg_value() - 0.5)
            );
        }

        return $output;
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: DNS Lookup
    // ─────────────────────────────────────────────────────────────────
    public function dnsLookup(): \CodeIgniter\HTTP\ResponseInterface
    {
        $name   = $this->sanitizeTarget($this->request->getPost('name') ?? 'cloudflare.com');
        $type   = strtoupper(trim($this->request->getPost('type') ?? 'A'));
        $server = trim($this->request->getPost('server') ?? '1.1.1.1');

        $allowed = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'PTR', 'SOA'];
        if (!in_array($type, $allowed)) {
            $type = 'A';
        }

        // On Linux: use real dig via IPC. The daemon's payload lives under the
        // envelope's `result`; reading `output` off the envelope itself is what
        // made this fall through to simulated output on every live appliance.
        $response = $this->ipc->call('diag.dns_lookup', ['name' => $name, 'type' => $type, 'server' => $server]);
        $result   = $response['result'] ?? null;

        if (is_array($result) && isset($result['output'])) {
            return $this->json(['output' => (string) $result['output'], 'mock' => false]);
        }

        // Dev mock
        $now = date('D M  j H:i:s T Y');
        $serial = date('Ymd') . '01';
        $output = "; <<>> DiG 9.18.28 <<>> @{$server} {$name} {$type}\n";
        $output .= ";; global options: +cmd\n";
        $output .= ";; Got answer:\n";
        $output .= ";; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: " . rand(10000, 60000) . "\n";
        $output .= ";; flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 1\n\n";
        $output .= ";; OPT PSEUDOSECTION:\n; EDNS: version: 0, flags:; udp: 1232\n";
        $output .= ";; QUESTION SECTION:\n;{$name}.\t\t\tIN\t{$type}\n\n";
        $output .= ";; ANSWER SECTION:\n";
        if ($type === 'A')    $output .= "{$name}.\t\t299\tIN\tA\t104.21.50.182\n";
        if ($type === 'AAAA') $output .= "{$name}.\t\t299\tIN\tAAAA\t2606:4700:3033::6815:32b6\n";
        if ($type === 'MX')   $output .= "{$name}.\t\t299\tIN\tMX\t10 aspmx.l.google.com.\n";
        if ($type === 'NS')   $output .= "{$name}.\t\t299\tIN\tNS\tns1.cloudflare.com.\n";
        if ($type === 'TXT')  $output .= "{$name}.\t\t299\tIN\tTXT\t\"v=spf1 include:_spf.google.com ~all\"\n";
        $output .= "\n;; Query time: " . rand(5, 25) . " msec\n";
        $output .= ";; SERVER: {$server}#53({$server}) (UDP)\n;; WHEN: {$now}\n;; MSG SIZE  rcvd: 102\n";

        return $this->simulated(['output' => $output, 'mock' => $this->ipc->isMock()]);
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: Packet Capture preview (limited, safe)
    // ─────────────────────────────────────────────────────────────────
    public function packetCapture(): \CodeIgniter\HTTP\ResponseInterface
    {
        $iface   = preg_replace('/[^a-z0-9]/', '', $this->request->getPost('interface') ?? 'eth0');
        $filter  = $this->request->getPost('filter') ?? 'icmp';
        $packets = (int) ($this->request->getPost('packets') ?? 10);
        $packets = max(1, min($packets, 50));

        $response = $this->ipc->call('diag.tcpdump', [
            'interface' => $iface,
            'filter'    => $filter,
            'count'     => $packets,
        ]);
        $result = $response['result'] ?? null;

        if (is_array($result) && isset($result['output'])) {
            return $this->json(['output' => (string) $result['output'], 'mock' => false]);
        }

        // Dev mock
        $ts = time();
        $output = "tcpdump: verbose output suppressed, use -v[v]... for full protocol decode\n";
        $output .= "listening on {$iface}, link-type EN10MB (Ethernet), snapshot length 262144 bytes\n";
        for ($i = 0; $i < min($packets, 6); $i++) {
            $src = '192.168.' . rand(1, 254) . '.' . rand(1, 254);
            $dst = '10.0.0.' . rand(1, 254);
            $output .= date('H:i:s', $ts + $i) . "." . rand(100000, 999999) . " IP {$src} > {$dst}: ICMP echo request, id " . rand(1,65535) . ", seq {$i}, length 64\n";
        }
        $output .= "{$packets} packets captured\n{$packets} packets received by filter\n0 packets dropped by kernel\n";

        return $this->simulated(['output' => $output, 'mock' => $this->ipc->isMock()]);
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: Live Packet Capture (streamed session)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Start a live capture.
     *
     * A one-shot capture returns everything when it finishes; a live one is a
     * session the page polls, so `tcpdump` output reaches the terminal as it is
     * produced rather than in one lump at the end. The BPF expression is checked
     * here and compiled by `tcpdump -d` in the daemon before anything runs — the
     * client's own validation is never trusted.
     */
    public function packetCaptureStart(): \CodeIgniter\HTTP\ResponseInterface
    {
        $iface   = trim((string) $this->request->getPost('interface'));
        $filter  = trim((string) $this->request->getPost('filter'));
        $packets = (int) ($this->request->getPost('packets') ?? 10);

        $invalid = $this->captureInputError($iface, $filter, $packets);
        if ($invalid !== null) {
            return $this->json(['error' => $invalid, 'output' => $invalid], 422);
        }

        $response = $this->ipc->call('diag.capture_start', [
            'interface' => $iface,
            'filter'    => $filter,
            'count'     => $packets,
        ]);
        $result = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the capture request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['session'])) {
            return $this->json([
                'session' => (string) $result['session'],
                'output'  => (string) ($result['output'] ?? ''),
                'cmd'     => (string) ($result['cmd'] ?? ''),
                'running' => (bool) ($result['running'] ?? true),
                'mock'    => false,
            ]);
        }

        // Windows dev fallback — a short simulated capture, delivered in one
        // piece so the terminal still shows something in mock mode.
        return $this->simulated([
            'session' => '',
            'output'  => $this->mockCaptureOutput($iface, $filter, $packets),
            'cmd'     => 'tcpdump -i ' . $iface . ' -n -l -c ' . $packets . ($filter === '' ? '' : ' ' . $filter),
            'running' => false,
            'mock'    => $this->ipc->isMock(),
        ]);
    }

    /** The capture's output since the page's previous poll. */
    public function packetCaptureStream(): \CodeIgniter\HTTP\ResponseInterface
    {
        $session = trim((string) $this->request->getGet('session'));
        if (preg_match('/^[a-f0-9]{16}$/', $session) !== 1) {
            return $this->json(['error' => 'Capture session is not valid.', 'output' => '', 'running' => false], 422);
        }

        $response = $this->ipc->call('diag.capture_poll', ['session' => $session]);
        $result   = $response['result'] ?? null;

        if (!is_array($result) || isset($result['success']) && $result['success'] === false) {
            $reason = is_array($result) ? trim((string) ($result['message'] ?? '')) : '';

            return $this->json([
                'error'   => $reason ?: 'The capture session is no longer available.',
                'output'  => '',
                'running' => false,
            ], 404);
        }

        return $this->json([
            'output'    => (string) ($result['output'] ?? ''),
            'running'   => (bool) ($result['running'] ?? false),
            'returncode' => $result['returncode'] ?? null,
        ]);
    }

    /** Stop a live capture and return the tail it had not yet handed over. */
    public function packetCaptureStop(): \CodeIgniter\HTTP\ResponseInterface
    {
        $session = trim((string) $this->request->getPost('session'));
        if (preg_match('/^[a-f0-9]{16}$/', $session) !== 1) {
            return $this->json(['error' => 'Capture session is not valid.', 'output' => '', 'running' => false], 422);
        }

        $response = $this->ipc->call('diag.capture_stop', ['session' => $session]);
        $result   = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The capture session is no longer available.';

            return $this->json(['error' => $reason, 'output' => '', 'running' => false], 404);
        }

        return $this->json([
            'output'  => (string) ($result['output'] ?? ''),
            'running' => false,
        ]);
    }

    /**
     * The live capture form's validation errors, or null when every field is usable.
     *
     * A capture runs for up to two minutes against a live interface, so the frame
     * count is bounded and the filter is kept to text — the daemon still compiles
     * it before use.
     */
    private function captureInputError(string $iface, string $filter, int $packets): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,15}$/', $iface) !== 1) {
            return 'Interface name is not valid.';
        }
        if ($packets < 1 || $packets > self::CAPTURE_MAX_FRAMES) {
            return 'Frame count must be between 1 and ' . self::CAPTURE_MAX_FRAMES . '.';
        }
        if (strlen($filter) > self::CAPTURE_MAX_FILTER || preg_match('/[\x00\r\n]/', $filter) === 1) {
            return 'Capture filter is not valid.';
        }

        return null;
    }

    /** Simulated live capture for the Windows dev box (mock mode only). */
    private function mockCaptureOutput(string $iface, string $filter, int $packets): string
    {
        $ts      = time();
        $output  = "tcpdump: verbose output suppressed, use -v[v]... for full protocol decode\n";
        $output .= "listening on {$iface}, link-type EN10MB (Ethernet), snapshot length 262144 bytes\n";
        for ($i = 0; $i < min($packets, 6); $i++) {
            $src = '192.168.' . rand(1, 254) . '.' . rand(1, 254);
            $dst = '10.0.0.' . rand(1, 254);
            $output .= date('H:i:s', $ts + $i) . '.' . rand(100000, 999999)
                . " IP {$src} > {$dst}: ICMP echo request, id " . rand(1, 65535)
                . ", seq {$i}, length 64\n";
        }
        $output .= "{$packets} packets captured\n{$packets} packets received by filter\n0 packets dropped by kernel\n";

        return $output;
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: Streamed Ping / Traceroute (session runner)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Start a streamed ICMP ping.
     *
     * A ping prints a line per reply and can run for up to a minute, so the page
     * should not wait for the whole command. The request is validated exactly as
     * the one-shot form is; the daemon then leaves `ping` running and the page
     * polls `diag/stream` for the lines it produced since its previous poll.
     */
    public function pingStart(): \CodeIgniter\HTTP\ResponseInterface
    {
        $host = trim((string) $this->request->getPost('host'));
        if ($host === '') {
            // `target` is the button's older field name.
            $host = trim((string) $this->request->getPost('target'));
        }

        $proto  = strtolower(trim((string) $this->request->getPost('ipproto')));
        $proto  = $proto === '' ? 'ipv4' : $proto;
        $source = trim((string) $this->request->getPost('sourceip'));
        $countRaw = $this->request->getPost('count');
        $waitRaw  = $this->request->getPost('wait');
        $count    = ($countRaw === null || $countRaw === '') ? self::PING_DEFAULT_COUNT : (int) $countRaw;
        $wait     = ($waitRaw === null || $waitRaw === '') ? 1 : (int) $waitRaw;

        $invalid = $this->pingInputError($host, $proto, $source, $count, $wait);
        if ($invalid !== null) {
            return $this->json(['error' => $invalid, 'output' => $invalid], 422);
        }

        $response = $this->ipc->call('diag.ping_start', [
            'host'     => $host,
            'ipproto'  => $proto,
            'sourceip' => $source,
            'count'    => $count,
            'wait'     => $wait,
        ]);
        $result = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the ping request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['session'])) {
            return $this->json([
                'session' => (string) $result['session'],
                'output'  => (string) ($result['output'] ?? ''),
                'cmd'     => (string) ($result['cmd'] ?? ''),
                'running' => (bool) ($result['running'] ?? true),
                'mock'    => false,
            ]);
        }

        // Windows dev fallback — the whole simulated run in one piece, so the
        // terminal still shows something in mock mode.
        return $this->simulated([
            'session' => '',
            'output'  => $this->mockPingOutput($host, $proto, $source, $count, $wait),
            'cmd'     => '',
            'running' => false,
            'mock'    => $this->ipc->isMock(),
        ]);
    }

    /**
     * Start a streamed path trace.
     *
     * The same field set and validation as the one-shot form; the daemon leaves
     * `traceroute` running so each hop reaches the terminal as it is found.
     */
    public function tracerouteStart(): \CodeIgniter\HTTP\ResponseInterface
    {
        $host = trim((string) $this->request->getPost('host'));
        if ($host === '') {
            $host = trim((string) $this->request->getPost('target'));
        }

        $proto  = strtolower(trim((string) $this->request->getPost('ipproto')));
        $proto  = $proto === '' ? 'ipv4' : $proto;
        $source = trim((string) $this->request->getPost('sourceip'));
        if (strcasecmp($source, 'any') === 0) {
            $source = '';
        }

        $ttlRaw = $this->request->getPost('ttl');
        $ttl    = ($ttlRaw === null || $ttlRaw === '') ? self::TRACEROUTE_DEFAULT_TTL : (int) $ttlRaw;
        $resolve = $this->checkbox('resolve');
        $useicmp = $this->checkbox('useicmp');

        $invalid = $this->tracerouteInputError($host, $proto, $source, $ttl);
        if ($invalid !== null) {
            return $this->json(['error' => $invalid, 'output' => $invalid], 422);
        }

        $response = $this->ipc->call('diag.traceroute_start', [
            'host'     => $host,
            'ipproto'  => $proto,
            'sourceip' => $source,
            'ttl'      => $ttl,
            'resolve'  => $resolve,
            'useicmp'  => $useicmp,
        ]);
        $result = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the traceroute request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['session'])) {
            return $this->json([
                'session' => (string) $result['session'],
                'output'  => (string) ($result['output'] ?? ''),
                'cmd'     => (string) ($result['cmd'] ?? ''),
                'running' => (bool) ($result['running'] ?? true),
                'mock'    => false,
            ]);
        }

        // Windows dev fallback — the whole simulated trace in one piece.
        return $this->simulated([
            'session' => '',
            'output'  => $this->mockTracerouteOutput($host, $proto, $source, $ttl, $resolve, $useicmp),
            'cmd'     => '',
            'running' => false,
            'mock'    => $this->ipc->isMock(),
        ]);
    }

    /** The output a streamed ping or traceroute produced since the previous poll. */
    public function diagnosticStream(): \CodeIgniter\HTTP\ResponseInterface
    {
        $session = trim((string) $this->request->getGet('session'));
        if (preg_match('/^[a-f0-9]{16}$/', $session) !== 1) {
            return $this->json(['error' => 'Diagnostics session is not valid.', 'output' => '', 'running' => false], 422);
        }

        $response = $this->ipc->call('diag.stream_poll', ['session' => $session]);
        $result   = $response['result'] ?? null;

        if (!is_array($result) || ($result['success'] ?? null) === false) {
            $reason = is_array($result) ? trim((string) ($result['message'] ?? '')) : '';

            return $this->json([
                'error'   => $reason ?: 'The diagnostics session is no longer available.',
                'output'  => '',
                'running' => false,
            ], 404);
        }

        return $this->json([
            'output'     => (string) ($result['output'] ?? ''),
            'running'    => (bool) ($result['running'] ?? false),
            'returncode' => $result['returncode'] ?? null,
            'kind'       => (string) ($result['kind'] ?? ''),
        ]);
    }

    /** Stop a streamed ping or traceroute and return the tail it had not yet handed over. */
    public function diagnosticStreamStop(): \CodeIgniter\HTTP\ResponseInterface
    {
        $session = trim((string) $this->request->getPost('session'));
        if (preg_match('/^[a-f0-9]{16}$/', $session) !== 1) {
            return $this->json(['error' => 'Diagnostics session is not valid.', 'output' => '', 'running' => false], 422);
        }

        $response = $this->ipc->call('diag.stream_stop', ['session' => $session]);
        $result   = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The diagnostics session is no longer available.';

            return $this->json(['error' => $reason, 'output' => '', 'running' => false], 404);
        }

        return $this->json([
            'output'  => (string) ($result['output'] ?? ''),
            'running' => false,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: Kernel Route Lookup (FIB Next-Hop Query)
    // ─────────────────────────────────────────────────────────────────
    public function routeLookup(): \CodeIgniter\HTTP\ResponseInterface
    {
        $target = $this->sanitizeTarget($this->request->getPost('target') ?? '8.8.8.8');

        $response = $this->ipc->call('diag.route_lookup', ['target' => $target]);
        $result   = $response['result'] ?? null;
        if (is_array($result) && isset($result['output'])) {
            return $this->json(['output' => (string) $result['output'], 'mock' => false]);
        }

        // The daemon is the only path to the kernel. Without it a real
        // appliance says so; the simulation below is for the development
        // (mock) client only.
        if (! $this->ipc->isMock()) {
            $reason = (string) ($response['error']['message'] ?? $result['message'] ?? 'the daemon did not answer');

            return $this->json(['output' => "Route lookup unavailable: {$reason}", 'mock' => false, 'error' => true], 503);
        }

        // Realistic kernel route lookup simulation
        $gw = '192.168.1.1';
        $dev = 'eth0 (WAN)';
        $src = '192.168.1.150';
        $metric = '100';

        if (str_starts_with($target, '10.100.') || str_starts_with($target, '10.8.')) {
            $gw = '10.100.0.1';
            $dev = 'wg0 (WireGuard)';
            $src = '10.100.0.10';
            $metric = '50';
        } elseif (str_starts_with($target, '192.168.1.')) {
            $gw = 'direct / link';
            $dev = 'eth1 (LAN)';
            $src = '192.168.1.1';
            $metric = '0';
        } elseif (str_starts_with($target, '192.168.20.')) {
            $gw = 'direct / link';
            $dev = 'eth2 (DMZ)';
            $src = '192.168.20.1';
            $metric = '0';
        }

        $output = "FIB Route Decision for Target: {$target}\n";
        $output .= "─────────────────────────────────────────────────────────────────\n";
        $output .= "Destination:      {$target}/32\n";
        $output .= "Outgoing Device:  {$dev}\n";
        $output .= "Next-Hop Gateway: {$gw}\n";
        $output .= "Source Address:   {$src}\n";
        $output .= "Metric:           {$metric}\n";
        $output .= "FIB Table:        main [Table 254]\n";
        $output .= "Flags:            [UNICAST, DIRECT-CONNECTED/GATEWAY]\n";
        $output .= "─────────────────────────────────────────────────────────────────\n";
        $output .= "Raw Kernel:       {$target} via {$gw} dev " . explode(' ', $dev)[0] . " src {$src} metric {$metric}\n";
        $output .= "                  cache <mtu 1500>";

        return $this->simulated(['output' => $output, 'mock' => $this->ipc->isMock()]);
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: Port / Socket Probe (TCP Syn / Connect)
    // ─────────────────────────────────────────────────────────────────
    public function portProbe(): \CodeIgniter\HTTP\ResponseInterface
    {
        $host = trim((string) $this->request->getPost('host'));
        if ($host === '' || ($this->ipLiteral($host) === null && ! $this->isHostname($host))) {
            $reason = 'Hostname must be a valid hostname or IP address.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        $port = (int) ($this->request->getPost('port') ?? 443);
        $port = max(1, min(65535, $port));
        $proto = strtolower(trim((string) $this->request->getPost('protocol')));
        $proto = in_array($proto, ['tcp', 'udp'], true) ? $proto : 'tcp';
        $timeout = (float) ($this->request->getPost('timeout') ?? 2.5);
        $timeout = max(0.5, min(10.0, $timeout));

        // The daemon runs the probe on the appliance; its answer lives under the
        // envelope's `result`, as with every other Diagnostics tool.
        $response = $this->ipc->call('diag.port_probe', [
            'host'     => $host,
            'port'     => $port,
            'protocol' => $proto,
            'timeout'  => $timeout,
        ]);
        $result = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the probe request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['output'])) {
            return $this->json([
                'output' => (string) $result['output'],
                'status' => (string) ($result['status'] ?? 'closed'),
                'rtt'    => $result['rtt'] ?? null,
                'mock'   => false,
            ]);
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';

        $fp = @fsockopen(($proto === 'udp' ? 'udp://' : '') . $host, $port, $errno, $errstr, $timeout);
        $latency = round((microtime(true) - $start) * 1000, 2);

        if ($fp) {
            fclose($fp);
            $serviceName = getservbyport($port, $proto) ?: 'custom';
            $output = "PORT SCAN PROBE: {$host}:{$port}/{$proto} ({$serviceName})\n";
            $output .= "─────────────────────────────────────────────────────────────────\n";
            $output .= "Status:        OPEN / ACCEPTING CONNECTIONS\n";
            $output .= "Protocol:      " . strtoupper($proto) . "\n";
            $output .= "Round-Trip:    {$latency} ms\n";
            $output .= "TCP Handshake: Completed (SYN -> SYN/ACK -> ACK)\n";
            $output .= "Service Guess: " . strtoupper($serviceName) . "\n";
            $output .= "Result:        Host is reachable and listening on port {$port}.";
            return $this->json(['output' => $output, 'status' => 'open', 'rtt' => $latency]);
        }

        $serviceName = getservbyport($port, $proto) ?: 'custom';
        $output = "PORT SCAN PROBE: {$host}:{$port}/{$proto} ({$serviceName})\n";
        $output .= "─────────────────────────────────────────────────────────────────\n";
        $output .= "Status:        CLOSED OR FILTERED\n";
        $output .= "Protocol:      " . strtoupper($proto) . "\n";
        $output .= "Response Time: {$latency} ms\n";
        $output .= "System Error:  {$errstr} (Error Code: {$errno})\n";
        $output .= "Firewall Note: Packets dropped by remote security policy or host is unreachable.";
        return $this->json(['output' => $output, 'status' => 'closed', 'rtt' => $latency]);
    }

    // ─────────────────────────────────────────────────────────────────
    // AJAX: DNS Multi-Resolver Benchmark
    // ─────────────────────────────────────────────────────────────────
    public function dnsBenchmark(): \CodeIgniter\HTTP\ResponseInterface
    {
        $domain = trim((string) $this->request->getPost('domain'));
        if ($domain === '' || ($this->ipLiteral($domain) === null && ! $this->isHostname($domain))) {
            $reason = 'Hostname must be a valid hostname or IP address.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        // Every row is a real query the daemon runs; the simulated table below is
        // only for a box with no daemon to ask.
        $response = $this->ipc->call('diag.dns_benchmark', ['domain' => $domain]);
        $result   = $response['result'] ?? null;

        if (is_array($result) && ($result['success'] ?? null) === false) {
            $reason = trim((string) ($result['message'] ?? '')) ?: 'The daemon refused the benchmark request.';

            return $this->json(['error' => $reason, 'output' => $reason], 422);
        }

        if (is_array($result) && isset($result['output'])) {
            return $this->json(['output' => (string) $result['output'], 'mock' => false]);
        }
        if (! $this->ipc->isMock()) {
            return $this->simulated(['output' => '']);
        }

        $resolvers = [
            ['name' => 'Cloudflare Primary', 'ip' => '1.1.1.1',        'type' => 'Public Anycast'],
            ['name' => 'Google Public DNS',  'ip' => '8.8.8.8',        'type' => 'Public Anycast'],
            ['name' => 'Quad9 Filtered',     'ip' => '9.9.9.9',        'type' => 'Malware Blocking'],
            ['name' => 'Family-Shield Resolver', 'ip' => '208.67.222.222', 'type' => 'Malware Blocking'],
            ['name' => 'Appliance Unbound',  'ip' => '127.0.0.1',      'type' => 'Local DNS Cache'],
        ];

        $output = "DNS MULTI-RESOLVER BENCHMARK: {$domain}\n";
        $output .= sprintf("%-24s %-16s %-18s %-12s %-10s\n", "Resolver", "Server IP", "Category", "Latency", "Status");
        $output .= str_repeat('─', 78) . "\n";

        $bestTime = 9999;
        $bestName = '';

        foreach ($resolvers as $r) {
            $start = microtime(true);
            $errno = 0; $errstr = '';
            $fp = @fsockopen("udp://" . $r['ip'], 53, $errno, $errstr, 1.2);
            $rtt = round((microtime(true) - $start) * 1000, 2);
            if ($rtt < 1.0) {
                $rtt = round(6 + (lcg_value() * 12), 2);
            }
            if ($fp) fclose($fp);

            if ($rtt < $bestTime) {
                $bestTime = $rtt;
                $bestName = $r['name'];
            }

            $output .= sprintf("%-24s %-16s %-18s %-12s %-10s\n", $r['name'], $r['ip'], $r['type'], "{$rtt} ms", "RESOLVED");
        }

        $output .= str_repeat('─', 78) . "\n";
        $output .= "Summary: Lowest latency resolver is {$bestName} ({$bestTime} ms).\n";
        $output .= "Appliance DNS forwarders recommended for lowest jitter.";

        return $this->simulated(['output' => $output]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Sub-views & live diagnostics telemetry
    // ─────────────────────────────────────────────────────────────────
    public function logs(): string
    {
        return view('diag/logs', [
            'title'      => 'Firewall Live Filter Logs — MicronOS',
            'breadcrumb' => 'Firewall Logs',
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    public function logsData(): \CodeIgniter\HTTP\ResponseInterface
    {
        // On Linux: fetch from /var/log/firewall.log or dmesg / ulogd2 / nftables json log
        $response = $this->ipc->call('diag.firewall_logs', ['limit' => 20]);
        $result   = $response['result'] ?? null;
        if (is_array($result) && isset($result['events']) && is_array($result['events'])) {
            return $this->json(['events' => $result['events']]);
        }
        if (! $this->ipc->isMock()) {
            return $this->json(['events' => [], 'unavailable' => true,
                'message' => (string) ($result['message'] ?? $response['error']['message'] ?? 'The firewall log is not available from the daemon.')]);
        }

        // Development (mock) client only: a simulated live traffic stream
        $protocols = ['tcp', 'tcp', 'udp', 'tcp', 'icmp', 'tcp'];
        $ifaces = ['wan', 'lan', 'lan', 'wan', 'dmz', 'wg0'];
        $actions = ['pass', 'pass', 'block', 'pass', 'pass', 'block'];
        $rules = [
            ['id' => 101, 'desc' => 'Default allow LAN to Internet', 'action' => 'pass'],
            ['id' => 102, 'desc' => 'Block inbound WAN reconnaissance', 'action' => 'block'],
            ['id' => 103, 'desc' => 'Allow WireGuard road warrior tunnel', 'action' => 'pass'],
            ['id' => 104, 'desc' => 'Drop invalid TCP flags (SYN-FIN scan)', 'action' => 'block'],
            ['id' => 105, 'desc' => 'Permit DNS query to Quad9 DoT', 'action' => 'pass'],
            ['id' => 106, 'desc' => 'Deny Bogon IP range (RFC1918 on WAN)', 'action' => 'block'],
        ];

        $events = [];
        $count = rand(2, 4);
        for ($i = 0; $i < $count; $i++) {
            $r = $rules[array_rand($rules)];
            $proto = $protocols[array_rand($protocols)];
            $iface = $ifaces[array_rand($ifaces)];

            $srcIp = $iface === 'wan'
                ? rand(45, 198) . '.' . rand(10, 250) . '.' . rand(1, 250) . '.' . rand(2, 250)
                : '192.168.1.' . rand(100, 150);

            $dstIp = $iface === 'wan'
                ? '203.0.113.10'
                : rand(104, 185) . '.' . rand(10, 250) . '.' . rand(1, 250) . '.' . rand(2, 250);

            $events[] = [
                'time'    => date('H:i:s') . '.' . str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT),
                'action'  => $r['action'],
                'iface'   => $iface,
                'proto'   => $proto,
                'src'     => $srcIp,
                'sport'   => rand(1025, 65530),
                'dst'     => $dstIp,
                'dport'   => $proto === 'icmp' ? 0 : [80, 443, 853, 51820, 22, 53, 8080][array_rand([80, 443, 853, 51820, 22, 53, 8080])],
                'rule_id' => $r['id'],
                'rule'    => $r['desc'],
                'flags'   => $proto === 'tcp' ? ['[SYN]', '[ACK]', '[PSH, ACK]', '[FIN, ACK]'][array_rand(['[SYN]', '[ACK]', '[PSH, ACK]', '[FIN, ACK]'])] : '—',
            ];
        }

        return $this->json(['events' => $events]);
    }

    public function states(): string
    {
        return view('diag/states', [
            'title'      => 'Connection States — MicronOS',
            'breadcrumb' => 'States Table',
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    public function statesData(): \CodeIgniter\HTTP\ResponseInterface
    {
        $states = $this->getConntrackStatesList();
        return $this->json([
            'states' => $states,
            'total'  => 1482,
        ]);
    }

    public function exportStatesCsv(): \CodeIgniter\HTTP\ResponseInterface
    {
        $states = $this->getConntrackStatesList();
        $headers = ['Interface', 'Protocol', 'Source_IP', 'Source_Port', 'Destination_IP', 'Destination_Port', 'State', 'Packets', 'Bytes', 'TTL'];

        $rows = [];
        foreach ($states as $s) {
            $rows[] = [
                // The interface the flow resolves to, or empty when conntrack's
                // addresses are not the appliance's own — never a guess.
                $s['iface'] ?? '',
                strtoupper($s['proto'] ?? 'tcp'),
                $s['src'] ?? '',
                $s['sport'] ?? '',
                $s['dst'] ?? '',
                $s['dport'] ?? '',
                $s['state'] ?? 'ESTABLISHED',
                $s['packets'] ?? 0,
                $s['bytes'] ?? 0,
                $s['ttl'] ?? '',
            ];
        }

        return $this->streamCsv('firewall_states_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    public function exportStatesJson(): \CodeIgniter\HTTP\ResponseInterface
    {
        $states = $this->getConntrackStatesList();
        $payload = [
            'appliance'       => 'MicronOS',
            'exported_at'     => date('c'),
            'table'           => 'netfilter_conntrack',
            'total_states'    => count($states),
            'conntrack_max'   => 262144,
            'states'          => $states,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $this->response
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('Content-Disposition', 'attachment; filename="firewall_states_' . date('Ymd_His') . '.json"')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', '0')
            ->setBody($json);
    }

    private function getConntrackStatesList(): array
    {
        // On Linux appliance: parse `conntrack -L -o extended` or query micrond
        $response = $this->ipc->call('diag.conntrack_states', ['limit' => 100]);
        $result   = $response['result'] ?? null;
        if (is_array($result) && isset($result['states']) && is_array($result['states'])) {
            return $this->nameInterfaces($result['states']);
        }
        if (! $this->ipc->isMock()) {
            return [];
        }

        // Development (mock) client only: sample conntrack entries
        return $this->nameInterfaces([
            ['iface' => 'lan', 'proto' => 'tcp', 'src' => '192.168.1.101', 'sport' => 54102, 'dst' => '104.21.50.182',   'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 381,  'bytes' => 148902, 'ttl' => '431980s'],
            ['iface' => 'lan', 'proto' => 'tcp', 'src' => '192.168.1.102', 'sport' => 49182, 'dst' => '142.250.180.14',  'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 1240, 'bytes' => 984120, 'ttl' => '431890s'],
            ['iface' => 'lan', 'proto' => 'udp', 'src' => '192.168.1.101', 'sport' => 58910, 'dst' => '1.1.1.1',          'dport' => 853, 'state' => 'ASSURED',     'packets' => 42,   'bytes' => 6100,   'ttl' => '28s'],
            ['iface' => 'wan', 'proto' => 'tcp', 'src' => '198.51.100.4',  'sport' => 443,   'dst' => '192.168.1.105',  'dport' => 51240,'state' => 'TIME_WAIT',   'packets' => 18,   'bytes' => 2400,   'ttl' => '115s'],
            ['iface' => 'wg0', 'proto' => 'udp', 'src' => '10.10.0.2',     'sport' => 51820, 'dst' => '192.168.1.1',    'dport' => 51820,'state' => 'ASSURED',     'packets' => 9820, 'bytes' => 4820190,'ttl' => '179s'],
            ['iface' => 'lan', 'proto' => 'tcp', 'src' => '192.168.1.104', 'sport' => 61022, 'dst' => '13.107.42.14',    'dport' => 443, 'state' => 'ESTABLISHED', 'packets' => 520,  'bytes' => 412000, 'ttl' => '431950s'],
            ['iface' => 'dmz', 'proto' => 'tcp', 'src' => '192.168.20.10', 'sport' => 80,    'dst' => '203.0.113.88',   'dport' => 49210,'state' => 'ESTABLISHED', 'packets' => 210,  'bytes' => 158900, 'ttl' => '431910s'],
            ['iface' => 'lan', 'proto' => 'icmp','src' => '192.168.1.101', 'sport' => 1,     'dst' => '8.8.8.8',          'dport' => 0,   'state' => 'UNREPLIED',   'packets' => 4,    'bytes' => 336,    'ttl' => '29s'],
            ['iface' => 'lan', 'proto' => 'tcp', 'src' => '192.168.1.105', 'sport' => 50190, 'dst' => '151.101.65.140',  'dport' => 443, 'state' => 'SYN_SENT',    'packets' => 2,    'bytes' => 120,    'ttl' => '118s'],
            ['iface' => 'wan', 'proto' => 'tcp', 'src' => '185.220.101.5', 'sport' => 41829, 'dst' => '203.0.113.10',   'dport' => 22,  'state' => 'SYN_RECV',    'packets' => 1,    'bytes' => 60,     'ttl' => '58s'],
        ]);
    }

    /**
     * Real Linux device name → the friendly description the GUI shows.
     *
     * conntrack (through the daemon) reports the interface the kernel sees —
     * `eth0`, `eth1`, `wg0`, `lo`. pfSense shows the *description* instead: it
     * maps each state's real port through
     * `convert_real_interface_to_friendly_interface_name_fast()` and
     * `get_configured_interface_with_descr()`, then keeps the raw device when the
     * port is not assigned (`'interface' => $interfaces[$state['if']] ?? $state['if']`).
     * Here that same pairing comes from the `interfaces` table: `physical_device`
     * (what conntrack reports) → `name` (the description, e.g. `WAN`). An interface's
     * short `id` is offered too, so an already-friendly name (`lan`) is normalised
     * to the configured description (`LAN`) rather than left in its short form. A
     * device with no row is simply absent, so its state keeps the raw name —
     * pfSense invents nothing either.
     *
     * @return array<string, string>
     */
    private function friendlyInterfaceNames(): array
    {
        if ($this->interfaceNames !== null) {
            return $this->interfaceNames;
        }

        $this->interfaceNames = [];

        try {
            $query = \Config\Database::connect()->table('interfaces')->get();
            $rows  = $query ? $query->getResultArray() : [];
        } catch (\Throwable $e) {
            return $this->interfaceNames;
        }

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            // The real device conntrack reports is authoritative over the short id.
            $device = trim((string) ($row['physical_device'] ?? ''));
            if ($device !== '') {
                $this->interfaceNames[$device] = $name;
            }

            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '' && !isset($this->interfaceNames[$id])) {
                $this->interfaceNames[$id] = $name;
            }
        }

        return $this->interfaceNames;
    }

    /**
     * Rewrite each state's `iface` from the raw device to the friendly name.
     *
     * @param list<array<string, mixed>> $states
     *
     * @return list<array<string, mixed>>
     */
    private function nameInterfaces(array $states): array
    {
        $names = $this->friendlyInterfaceNames();
        if ($names === []) {
            return $states;
        }

        foreach ($states as &$state) {
            $iface = trim((string) ($state['iface'] ?? ''));
            if ($iface !== '' && isset($names[$iface])) {
                $state['iface'] = $names[$iface];
            }
        }
        unset($state);

        return $states;
    }

    private function streamCsv(string $filename, array $headers, array $rows): \CodeIgniter\HTTP\ResponseInterface
    {
        $fp = fopen('php://temp', 'r+');
        fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $csvData = stream_get_contents($fp);
        fclose($fp);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', '0')
            ->setBody($csvData);
    }

    public function killState(): \CodeIgniter\HTTP\ResponseInterface
    {
        $src   = $this->sanitizeTarget($this->request->getPost('src') ?? '');
        $sport = (int) ($this->request->getPost('sport') ?? 0);
        $dst   = $this->sanitizeTarget($this->request->getPost('dst') ?? '');
        $dport = (int) ($this->request->getPost('dport') ?? 0);
        $proto = strtolower(trim($this->request->getPost('proto') ?? 'tcp'));

        $response = $this->ipc->call('diag.conntrack_kill', [
            'src' => $src, 'sport' => $sport, 'dst' => $dst, 'dport' => $dport, 'proto' => $proto,
        ]);
        $outcome = $this->ipcOutcome($response);
        $count   = (int) ($response['result']['killed_count'] ?? 0);
        if (! $outcome['ok']) {
            return $this->json(['success' => false, 'killed_count' => 0,
                'message' => "Could not remove state {$src}:{$sport} -> {$dst}:{$dport}: " . $outcome['reason']], 422);
        }

        return $this->json(['success' => true, 'killed_count' => $count, 'mock' => $this->ipc->isMock(),
            'message' => $count > 0
                ? "Removed {$count} state(s) for {$src}:{$sport} -> {$dst}:{$dport}."
                : "No matching state for {$src}:{$sport} -> {$dst}:{$dport} (it may already have closed)."]);
    }

    public function killHostStates(): \CodeIgniter\HTTP\ResponseInterface
    {
        $host     = $this->sanitizeTarget($this->request->getPost('host') ?? '');
        $response = $this->ipc->call('diag.conntrack_kill_host', ['host' => $host]);
        $outcome  = $this->ipcOutcome($response);
        if (! $outcome['ok']) {
            return $this->json(['success' => false, 'killed_count' => 0,
                'message' => "Could not remove the states of {$host}: " . $outcome['reason']], 422);
        }
        $count = (int) ($response['result']['killed_count'] ?? 0);

        return $this->json([
            'success'      => true,
            'killed_count' => $count,
            'mock'         => $this->ipc->isMock(),
            'message'      => "Removed {$count} connection state(s) involving {$host}.",
        ]);
    }

    public function audit(): string  { return $this->index(); }
    public function arp(): string    { return $this->index(); }

    // ─────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────

    /** Sanitize target to prevent command injection */
    /**
     * A JSON response carrying the next CSRF hash.
     *
     * Token regeneration is enabled and the CSRF cookie is `HttpOnly`, so the
     * hash the page rendered is spent by its first request and JavaScript cannot
     * read the replacement from the cookie. Every response therefore hands the
     * next hash back in the body — without it the page's second probe (any tool,
     * not just ping) is refused 403.
     */
    /**
     * A simulated tool result — only ever for the development (mock) client.
     *
     * On a live appliance the daemon is the only path to the kernel; when it
     * does not answer, the operator is told so instead of being shown a
     * plausible-looking but invented result.
     */
    private function simulated(array $payload): \CodeIgniter\HTTP\ResponseInterface
    {
        if (! $this->ipc->isMock()) {
            return $this->json([
                'output'  => "The diagnostics daemon (micrond) did not return a result, so no output is available.\n"
                    . "Check System → Services (micrond) or run `microctl doctor` on the console.",
                'mock'    => false,
                'error'   => true,
                'running' => false,
            ], 503);
        }
        $payload['mock'] = true;

        return $this->json($payload);
    }

    private function json(array $payload, ?int $status = null): \CodeIgniter\HTTP\ResponseInterface
    {
        if ($status !== null) {
            $this->response->setStatusCode($status);
        }

        return $this->response->setJSON($payload + ['csrf' => csrf_hash()]);
    }

    private function sanitizeTarget(string $target): string
    {
        // Allow only valid hostname/IP characters
        $target = preg_replace('/[^a-zA-Z0-9.\-:]/', '', trim($target));
        return $target ?: '127.0.0.1';
    }

    /** Read ARP table (real on Linux, mock on Windows) */
    private function getArpTable(): array
    {
        if (PHP_OS_FAMILY !== 'Windows' && file_exists('/proc/net/arp')) {
            $lines   = array_slice(file('/proc/net/arp'), 1); // skip header
            $entries = [];
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 6 && $parts[2] !== '0x0') {
                    $entries[] = [
                        'ip'        => $parts[0],
                        'mac'       => $parts[3],
                        'interface' => $parts[5],
                        'type'      => ($parts[2] === '0x2') ? 'gateway' : 'dynamic',
                    ];
                }
            }
            return $entries;
        }

        return [
            ['ip' => '192.168.1.1',   'mac' => 'e0:63:da:89:12:34', 'interface' => 'eth0', 'type' => 'gateway'],
            ['ip' => '192.168.1.101', 'mac' => 'bc:24:11:4d:9a:10', 'interface' => 'eth1', 'type' => 'dynamic'],
            ['ip' => '192.168.1.102', 'mac' => '00:1a:2b:3c:4d:5e', 'interface' => 'eth1', 'type' => 'dynamic'],
            ['ip' => '10.8.0.2',      'mac' => 'ba:ad:de:ad:be:ef', 'interface' => 'tun0', 'type' => 'vpn'],
        ];
    }

    /** System statistics (real on Linux, mock on Windows) */
    private function getSystemStats(): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime    = (float) explode(' ', file_get_contents('/proc/uptime'))[0];
            $loadAvg   = sys_getloadavg();
            $mem       = $this->parseMeminfo();
            return [
                'uptime_seconds' => $uptime,
                'load_1min'      => round($loadAvg[0], 2),
                'load_5min'      => round($loadAvg[1], 2),
                'load_15min'     => round($loadAvg[2], 2),
                'memory_used_mb' => $mem['used'],
                'memory_total_mb'=> $mem['total'],
            ];
        }

        // Windows dev mock
        return [
            'uptime_seconds'  => 86400 * 3 + 7200,
            'load_1min'       => 0.12,
            'load_5min'       => 0.15,
            'load_15min'      => 0.18,
            'memory_used_mb'  => 412,
            'memory_total_mb' => 2048,
        ];
    }

    private function parseMeminfo(): array
    {
        $lines = file('/proc/meminfo');
        $data  = [];
        foreach ($lines as $line) {
            [$key, $val] = array_pad(explode(':', $line), 2, '0');
            $data[trim($key)] = (int) trim($val);
        }
        $total = ($data['MemTotal'] ?? 0) / 1024;
        $free  = (($data['MemFree'] ?? 0) + ($data['Buffers'] ?? 0) + ($data['Cached'] ?? 0)) / 1024;
        return ['total' => round($total), 'used' => round($total - $free)];
    }

    /** Read Kernel Routing Table (FIB) */
    private function getRoutingTable(): array
    {
        // The daemon is the primary source on an appliance; `ip route show` is
        // the fallback when the daemon has nothing to say.
        $response = $this->ipc->call('diag.routing_table');
        $result   = $response['result'] ?? null;
        if (is_array($result) && isset($result['routes']) && is_array($result['routes'])) {
            return $result['routes'];
        }

        // Only the development (mock) client gets the sample table; a real
        // appliance whose daemon did not answer shows an empty table.
        if (! $this->ipc->isMock()) {
            return [];
        }

        // Realistic routing table mock for Windows prototyping
        return [
            ['destination' => '0.0.0.0/0',       'gateway' => '192.168.1.1',   'interface' => 'eth0 (WAN)', 'metric' => 100, 'protocol' => 'dhcp',   'flags' => 'UG'],
            ['destination' => '10.8.0.0/24',     'gateway' => '10.8.0.2',      'interface' => 'tun0 (VPN)', 'metric' => 50,  'protocol' => 'static', 'flags' => 'UG'],
            ['destination' => '10.100.0.0/24',   'gateway' => 'direct',        'interface' => 'wg0 (WG)',   'metric' => 0,   'protocol' => 'kernel', 'flags' => 'U'],
            ['destination' => '192.168.1.0/24',  'gateway' => 'direct',        'interface' => 'eth1 (LAN)', 'metric' => 0,   'protocol' => 'kernel', 'flags' => 'U'],
            ['destination' => '192.168.20.0/24', 'gateway' => 'direct',        'interface' => 'eth2 (DMZ)', 'metric' => 0,   'protocol' => 'kernel', 'flags' => 'U'],
            ['destination' => '127.0.0.0/8',     'gateway' => 'direct',        'interface' => 'lo',         'metric' => 0,   'protocol' => 'kernel', 'flags' => 'U'],
        ];
    }

    /** Read IPv6 NDP Neighbor Table */
    private function getNdpTable(): array
    {
        // The daemon is the primary source; `ip -6 neigh show` is the fallback.
        $response = $this->ipc->call('diag.ndp_table');
        $result   = $response['result'] ?? null;
        if (is_array($result) && isset($result['neighbors']) && is_array($result['neighbors'])) {
            return $result['neighbors'];
        }

        if (! $this->ipc->isMock()) {
            return [];
        }

        // Realistic IPv6 NDP mock
        return [
            ['ip' => 'fe80::1',                  'mac' => 'e0:63:da:89:12:34', 'interface' => 'eth0', 'state' => 'REACHABLE'],
            ['ip' => '2606:4700:4700::1111',    'mac' => '00:1a:2b:88:99:aa', 'interface' => 'eth0', 'state' => 'STALE'],
            ['ip' => 'fe80::215:5dff:fe00:41a2', 'mac' => '00:15:5d:00:41:a2', 'interface' => 'eth1', 'state' => 'REACHABLE'],
            ['ip' => '2001:db8:acad::101',      'mac' => 'bc:24:11:4d:9a:10', 'interface' => 'eth1', 'state' => 'DELAY'],
        ];
    }
}

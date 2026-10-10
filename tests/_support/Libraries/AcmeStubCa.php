<?php

declare(strict_types=1);

namespace Tests\Support\Libraries;

use App\Libraries\Acme;
use App\Libraries\OpenSslConfig;

/**
 * A stub ACME v2 server that validates like the real thing.
 *
 * Two properties are deliberate. It refuses to validate a name unless the
 * HTTP-01 answer is readable at the path a real CA would fetch it from, and it
 * signs the submitted CSR with a throwaway local CA so the declared certificate
 * is an actual X.509 object.
 *
 * @internal
 */
final class AcmeStubCa
{
    public string $base = 'https://acme-v02.api.letsencrypt.org';

    /** Force the challenge of any authorization to be rejected with this detail. */
    public ?string $rejectChallenge = null;

    /** Refuse any order naming this domain. */
    public ?string $rejectDomain = null;

    /** @var list<array{method: string, url: string, body: string}> */
    public array $requests = [];

    private int $nonces = 0;

    private int $challenges = 0;

    private bool $signed = false;

    /** @var array<string, string> challenge URL => authorization URL */
    private array $challengeAuthz = [];

    /** @var array<string, string> challenge URL => token */
    private array $challengeToken = [];

    /** @var array<string, string> authorization URL => status */
    private array $authzStatus = [];

    /** @var list<string> */
    private array $authzUrls = [];

    private string $issuedChain = '';

    private string $caKey = '';

    private string $caCert = '';

    public function transport(): callable
    {
        return function (array $request): array {
            $this->requests[] = ['method' => $request['method'], 'url' => $request['url'], 'body' => $request['body']];

            $this->nonces++;
            $nonce = 'nonce-' . $this->nonces;

            $json = function (array $body, int $status = 200, array $headers = []) use ($nonce): array {
                return [
                    'status'  => $status,
                    'headers' => $headers + ['replay-nonce' => $nonce . '-' . mt_rand()],
                    'body'    => (string) json_encode($body),
                    'error'   => null,
                ];
            };

            $path = (string) parse_url((string) $request['url'], PHP_URL_PATH);

            if ($path === '/directory') {
                return $json([
                    'newNonce'   => $this->base . '/new-nonce',
                    'newAccount' => $this->base . '/new-account',
                    'newOrder'   => $this->base . '/new-order',
                ], 200, ['replay-nonce' => $nonce]);
            }

            if ($path === '/new-nonce') {
                return ['status' => 200, 'headers' => ['replay-nonce' => $nonce], 'body' => '', 'error' => null];
            }

            $payload = (array) json_decode($this->decode($request['body'], 'payload'), true);

            if ($path === '/new-account') {
                return $json(['status' => 'valid'], 201, ['location' => $this->base . '/acct/1', 'replay-nonce' => $nonce]);
            }

            if ($path === '/new-order') {
                $domains = [];
                foreach ((array) ($payload['identifiers'] ?? []) as $identifier) {
                    $domains[] = (string) ($identifier['value'] ?? '');
                }

                if ($this->rejectDomain !== null && in_array($this->rejectDomain, $domains, true)) {
                    return $json(['type' => 'urn:ietf:params:acme:error:rejectedIdentifier', 'detail' => 'this CA will not issue for ' . $this->rejectDomain], 400);
                }

                // One authorization per name, exactly as the real CA does — this is
                // what a manager reading only its stored first challenge would get
                // wrong.
                $this->authzUrls = [];
                foreach ($domains as $index => $domain) {
                    $authzUrl = $this->base . '/authz/' . ($index + 1);
                    $this->authzStatus[$authzUrl] = 'pending';
                    $this->authzUrls[]             = $authzUrl;

                    foreach (['http-01', 'dns-01'] as $type) {
                        $this->challenges++;
                        $url = $this->base . '/challenge/' . $this->challenges;
                        $this->challengeAuthz[$url] = $authzUrl;
                        $this->challengeToken[$url] = 'tok-' . $type . '-' . $this->challenges;
                    }
                }

                $this->domains = $domains;
                $this->signed  = false;

                return $json(
                    ['status' => 'pending', 'authorizations' => $this->authzUrls, 'finalize' => $this->base . '/finalize/1'],
                    201,
                    ['location' => $this->base . '/order/1', 'replay-nonce' => $nonce],
                );
            }

            if (str_starts_with($path, '/authz/')) {
                $status = $this->authzStatus[$request['url']] ?? $this->authzStatus[$this->base . $path] ?? 'pending';
                $index  = (int) substr($path, 7) - 1;

                $challenges = [];
                foreach ($this->challengeAuthz as $url => $authz) {
                    if ($authz !== $this->base . $path) {
                        continue;
                    }

                    $entry = [
                        'type'   => str_starts_with($this->challengeToken[$url], 'tok-dns') ? 'dns-01' : 'http-01',
                        'url'    => $url,
                        'token'  => $this->challengeToken[$url],
                        'status' => $status === 'valid' ? 'valid' : 'pending',
                    ];

                    if ($status === 'invalid') {
                        $entry['status'] = 'invalid';
                        $entry['error']  = ['type' => 'urn:ietf:params:acme:error:dns', 'detail' => $this->rejectChallenge ?? 'the challenge was rejected'];
                    }

                    $challenges[] = $entry;
                }

                return $json([
                    'status'     => $status,
                    'identifier' => ['type' => 'dns', 'value' => $this->domains[$index] ?? 'unknown.example.com'],
                    'challenges' => $challenges,
                ]);
            }

            if (str_starts_with($path, '/challenge/')) {
                $authz = $this->challengeAuthz[$request['url']] ?? '';
                $token = $this->challengeToken[$request['url']] ?? '';

                if ($this->rejectChallenge !== null) {
                    $this->authzStatus[$authz] = 'invalid';

                    return $json(['status' => 'invalid'], 200);
                }

                // DNS-01 is proven at the resolver, which this stub cannot see;
                // the manager's own TXT value is checked by AcmeTest.
                if (str_starts_with($token, 'tok-dns')) {
                    $this->authzStatus[$authz] = 'valid';

                    return $json(['status' => 'valid'], 200);
                }

                // HTTP-01 however is fetched over HTTP, and this is that fetch:
                // the same path the public route reads, with no knowledge of the
                // manager's internals.
                $served = @file_get_contents(rtrim(WRITEPATH, '/\\') . '/acme-challenges/' . $token);

                if (is_string($served) && str_starts_with($served, $token . '.')) {
                    $this->authzStatus[$authz] = 'valid';

                    return $json(['status' => 'valid'], 200);
                }

                $this->authzStatus[$authz] = 'invalid';

                // Reported the way the real CA reports it: the authorization goes
                // invalid, and the detail names the 404 on the validation file.
                return $json(['status' => 'invalid'], 200, ['link' => '<' . $authz . '>;rel="up"']);
            }

            if ($path === '/finalize/1') {
                $this->issuedChain = $this->sign($payload);
                $this->signed      = true;

                // `processing` first, so the client's order polling is exercised
                // rather than skipped by a convenient `valid`.
                return $json(['status' => 'processing', 'authorizations' => $this->authzUrls], 200, ['location' => $this->base . '/order/1', 'replay-nonce' => $nonce]);
            }

            if ($path === '/order/1') {
                return $this->signed
                    ? $json(['status' => 'valid', 'certificate' => $this->base . '/cert/1', 'authorizations' => $this->authzUrls])
                    : $json(['status' => 'ready', 'authorizations' => $this->authzUrls, 'finalize' => $this->base . '/finalize/1']);
            }

            if ($path === '/cert/1') {
                return [
                    'status'  => 200,
                    'headers' => ['replay-nonce' => $nonce, 'content-type' => 'application/pem-certificate-chain'],
                    'body'    => $this->issuedChain,
                    'error'   => null,
                ];
            }

            return $json(['type' => 'urn:ietf:params:acme:error:malformed', 'detail' => 'unknown endpoint ' . $path], 404);
        };
    }

    /** @var list<string> */
    private array $domains = [];

    /**
     * Sign the submitted CSR with a throwaway CA, returning leaf + issuer.
     *
     * @param array<string, mixed> $payload the already-decoded finalize request
     */
    private function sign(array $payload): string
    {
        $encoded = (string) ($payload['csr'] ?? '');
        $der     = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        if ($this->caCert === '') {
            $this->makeCa();
        }

        if (! is_string($der) || $der === '') {
            return 'not a chain';
        }

        $csrPem = "-----BEGIN CERTIFICATE REQUEST-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE REQUEST-----\n";

        // The CA signs the *submitted* CSR, so the certificate it returns carries
        // the public key the appliance generated — which is what makes the key
        // match check in the manager meaningful.
        $signed = @openssl_csr_sign($csrPem, $this->caCert, $this->caKey, 90, OpenSslConfig::options(), random_int(1000, 999999));

        if ($signed === false) {
            return 'not a chain';
        }

        $pem = '';
        openssl_x509_export($signed, $pem);

        return trim($pem) . "\n" . $this->caCert;
    }

    private function makeCa(): void
    {
        $options = OpenSslConfig::options();
        $key     = openssl_pkey_new($options + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $dn  = ['commonName' => 'MicronOS ACME Stub CA', 'organizationName' => 'MicronOS Test'];
        $csr = openssl_csr_new($dn, $key, $options);
        $crt = openssl_csr_sign($csr, null, $key, 3650, $options, random_int(1000, 9999));

        openssl_pkey_export($key, $this->caKey, null, $options);
        openssl_x509_export($crt, $this->caCert);
        $this->caCert = trim($this->caCert);
    }

    /** The `payload`/`protected` member of a flattened JWS. */
    private function decode(string $body, string $member): string
    {
        $jws = (array) json_decode($body, true);
        $raw = (string) ($jws[$member] ?? '');

        return (string) base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', (4 - strlen($raw) % 4) % 4), true);
    }
}

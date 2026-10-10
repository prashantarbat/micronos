<?php

namespace App\Libraries;

/**
 * ACME client (RFC 8555) — Let's Encrypt certificate issuance and renewal.
 *
 * The PKI module generates its own keys and certificates with PHP's OpenSSL
 * extension and calls `micrond` only to *install* the result, so ACME belongs
 * here rather than behind a plugin or a packaged `certbot`: issuing a public
 * certificate is an account + protocol conversation, not a privileged
 * filesystem operation. This class is the protocol; {@see AcmeManager} owns the
 * appliance-facing state (settings, orders, renewal, installation).
 *
 * ## What it deliberately does not do
 *
 *  - **No arbitrary endpoints.** A directory URL must be one of
 *    {@see self::DIRECTORIES}. Let's Encrypt and its staging endpoint are the
 *    only CAs this appliance will talk to, so a pasted URL can never turn the
 *    management plane into a request forwarder (the same reason a curated
 *    repository URL is a closed grammar, Plugin Spec §5.2).
 *  - **No redirects.** Every request is a single HTTPS hop to a host the
 *    directory or an already-validated response named. ACME never needs one, and
 *    following one would let the CA's response steer the appliance elsewhere.
 *  - **No streaming to disk.** Responses are capped and read into memory; the
 *    largest body any of these endpoints returns is a certificate chain.
 *
 * ## Account key and ES256
 *
 * The account key is P-256. Requests are signed with JWS in the flattened JSON
 * serialization, and `ES256` in JOSE means the signature is the **fixed-width
 * R‖S pair**, not the DER `SEQUENCE` OpenSSL prints — so
 * {@see self::derToRaw()} converts it. A DER signature sent as-is is the classic
 * ACME bug: the header, payload and JWK are all correct and the CA still answers
 * `urn:ietf:params:acme:error:malformed`.
 *
 * The nonce is taken from every response (`Replay-Nonce`) and reused, with one
 * retry on `badNonce` — the protocol's own recovery path — so a long issuance
 * does not race a short-lived nonce.
 *
 * ## Testing
 *
 * The transport is injected. The default is cURL, as elsewhere in this tree; the
 * tests hand in a stub that speaks the handful of ACME endpoints it needs, which
 * is what keeps the protocol logic — not a mock of it — under test.
 */
final class Acme
{
    /** The production directory. */
    public const LETSENCRYPT = 'https://acme-v02.api.letsencrypt.org/directory';

    /** The staging directory: same protocol, certificates nobody trusts. */
    public const LETSENCRYPT_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    /**
     * The directories this appliance will talk to, keyed by the name the UI and
     * the database store. A closed set — see the class docblock.
     */
    public const DIRECTORIES = [
        'letsencrypt'         => self::LETSENCRYPT,
        'letsencrypt-staging' => self::LETSENCRYPT_STAGING,
    ];

    /** The challenge types the module can complete. */
    public const CHALLENGE_HTTP = 'http-01';
    public const CHALLENGE_DNS  = 'dns-01';

    // -- Stable failure classifications ------------------------------------
    //
    // Every refusal carries one, so a renewal task can tell "the CA rate-limited
    // us" from "the vendor's CDN blipped" without pattern-matching English —
    // the same convention the repository drift sweep uses.

    public const REASON_DIRECTORY_UNREACHABLE = 'directory_unreachable';
    public const REASON_TRANSPORT_ERROR       = 'transport_error';
    public const REASON_ACCOUNT_FAILED        = 'account_failed';
    public const REASON_ORDER_FAILED          = 'order_failed';
    public const REASON_ORDER_PENDING         = 'order_pending';
    public const REASON_CHALLENGE_FAILED      = 'challenge_failed';
    public const REASON_AUTHORIZATION_FAILED  = 'authorization_failed';
    public const REASON_AUTHORIZATION_PENDING = 'authorization_pending';
    public const REASON_FINALIZE_FAILED       = 'finalize_failed';
    public const REASON_CERTIFICATE_FAILED    = 'certificate_failed';
    public const REASON_RATE_LIMITED          = 'rate_limited';
    public const REASON_NOT_CONFIGURED        = 'not_configured';

    /** A P-256 coordinate is 32 bytes; so is each half of an ES256 signature. */
    private const PART_BYTES = 32;

    /** Nothing any of these endpoints returns is larger than a chain. */
    private const MAX_RESPONSE_BYTES = 1048576;

    private const TIMEOUT = 30;

    /** @var callable(array{method:string,url:string,headers:array<string,string>,body:string}): array */
    private $transport;

    private string $directory;

    private string $accountKeyPem;

    /** The parsed directory document, fetched at most once per instance. */
    private ?array $document = null;

    /** The account URL (`kid`), once the account exists. */
    private string $accountUrl = '';

    /** The nonce most recently handed to us by the CA. */
    private ?string $nonce = null;

    /**
     * @param callable|null $transport  request in, `{status, headers, body, error}` out
     * @param string        $directory  a URL from {@see self::DIRECTORIES}
     * @param string|null   $accountKeyPem the persisted P-256 account key, if any
     */
    public function __construct(?callable $transport = null, string $directory = self::LETSENCRYPT, ?string $accountKeyPem = null)
    {
        // Make sure OpenSSL has a discoverable config before any of it is used:
        // on a PHP build whose `openssl.cnf` is not beside the binary every key
        // and signature call fails with a message about a missing file.
        OpenSslConfig::apply();

        $this->transport     = $transport ?? self::curlTransport();
        $this->directory     = self::isAllowedDirectory($directory) ? $directory : self::LETSENCRYPT;
        $this->accountKeyPem = $accountKeyPem ?? self::generateKey();
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * Whether a directory URL is one this appliance may talk to.
     *
     * Exact match on the published endpoints rather than a host suffix: a
     * suffix test would accept `acme-v02.api.letsencrypt.org.evil.example`.
     */
    public static function isAllowedDirectory(string $url): bool
    {
        return in_array(rtrim(trim($url), '/'), array_values(self::DIRECTORIES), true);
    }

    /** The directory document (fetched at most once). */
    public function directory(): array
    {
        if ($this->document !== null) {
            return ['ok' => true, 'document' => $this->document, 'reason' => '', 'message' => ''];
        }

        $response = $this->request('GET', $this->directory, '', ['Accept' => 'application/json']);

        if ($response['error'] !== null) {
            return self::failure(self::REASON_DIRECTORY_UNREACHABLE, $response['error']);
        }

        $document = self::decode($response['body']);

        if ($document === null || ! isset($document['newNonce'], $document['newAccount'], $document['newOrder'])) {
            return self::failure(self::REASON_DIRECTORY_UNREACHABLE, 'the directory did not describe the ACME endpoints this client needs');
        }

        $this->document = $document;
        $this->rememberNonce($response['headers']);

        return ['ok' => true, 'document' => $document, 'reason' => '', 'message' => ''];
    }

    /** The account key PEM, generating one when the caller has none yet. */
    public function accountKey(): string
    {
        return $this->accountKeyPem;
    }

    /** The public half of the account key, as a JWK (RFC 7517). */
    public function jwk(): array
    {
        return self::keyToJwk($this->accountKeyPem);
    }

    /**
     * The account key's RFC 7638 thumbprint, base64url encoded.
     *
     * This is the stable name of the account key: it identifies *this* account to
     * the CA and, mixed with a challenge token, forms the key authorization.
     */
    public function thumbprint(): string
    {
        return self::jwkThumbprint($this->jwk());
    }

    /** The HTTP-01 file body: `token.thumbprint`. */
    public function keyAuthorization(string $token): string
    {
        return $token . '.' . $this->thumbprint();
    }

    /**
     * The DNS-01 TXT value: base64url(SHA-256(key authorization)).
     *
     * Returned for the operator to publish, never written anywhere — the
     * appliance does not hold DNS provider credentials, and inventing a provider
     * integration would be a different feature with a different blast radius.
     */
    public function dnsTxtValue(string $token): string
    {
        return self::b64(hash('sha256', $this->keyAuthorization($token), true));
    }

    /** The account URL, once registered. */
    public function accountUrl(): string
    {
        return $this->accountUrl;
    }

    // ---------------------------------------------------------------------
    // The protocol
    // ---------------------------------------------------------------------

    /**
     * Register (or find) the ACME account for a contact address.
     *
     * Idempotent by the CA's own rules: the same account key always resolves to
     * the same account, so renewing after a database restore re-registers rather
     * than creating a duplicate.
     */
    public function newAccount(string $email): array
    {
        $ready = $this->directory();
        if ($ready['ok'] !== true) {
            return $ready;
        }

        $payload = ['termsOfServiceAgreed' => true];
        if (trim($email) !== '') {
            $payload['contact'] = ['mailto:' . trim($email)];
        }

        $response = $this->post((string) $this->document['newAccount'], $payload, '');

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_ACCOUNT_FAILED), $response['error']);
        }

        $problem = self::problem($response);
        if ($problem !== null) {
            return self::failure(self::classify($response['status'], self::REASON_ACCOUNT_FAILED), $problem);
        }

        // The account URL is only ever in the Location header, and it is the `kid`
        // every later request must carry.
        $location = (string) ($response['headers']['location'] ?? '');
        if ($location === '') {
            return self::failure(self::REASON_ACCOUNT_FAILED, 'the CA accepted the account but named no account URL');
        }

        $this->accountUrl = $location;

        return ['ok' => true, 'account_url' => $location, 'reason' => '', 'message' => ''];
    }

    /**
     * Create an order for a set of DNS names.
     *
     * @param list<string> $domains
     */
    public function newOrder(array $domains): array
    {
        $ready = $this->directory();
        if ($ready['ok'] !== true) {
            return $ready;
        }

        $identifiers = [];
        foreach ($domains as $domain) {
            $identifiers[] = ['type' => 'dns', 'value' => $domain];
        }

        $response = $this->post((string) $this->document['newOrder'], ['identifiers' => $identifiers], $this->accountUrl);

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_ORDER_FAILED), $response['error']);
        }

        $problem = self::problem($response);
        if ($problem !== null) {
            return self::failure(self::classify($response['status'], self::REASON_ORDER_FAILED), $problem);
        }

        $body = self::decode($response['body']);
        if ($body === null || ! isset($body['authorizations'], $body['finalize'])) {
            return self::failure(self::REASON_ORDER_FAILED, 'the CA returned an order without authorizations or a finalize URL');
        }

        return [
            'ok'             => true,
            'order_url'      => (string) ($response['headers']['location'] ?? ''),
            'status'         => (string) ($body['status'] ?? ''),
            'authorizations' => array_values((array) $body['authorizations']),
            'finalize'       => (string) $body['finalize'],
            'reason'         => '',
            'message'        => '',
        ];
    }

    /** Fetch one authorization, so the caller can publish the right challenge. */
    public function authorization(string $url): array
    {
        $response = $this->postAsGet($url);

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_AUTHORIZATION_FAILED), $response['error']);
        }

        $body = self::decode($response['body']);
        if ($body === null) {
            return self::failure(self::REASON_AUTHORIZATION_FAILED, 'the CA returned an unreadable authorization');
        }

        return [
            'ok'         => true,
            'url'        => $url,
            'status'     => (string) ($body['status'] ?? ''),
            'domain'     => (string) ($body['identifier']['value'] ?? ''),
            'wildcard'   => (bool) ($body['wildcard'] ?? false),
            'challenges' => array_values((array) ($body['challenges'] ?? [])),
            'expires'    => (string) ($body['expires'] ?? ''),
            'reason'     => '',
            'message'    => '',
        ];
    }

    /**
     * The challenge of a given type inside an authorization.
     *
     * @param array<string, mixed> $authorization as {@see self::authorization()} returns it
     */
    public static function challenge(array $authorization, string $type): ?array
    {
        foreach ((array) ($authorization['challenges'] ?? []) as $challenge) {
            if (is_array($challenge) && ($challenge['type'] ?? '') === $type) {
                return $challenge;
            }
        }

        return null;
    }

    /**
     * Tell the CA the challenge is ready, which is what triggers validation.
     *
     * For HTTP-01 the caller must already be serving the key authorization; for
     * DNS-01 the caller must already have published (or be about to publish) the
     * TXT record.
     */
    public function respondToChallenge(string $challengeUrl): array
    {
        $response = $this->post($challengeUrl, [], $this->accountUrl);

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_CHALLENGE_FAILED), $response['error']);
        }

        // A rejected challenge is reported in the body, not only by a status code.
        $problem = self::problem($response);
        if ($problem !== null) {
            return self::failure(self::classify($response['status'], self::REASON_CHALLENGE_FAILED), $problem);
        }

        return ['ok' => true, 'reason' => '', 'message' => ''];
    }

    /**
     * Wait for an authorization to leave `pending`.
     *
     * The CA validates asynchronously, so the operator-facing action cannot be a
     * single request. Bounded, and the last observed status is reported on
     * timeout, because "still pending after 30s" is a different message from
     * "invalid".
     */
    public function pollAuthorization(string $url, int $attempts = 15, int $delayMs = 2000): array
    {
        $last = ['ok' => true, 'status' => 'pending', 'domain' => '', 'wildcard' => false, 'challenges' => [], 'expires' => '', 'url' => $url, 'reason' => '', 'message' => ''];

        for ($i = 0; $i < max(1, $attempts); $i++) {
            if ($i > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $last = $this->authorization($url);
            if ($last['ok'] !== true) {
                return $last;
            }

            if ($last['status'] === 'valid') {
                return $last;
            }

            if ($last['status'] === 'invalid') {
                return self::failure(
                    self::REASON_AUTHORIZATION_FAILED,
                    self::authorizationProblem($last['challenges']) ?: 'the CA could not validate the challenge for ' . ($last['domain'] ?: 'a domain'),
                ) + ['authorization' => $last];
            }
        }

        // A *pending* answer is not a rejection: the operator can come back once
        // a slow DNS record propagates. It carries its own classification so the
        // caller can tell the two apart without reading English.
        return self::failure(
            self::REASON_AUTHORIZATION_PENDING,
            'the CA had not finished validating ' . ($last['domain'] ?: 'a domain') . ' after ' . max(1, $attempts) . ' checks',
        ) + ['authorization' => $last];
    }

    /**
     * Hand the CA the CSR and wait for the order to become valid.
     *
     * @param string $csrPem a PEM CSR covering exactly the ordered names
     */
    public function finalize(string $finalizeUrl, string $csrPem): array
    {
        $der = self::pemToDer($csrPem);
        if ($der === null) {
            return self::failure(self::REASON_FINALIZE_FAILED, 'the certificate signing request is not a PEM CSR');
        }

        $response = $this->post($finalizeUrl, ['csr' => self::b64($der)], $this->accountUrl);

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_FINALIZE_FAILED), $response['error']);
        }

        $problem = self::problem($response);
        if ($problem !== null) {
            return self::failure(self::REASON_FINALIZE_FAILED, $problem);
        }

        $body = self::decode($response['body']) ?? [];

        return [
            'ok'            => true,
            'status'        => (string) ($body['status'] ?? ''),
            'order_url'     => (string) ($response['headers']['location'] ?? ''),
            'certificate'   => (string) ($body['certificate'] ?? ''),
            'reason'        => '',
            'message'       => '',
        ];
    }

    /** Ask the CA for an order's state, so a finalize can be waited on. */
    public function order(string $orderUrl): array
    {
        $response = $this->postAsGet($orderUrl);

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_ORDER_FAILED), $response['error']);
        }

        $body = self::decode($response['body']);
        if ($body === null) {
            return self::failure(self::REASON_ORDER_FAILED, 'the CA returned an unreadable order');
        }

        // The order document is the only authority on which authorizations are
        // still outstanding: a re-issued order, or one the CA has re-rolled after
        // a renewal window, can have a different set than the one `newOrder`
        // returned. A caller that completes an order from its *stored* list would
        // finalize while an authorization was still pending.
        return [
            'ok'             => true,
            'status'         => (string) ($body['status'] ?? ''),
            'certificate'    => (string) ($body['certificate'] ?? ''),
            'authorizations' => array_values((array) ($body['authorizations'] ?? [])),
            'finalize'       => (string) ($body['finalize'] ?? ''),
            'error'          => (string) ($body['error']['detail'] ?? ''),
            'reason'         => '',
            'message'        => '',
        ];
    }

    /** Wait for an order to become `valid`, returning the certificate URL. */
    public function pollOrder(string $orderUrl, int $attempts = 15, int $delayMs = 2000): array
    {
        $last = ['ok' => true, 'status' => 'processing', 'certificate' => '', 'error' => '', 'reason' => '', 'message' => ''];

        for ($i = 0; $i < max(1, $attempts); $i++) {
            if ($i > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $last = $this->order($orderUrl);
            if ($last['ok'] !== true) {
                return $last;
            }

            if ($last['status'] === 'valid') {
                return $last;
            }

            if ($last['status'] === 'invalid') {
                return self::failure(self::REASON_ORDER_FAILED, $last['error'] !== '' ? $last['error'] : 'the CA rejected the order');
            }
        }

        return self::failure(self::REASON_ORDER_PENDING, 'the CA had not issued the certificate after ' . max(1, $attempts) . ' checks');
    }

    /** Download the issued certificate chain. */
    public function download(string $certificateUrl): array
    {
        $response = $this->postAsGet($certificateUrl);

        if ($response['error'] !== null) {
            return self::failure(self::classify($response['status'], self::REASON_CERTIFICATE_FAILED), $response['error']);
        }

        $chain = trim($response['body']);
        if (! str_contains($chain, 'BEGIN CERTIFICATE')) {
            return self::failure(self::REASON_CERTIFICATE_FAILED, 'the CA returned no PEM certificate chain');
        }

        return ['ok' => true, 'chain' => $chain, 'reason' => '', 'message' => ''];
    }

    /**
     * The whole issuance for one order, from a fresh order to a downloaded chain.
     *
     * Used by the tests and by nothing in production: the operator-facing path is
     * staged, because an HTTP-01 or DNS-01 challenge needs a human-visible step
     * (a published file, a TXT record) between "ordered" and "validated".
     *
     * @param list<string> $domains
     * @param array<string, callable(string, string): bool> $publish token => keyAuthorization handlers, keyed by challenge type
     */
    public function issue(array $domains, string $contactEmail, string $csrPem, array $publish = [], int $attempts = 15, int $delayMs = 2000): array
    {
        $account = $this->newAccount($contactEmail);
        if ($account['ok'] !== true) {
            return $account;
        }

        $order = $this->newOrder($domains);
        if ($order['ok'] !== true) {
            return $order;
        }

        foreach ($order['authorizations'] as $authorizationUrl) {
            $authorization = $this->authorization((string) $authorizationUrl);
            if ($authorization['ok'] !== true) {
                return $authorization;
            }

            if ($authorization['status'] === 'valid') {
                continue; // Already proven for this account key.
            }

            // A wildcard authorization can only be proven by DNS-01, and the CA
            // will not offer HTTP-01 for one.
            $type = ($authorization['wildcard'] ?? false) ? self::CHALLENGE_DNS : self::CHALLENGE_HTTP;
            $challenge = self::challenge($authorization, $type) ?? self::challenge($authorization, self::CHALLENGE_HTTP) ?? self::challenge($authorization, self::CHALLENGE_DNS);
            if ($challenge === null) {
                return self::failure(self::REASON_CHALLENGE_FAILED, 'the CA offered no challenge this client can complete for ' . ($authorization['domain'] ?: 'a domain'));
            }

            $token = (string) ($challenge['token'] ?? '');
            $kind  = (string) ($challenge['type'] ?? '');

            if (isset($publish[$kind])) {
                $publish[$kind]($token, $kind === self::CHALLENGE_DNS ? $this->dnsTxtValue($token) : $this->keyAuthorization($token));
            }

            $responded = $this->respondToChallenge((string) ($challenge['url'] ?? ''));
            if ($responded['ok'] !== true) {
                return $responded;
            }

            $polled = $this->pollAuthorization((string) $authorizationUrl, $attempts, $delayMs);
            if ($polled['ok'] !== true) {
                return $polled;
            }
        }

        $finalized = $this->finalize((string) $order['finalize'], $csrPem);
        if ($finalized['ok'] !== true) {
            return $finalized;
        }

        $orderUrl = $finalized['order_url'] !== '' ? $finalized['order_url'] : (string) $order['order_url'];

        $ready = $finalized;
        if ($ready['status'] !== 'valid') {
            $ready = $this->pollOrder($orderUrl, $attempts, $delayMs);
            if ($ready['ok'] !== true) {
                return $ready;
            }
        }

        return $this->download((string) $ready['certificate']);
    }

    // ---------------------------------------------------------------------
    // JWS & request plumbing
    // ---------------------------------------------------------------------

    /**
     * Build and send one signed POST.
     *
     * `$kid` empty means the request is signed with the embedded JWK, which is
     * only correct for `newAccount`; every other request must name the account.
     */
    private function post(string $url, array $payload, string $kid): array
    {
        $attempt = 0;

        while (true) {
            $nonce = $this->nonce();
            if ($nonce === null) {
                return [
                    'status'  => 0,
                    'headers' => [],
                    'body'    => '',
                    'error'   => 'the CA did not supply a replay nonce',
                ];
            }

            $protected = ['alg' => 'ES256', 'nonce' => $nonce, 'url' => $url];
            if ($kid !== '') {
                $protected['kid'] = $kid;
            } else {
                $protected['jwk'] = $this->jwk();
            }

            $response = $this->request(
                'POST',
                $url,
                self::jws($protected, $payload, $this->accountKeyPem),
                ['Content-Type' => 'application/jose+json'],
            );

            $this->rememberNonce($response['headers']);

            if ($response['error'] !== null) {
                return $response;
            }

            // A stale nonce is the protocol's own retry case, not a failure.
            if ($response['status'] === 400 && self::problemType($response['body']) === 'urn:ietf:params:acme:error:badNonce' && $attempt === 0) {
                $attempt++;
                $this->nonce = null;

                continue;
            }

            return $response;
        }
    }

    /** The ACME `POST-as-GET` form: a signed request with an empty payload. */
    private function postAsGet(string $url): array
    {
        $response = $this->post($url, [], $this->accountUrl);
        if ($response['error'] === null) {
            $problem = self::problem($response);
            if ($problem !== null) {
                $response['error'] = $problem;
            }
        }

        return $response;
    }

    /**
     * One HTTP request through the injected transport, with the caps this class
     * promises applied to whatever comes back.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string,string>, body: string, error: string|null}
     */
    private function request(string $method, string $url, string $body, array $headers): array
    {
        if (! str_starts_with($url, 'https://')) {
            return self::transportFailure('refused a non-https ACME endpoint');
        }

        $response = ($this->transport)([
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ]);

        $status  = (int) ($response['status'] ?? 0);
        $payload = (string) ($response['body'] ?? '');
        $extra   = $response['error'] ?? null;

        if (is_string($extra) && $extra !== '') {
            return ['status' => $status, 'headers' => [], 'body' => '', 'error' => $extra];
        }

        if (strlen($payload) > self::MAX_RESPONSE_BYTES) {
            return self::transportFailure(sprintf('the CA response exceeds the %d KiB limit', intdiv(self::MAX_RESPONSE_BYTES, 1024)));
        }

        $normalised = [];
        foreach ((array) ($response['headers'] ?? []) as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }

        // A 4xx/5xx body is the CA's problem document, which the caller needs to
        // classify the failure; anything else with a bad status is an error here.
        if ($status >= 400 && $payload === '') {
            return ['status' => $status, 'headers' => $normalised, 'body' => '', 'error' => 'the CA returned status ' . $status];
        }

        return ['status' => $status, 'headers' => $normalised, 'body' => $payload, 'error' => null];
    }

    /** The nonce to sign with: cached from the last response, else fetched. */
    private function nonce(): ?string
    {
        if ($this->nonce !== null && $this->nonce !== '') {
            return $this->nonce;
        }

        $ready = $this->directory();
        if ($ready['ok'] !== true) {
            return null;
        }

        $response = $this->request('HEAD', (string) $this->document['newNonce'], '', []);
        $this->rememberNonce($response['headers']);

        return $this->nonce;
    }

    private function rememberNonce(array $headers): void
    {
        $nonce = (string) ($headers['replay-nonce'] ?? '');
        if ($nonce !== '') {
            $this->nonce = $nonce;
        }
    }

    /**
     * A flattened-JSON JWS over one request.
     *
     * @param array<string, mixed> $protected
     * @param array<string, mixed> $payload
     */
    private static function jws(array $protected, array $payload, string $keyPem): string
    {
        $encodedProtected = self::b64((string) json_encode($protected, JSON_UNESCAPED_SLASHES));
        $encodedPayload   = self::b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($keyPem);
        $der = '';
        if ($key === false || ! openssl_sign($encodedProtected . '.' . $encodedPayload, $der, $key, OPENSSL_ALGO_SHA256)) {
            $der = '';
        }

        $raw = self::derToRaw($der, self::PART_BYTES) ?? '';

        return (string) json_encode([
            'protected' => $encodedProtected,
            'payload'   => $encodedPayload,
            'signature' => self::b64($raw),
        ], JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------------
    // Keys, JWK, CSR
    // ---------------------------------------------------------------------

    /** A fresh P-256 private key, PEM encoded. */
    public static function generateKey(): string
    {
        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ];

        $config = OpenSslConfig::apply();
        if ($config !== null) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new($options);

        $pem = '';
        if ($key !== false) {
            // The export needs the config too: without it this returns false and
            // leaves an empty string on hosts whose config is not auto-detected.
            openssl_pkey_export($key, $pem, null, $config === null ? [] : ['config' => $config]);
        }

        return $pem;
    }

    /**
     * A fresh RSA private key, PEM encoded.
     *
     * The account key is EC because JOSE signs with it; the *certificate* key is
     * RSA because it has to satisfy consumers the PKI module already has — Squid,
     * OpenVPN and LDAP clients that were configured against RSA key material.
     */
    public static function generateRsaKey(int $bits = 2048): string
    {
        $config = OpenSslConfig::apply();

        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => $bits,
        ];
        if ($config !== null) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new($options);

        $pem = '';
        if ($key !== false) {
            openssl_pkey_export($key, $pem, null, $config === null ? [] : ['config' => $config]);
        }

        return $pem;
    }

    /**
     * The public JWK of an EC private key.
     *
     * @return array{crv: string, kty: string, x: string, y: string}
     */
    public static function keyToJwk(string $keyPem): array
    {
        $key = openssl_pkey_get_private($keyPem);
        $ec  = $key === false ? null : (openssl_pkey_get_details($key)['ec'] ?? null);

        return [
            'crv' => 'P-256',
            'kty' => 'EC',
            'x'   => self::b64(str_pad((string) ($ec['x'] ?? ''), self::PART_BYTES, "\0", STR_PAD_LEFT)),
            'y'   => self::b64(str_pad((string) ($ec['y'] ?? ''), self::PART_BYTES, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * The RFC 7638 thumbprint: SHA-256 over the JWK's required members, in
     * lexicographic order and with no whitespace. The exact serialization is the
     * point — one space, or a different member order, is a different thumbprint.
     *
     * @param array<string, string> $jwk
     */
    public static function jwkThumbprint(array $jwk): string
    {
        $canonical = [
            'crv' => (string) ($jwk['crv'] ?? ''),
            'kty' => (string) ($jwk['kty'] ?? ''),
            'x'   => (string) ($jwk['x'] ?? ''),
            'y'   => (string) ($jwk['y'] ?? ''),
        ];

        return self::b64(hash('sha256', (string) json_encode($canonical), true));
    }

    /**
     * A CSR covering exactly the ordered names, signed with its own fresh key.
     *
     * Each certificate gets a new key: reusing one across renewals is the
     * practice a compromise turns into a long-lived problem.
     *
     * @param list<string> $domains
     * @param string|null  $error   why no CSR could be produced, when null is returned
     */
    public static function csrFor(array $domains, string $keyPem, ?string &$error = null): ?string
    {
        $domains = array_values(array_filter(array_map('trim', $domains), static fn (string $d): bool => $d !== ''));
        if ($domains === []) {
            $error = 'a certificate needs at least one domain';

            return null;
        }

        $config = tempnam(sys_get_temp_dir(), 'micron-acme-cnf-');
        $conf   = "[req]\n"
            . "distinguished_name = dn\n"
            . "req_extensions = v3_req\n"
            . "prompt = no\n\n"
            . "[dn]\nCN = " . $domains[0] . "\n\n"
            . "[v3_req]\n"
            . "basicConstraints = CA:FALSE\n"
            . "keyUsage = critical, digitalSignature, keyEncipherment\n"
            . "extendedKeyUsage = serverAuth\n"
            . "subjectAltName = @alt\n\n"
            . "[alt]\n";

        foreach ($domains as $index => $domain) {
            $conf .= 'DNS.' . ($index + 1) . ' = ' . $domain . "\n";
        }

        file_put_contents($config, $conf);

        $key = openssl_pkey_get_private($keyPem);
        if ($key === false) {
            @unlink($config);
            $error = 'the generated private key is unusable';

            return null;
        }

        $csr = openssl_csr_new(
            ['commonName' => $domains[0]],
            $key,
            ['config' => $config, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256'],
        );

        @unlink($config);

        if ($csr === false) {
            $error = 'OpenSSL rejected the certificate signing request';

            return null;
        }

        $pem = '';
        openssl_csr_export($csr, $pem);

        return $pem === '' ? null : $pem;
    }

    /**
     * EC signatures leave OpenSSL DER encoded (`SEQUENCE { INTEGER r, INTEGER s }`)
     * and JOSE wants the fixed-width `r‖s`. Convert, or the CA rejects a request
     * whose header, payload and JWK are all correct.
     *
     * Rejecting anything that is not a two-integer DER sequence is deliberate: a
     * silently zero-padded buffer would produce a signature that verifies
     * nowhere and is very hard to diagnose.
     */
    public static function derToRaw(string $der, int $partLen = self::PART_BYTES): ?string
    {
        $length = strlen($der);
        if ($length < 8 || $der[0] !== "\x30") {
            return null;
        }

        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F; // long-form length
        }

        $parts = [];
        foreach (['r', 's'] as $_) {
            if ($offset + 2 > $length || $der[$offset] !== "\x02") {
                return null;
            }

            $size   = ord($der[$offset + 1]);
            $offset += 2;

            if ($offset + $size > $length) {
                return null;
            }

            $integer = ltrim(substr($der, $offset, $size), "\x00");
            $offset += $size;

            if (strlen($integer) > $partLen) {
                return null;
            }

            $parts[] = str_pad($integer, $partLen, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** base64url, unpadded — the only encoding JOSE uses. */
    private static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** The DER bytes inside a PEM block. */
    private static function pemToDer(string $pem): ?string
    {
        if (preg_match('/-----BEGIN [A-Z ]+-----(.+?)-----END [A-Z ]+-----/s', $pem, $match) !== 1) {
            return null;
        }

        $der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);

        return $der === false || $der === '' ? null : $der;
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The ACME problem document's `detail`, when the response carries one.
     *
     * The CA reports challenge and order refusals in the body of an otherwise
     * well-formed 200/4xx, so this is checked before a response is trusted.
     *
     * @param array{status: int, headers: array<string,string>, body: string, error: string|null} $response
     */
    private static function problem(array $response): ?string
    {
        if ($response['status'] < 400) {
            return null;
        }

        $body = self::decode($response['body']);

        if (is_array($body) && isset($body['detail'])) {
            return (string) $body['detail'];
        }

        return 'the CA refused the request with status ' . $response['status'];
    }

    /** The `type` of a problem document, used to spot a stale nonce. */
    private static function problemType(string $body): string
    {
        $decoded = self::decode($body);

        return is_array($decoded) ? (string) ($decoded['type'] ?? '') : '';
    }

    /**
     * The reason a challenge was rejected, taken from the challenge's own error.
     *
     * @param list<array<string, mixed>> $challenges
     */
    private static function authorizationProblem(array $challenges): string
    {
        foreach ($challenges as $challenge) {
            $detail = (string) ($challenge['error']['detail'] ?? '');
            if ($detail !== '') {
                return $detail;
            }
        }

        return '';
    }

    /**
     * Classify a failed request, so a rate limit is distinguishable from a
     * transient outage without reading prose.
     */
    private static function classify(int $status, string $fallback): string
    {
        return $status === 429 ? self::REASON_RATE_LIMITED : $fallback;
    }

    /** @return array{ok: false, reason: string, message: string} */
    private static function failure(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }

    /** @return array{status: int, headers: array<string,string>, body: string, error: string} */
    private static function transportFailure(string $error): array
    {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error];
    }

    /**
     * The default transport: HTTPS only, one hop, no redirects, bounded.
     *
     * @return callable(array{method:string,url:string,headers:array<string,string>,body:string}): array
     */
    public static function curlTransport(): callable
    {
        return static function (array $request): array {
            if (! function_exists('curl_init')) {
                return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'the cURL extension is required for ACME'];
            }

            $handle = curl_init();
            $header = [];
            foreach ((array) $request['headers'] as $name => $value) {
                $header[] = $name . ': ' . $value;
            }

            $received = [];
            curl_setopt_array($handle, [
                CURLOPT_URL            => (string) $request['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_CUSTOMREQUEST  => (string) $request['method'],
                CURLOPT_HTTPHEADER     => $header,
                CURLOPT_NOBODY         => (string) $request['method'] === 'HEAD',
                CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$received): int {
                    $split = strpos($line, ':');
                    if ($split !== false) {
                        $received[strtolower(trim(substr($line, 0, $split)))] = trim(substr($line, $split + 1));
                    }

                    return strlen($line);
                },
            ]);

            if ((string) $request['method'] === 'POST') {
                curl_setopt($handle, CURLOPT_POSTFIELDS, (string) $request['body']);
            }

            $body   = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($handle);
            curl_close($handle);

            if ($body === false) {
                return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error !== '' ? $error : 'the request to the CA failed'];
            }

            return [
                'status'  => $status,
                'headers' => $received,
                'body'    => (string) $body,
                'error'   => null,
            ];
        };
    }
}

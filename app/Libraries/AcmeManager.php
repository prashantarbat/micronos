<?php

namespace App\Libraries;

use App\Models\PkiAuthorityModel;
use App\Models\PkiCertificateModel;

/**
 * ACME / Let's Encrypt certificate issuance and renewal for the PKI module.
 *
 * {@see Acme} is the protocol; this is the appliance-facing half: which account
 * exists, which orders are in flight, how a downloaded chain becomes a row in
 * the PKI store, and when a certificate is due for renewal.
 *
 * ## Ordering against the rest of the module
 *
 * A certificate issued here lands in `pki_certificates` with `source = 'acme'`
 * and its `ca_id` pointing at an authority row that holds the issuer's chain and
 * **no private key**. That is the whole integration: WireGuard, OpenVPN, IPsec,
 * Squid SSL, LDAP and the Web GUI already read those two tables, so nothing else
 * changes to make a publicly-trusted certificate usable.
 *
 * ## The challenge is served from a directory, not the database
 *
 * HTTP-01 must answer `GET /.well-known/acme-challenge/<token>` for *every* name
 * in the order, at a moment the CA chooses. The key authorization for each
 * pending token is therefore written to `writable/acme-challenges/<token>` when
 * the order is created and removed as soon as the order finishes, one file per
 * token — the same shape as certbot's webroot. Serving from the database instead
 * would mean one stored token per order and silently broken multi-name orders.
 *
 * The directory is under `writable/`, never the web root, and the public route
 * only ever reads a file whose name matches the RFC 8555 token grammar — so no
 * traversal is possible even if a token arrives crafted.
 *
 * ## Two steps, because validation is asynchronous
 *
 * The CA proves the challenge on its own schedule. `begin()` returns the
 * challenge material; `complete()` publishes it, waits, finalizes, downloads and
 * installs. For HTTP-01 the appliance is the web server, so a page can chain the
 * two in one click; for DNS-01 a human has to publish the TXT record in between.
 */
final class AcmeManager
{
    /** Renew this many days before expiry. */
    public const RENEW_WINDOW_DAYS = 30;

    /** Where the public HTTP-01 answers live, relative to `WRITEPATH`. */
    public const CHALLENGE_PATH = 'acme-challenges';

    /** A pending token nobody collected is worthless after this long. */
    private const CHALLENGE_TTL = 86400;

    /** How many names one order may cover. */
    private const MAX_DOMAINS = 100;

    /** X.509 `cert_type` values the PKI store accepts. */
    private const CERT_TYPES = ['server', 'client', 'user', 'vpn'];

    private $db;

    /** @var callable(string, ?string): Acme */
    private $clientFactory;

    private $ipc;

    /**
     * @param callable|null      $clientFactory `function(string $directory, ?string $accountKeyPem): Acme`
     * @param \App\Libraries\IpcClient|null $ipc
     */
    public function __construct(?callable $clientFactory = null, $ipc = null)
    {
        $this->db            = \Config\Database::connect();
        $this->clientFactory = $clientFactory ?? static fn (string $directory, ?string $key): Acme => new Acme(null, $directory, $key);
        $this->ipc           = $ipc ?? \Config\Services::ipc();
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * The ACME directories an operator may pick, keyed by the stored name.
     *
     * @return array<string, string>
     */
    public static function directories(): array
    {
        return Acme::DIRECTORIES;
    }

    /** A friendly label for a stored directory URL. */
    public static function directoryLabel(string $directory): string
    {
        foreach (Acme::DIRECTORIES as $name => $url) {
            if ($url === $directory) {
                return 'Let\'s Encrypt' . ($name === 'letsencrypt' ? '' : ' (Staging)');
            }
        }

        return 'Unknown CA';
    }

    /** The stored account for a directory, or null. */
    public function account(string $directory): ?array
    {
        $row = $this->db->table('pki_acme_accounts')->where('directory', $directory)->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Every known account, so the page can show which directories are set up.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return $this->db->table('pki_acme_accounts')->orderBy('directory', 'ASC')->get()->getResultArray();
    }

    /** The contact address registered with a directory, or ''. */
    public function contactEmail(string $directory): string
    {
        return (string) ($this->account($directory)['contact_email'] ?? '');
    }

    /**
     * Tell the CA who we are, creating the account on first use.
     *
     * @return array{ok: bool, reason: string, message: string, account?: array<string,mixed>}
     */
    public function registerAccount(string $directory, string $email): array
    {
        if (! Acme::isAllowedDirectory($directory)) {
            return self::failure(Acme::REASON_NOT_CONFIGURED, 'that is not an ACME directory this appliance will contact');
        }

        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::failure(Acme::REASON_NOT_CONFIGURED, 'the contact address is not a valid email address');
        }

        $existing = $this->account($directory);
        $key      = trim((string) ($existing['private_key'] ?? ''));

        // A stored key becomes a new key only when it cannot be read: reusing the
        // account key is what makes this idempotent (the same key is the same
        // account at the CA), and regenerating it silently would strand every
        // certificate issued so far.
        if ($key !== '' && openssl_pkey_get_private($key) === false) {
            $key = '';
        }

        $client  = ($this->clientFactory)($directory, $key === '' ? null : $key);
        $keyPem  = $client->accountKey();

        $response = $client->newAccount($email);
        if ($response['ok'] !== true) {
            $this->rememberAccount($directory, $email, $keyPem, (string) ($existing['account_url'] ?? ''), 'error', (string) $response['message']);

            return self::failure((string) $response['reason'], (string) $response['message']);
        }

        $account = $this->rememberAccount($directory, $email, $keyPem, (string) $response['account_url'], 'registered', null);

        return ['ok' => true, 'reason' => '', 'message' => '', 'account' => $account];
    }

    /**
     * Persist an account row, generating the key when the caller has none.
     *
     * The private key is never logged or returned to a view; only its presence is
     * ever displayed.
     */
    private function rememberAccount(string $directory, string $email, string $keyPem, string $accountUrl, string $status, ?string $error): array
    {
        $now  = date('Y-m-d H:i:s');
        $row  = $this->account($directory);

        $data = [
            'directory'     => $directory,
            'contact_email' => $email,
            'account_url'   => $accountUrl,
            // Trimmed, because every read path trims it: storing the PEM with the
            // trailing newline OpenSSL exports and reading it back without one
            // makes the same account look like two different keys.
            'private_key'   => trim($keyPem),
            'status'        => $status,
            'last_error'    => $error,
            'updated_at'    => $now,
        ];

        if ($row === null) {
            $data['created_at'] = $now;
            $this->db->table('pki_acme_accounts')->insert($data);

            return $this->account($directory) ?? $data;
        }

        $this->db->table('pki_acme_accounts')->where('directory', $directory)->update($data);

        return $this->account($directory) ?? $data;
    }

    // ---------------------------------------------------------------------
    // Issuance
    // ---------------------------------------------------------------------

    /**
     * Open an order and describe the challenge the operator (or this appliance)
     * must satisfy.
     *
     * @param array<string, mixed> $input directory, email, domains, challenge_type,
     *                                    cert_type, name, cert_id (a renewal)
     *
     * @return array<string, mixed>
     */
    public function begin(array $input): array
    {
        $directory = (string) ($input['directory'] ?? '');
        if (! Acme::isAllowedDirectory($directory)) {
            return self::failure(Acme::REASON_NOT_CONFIGURED, 'choose an ACME directory to issue from');
        }

        $domains = self::normaliseDomains($input['domains'] ?? '', (string) ($input['challenge_type'] ?? ''), $error);
        if ($domains === []) {
            return self::failure(Acme::REASON_NOT_CONFIGURED, (string) $error);
        }

        $challengeType = self::challengeType((string) ($input['challenge_type'] ?? ''), $domains);
        if ($challengeType === null) {
            return self::failure(
                Acme::REASON_NOT_CONFIGURED,
                'a wildcard name can only be validated with DNS-01, which this appliance has no way to publish automatically'
            );
        }

        $email   = trim((string) ($input['email'] ?? $this->contactEmail($directory)));
        $account = $this->registerAccount($directory, $email);
        if ($account['ok'] !== true) {
            return $account;
        }

        $certId = isset($input['cert_id']) ? (int) $input['cert_id'] : null;

        $client = ($this->clientFactory)($directory, $account['account']['private_key']);

        $order = $client->newOrder($domains);
        if ($order['ok'] !== true) {
            $this->recordOrderFailure($certId, $directory, $domains, $challengeType, (string) $order['reason'], (string) $order['message']);

            return $order;
        }

        // Every authorization needs its own proof: a two-name order needs two
        // challenge files, and a DNS-01 order needs two TXT records.
        $pending = [];
        foreach ($order['authorizations'] as $authorizationUrl) {
            $authorization = $client->authorization((string) $authorizationUrl);
            if ($authorization['ok'] !== true) {
                return $authorization;
            }

            if ($authorization['status'] === 'valid') {
                continue; // Already proven for this account key.
            }

            $challenge = Acme::challenge($authorization, $challengeType);
            if ($challenge === null) {
                return self::failure(
                    Acme::REASON_CHALLENGE_FAILED,
                    'the CA offered no ' . $challengeType . ' challenge for ' . ($authorization['domain'] ?: 'a domain'),
                );
            }

            $pending[] = [
                'url'     => (string) ($challenge['url'] ?? ''),
                'token'   => (string) ($challenge['token'] ?? ''),
                'type'    => (string) ($challenge['type'] ?? ''),
                'domain'  => (string) $authorization['domain'],
                'authz'   => (string) $authorizationUrl,
                'value'   => $challengeType === Acme::CHALLENGE_DNS
                    ? $client->dnsTxtValue((string) ($challenge['token'] ?? ''))
                    : $client->keyAuthorization((string) ($challenge['token'] ?? '')),
            ];
        }

        // Order matters: the files must exist *before* the challenge is published
        // to the CA, or a fast validation can fetch a 404.
        foreach ($pending as $challenge) {
            if ($challenge['type'] === Acme::CHALLENGE_HTTP) {
                $this->writeChallenge($challenge['token'], $challenge['value']);
            }
        }

        $orderId = $this->storeOrder([
            'cert_id'        => $certId,
            'directory'      => $directory,
            'domains'        => implode(',', $domains),
            'challenge_type' => $challengeType,
            'cert_type'      => self::certType((string) ($input['cert_type'] ?? 'server')),
            'name'           => mb_substr(trim((string) ($input['name'] ?? '')), 0, 100) ?: null,
            'status'         => 'pending',
            'order_url'      => (string) ($order['order_url'] ?? ''),
            'finalize_url'   => (string) $order['finalize'],
            // The first pending challenge is stored for display and for the
            // renewal record; `complete()` re-reads the order for the authoritative
            // list, because a multi-name order has one authorization per name.
            'authz_url'      => $pending === [] ? null : $pending[0]['authz'],
            'challenge_url'  => $pending === [] ? null : $pending[0]['url'],
            'token'          => $pending === [] ? null : $pending[0]['token'],
            'cert_url'       => null,
            'reason'         => null,
            'last_error'     => null,
        ]);

        return [
            'ok'             => true,
            'order_id'       => $orderId,
            // An order that has been opened is *pending*, always: the CA has not
            // confirmed anything yet. Callers branch on this to tell "waiting for
            // validation" from "done", so it is stated even when every name was
            // already validated for this account key.
            'status'         => $pending === [] ? 'ready' : 'pending',
            'directory'      => $directory,
            'domains'        => $domains,
            'challenge_type' => $challengeType,
            'challenges'     => $pending,
            'already_valid'  => $pending === [],
            'reason'         => '',
            'message'        => $pending === []
                ? 'The CA already considers every name in this order validated.'
                : self::instructions($challengeType, $pending),
        ];
    }

    /**
     * Publish the challenge, wait for the CA, then finalize, download and install.
     *
     * @return array<string, mixed>
     */
    public function complete(int $orderId, int $attempts = 10, int $delayMs = 2000): array
    {
        $order = $this->order($orderId);
        if ($order === null) {
            return self::failure(Acme::REASON_ORDER_FAILED, 'no such ACME order');
        }

        $account = $this->account((string) $order['directory']);
        if ($account === null || trim((string) $account['private_key']) === '') {
            return self::failure(Acme::REASON_NOT_CONFIGURED, 'the ACME account key for this order is missing');
        }

        $client = ($this->clientFactory)((string) $order['directory'], (string) $account['private_key']);

        $challengeType = (string) $order['challenge_type'];
        $domains       = self::splitDomains((string) $order['domains']);

        // Re-read the order rather than trusting what was stored: the CA is the
        // authority on which authorizations are outstanding, and a repeated click
        // must not re-post a challenge it has already accepted.
        $current = $client->order((string) $order['order_url']);
        if ($current['ok'] !== true) {
            return $this->failOrder($orderId, $current);
        }

        $tokens = [];

        foreach ((array) $current['authorizations'] as $authorizationUrl) {
            $authorization = $client->authorization((string) $authorizationUrl);
            if ($authorization['ok'] !== true) {
                return $this->failOrder($orderId, $authorization);
            }

            if (! in_array($authorization['status'], ['pending', 'processing'], true)) {
                if ($authorization['status'] === 'valid') {
                    continue;
                }

                // `invalid` is terminal for the authorization, and the CA's own
                // detail (a DNS problem, a 404 on the file) is the only useful
                // thing to show.
                return $this->failOrder($orderId, self::failure(
                    Acme::REASON_AUTHORIZATION_FAILED,
                    'the CA could not validate ' . ($authorization['domain'] ?: 'a name') . ': '
                        . self::authorizationDetail($authorization),
                ), $tokens);
            }

            $challenge = Acme::challenge($authorization, $challengeType);
            if ($challenge === null) {
                return $this->failOrder($orderId, self::failure(
                    Acme::REASON_CHALLENGE_FAILED,
                    'the CA offered no ' . $challengeType . ' challenge for ' . ($authorization['domain'] ?: 'a name'),
                ), $tokens);
            }

            $token = (string) ($challenge['token'] ?? '');

            // HTTP-01 is published again here as well as in begin(): an operator
            // who clicked "check" a day later must still get a served file.
            if ($challengeType === Acme::CHALLENGE_HTTP && $token !== '') {
                $this->writeChallenge($token, $client->keyAuthorization($token));
                $tokens[] = $token;
            }

            if ($authorization['status'] === 'pending') {
                $responded = $client->respondToChallenge((string) ($challenge['url'] ?? ''));
                if ($responded['ok'] !== true) {
                    return $this->failOrder($orderId, $responded, $tokens);
                }
            }

            $polled = $client->pollAuthorization((string) $authorizationUrl, $attempts, $delayMs);
            if ($polled['ok'] !== true) {
                // "not finished yet" is a bounded wait, not a rejection: the
                // operator can try again once a slow DNS record propagates. The
                // challenge stays served for exactly that reason.
                if (($polled['reason'] ?? '') === Acme::REASON_AUTHORIZATION_PENDING) {
                    $this->noteOrder($orderId, 'pending', (string) $polled['reason'], (string) $polled['message']);

                    return $polled + ['order_id' => $orderId, 'status' => 'pending'];
                }

                return $this->failOrder($orderId, $polled, $tokens);
            }
        }

        $key = Acme::generateRsaKey();
        if ($key === '') {
            return $this->failOrder($orderId, self::failure(Acme::REASON_FINALIZE_FAILED, 'could not generate a private key for the certificate'), $tokens);
        }

        $csr = Acme::csrFor($domains, $key, $csrError);
        if ($csr === null) {
            return $this->failOrder($orderId, self::failure(Acme::REASON_FINALIZE_FAILED, (string) $csrError), $tokens);
        }

        $finalized = $client->finalize((string) $order['finalize_url'], $csr);
        if ($finalized['ok'] !== true) {
            return $this->failOrder($orderId, $finalized, $tokens);
        }

        $ready = $finalized;
        if (($ready['status'] ?? '') !== 'valid') {
            $ready = $client->pollOrder((string) $order['order_url'], $attempts, $delayMs);

            // The authorizations are all valid at this point, so a slow issuance
            // is retryable on its own: the next click re-polls and downloads,
            // rather than restarting the whole order.
            if ($ready['ok'] !== true) {
                if (($ready['reason'] ?? '') === Acme::REASON_ORDER_PENDING) {
                    $this->noteOrder($orderId, 'pending', (string) $ready['reason'], (string) $ready['message']);

                    return $ready + ['order_id' => $orderId, 'status' => 'pending'];
                }

                return $this->failOrder($orderId, $ready, $tokens);
            }
        }

        $downloaded = $client->download((string) $ready['certificate']);
        if ($downloaded['ok'] !== true) {
            return $this->failOrder($orderId, $downloaded, $tokens);
        }

        $stored = $this->store([
            'cert_id'   => $order['cert_id'] === null ? null : (int) $order['cert_id'],
            'domains'   => $domains,
            'chain'     => (string) $downloaded['chain'],
            'key'       => $key,
            'directory' => (string) $order['directory'],
            'cert_type' => (string) ($order['cert_type'] ?? 'server'),
            'name'      => (string) ($order['name'] ?? ''),
        ]);

        if ($stored['ok'] !== true) {
            return $this->failOrder($orderId, $stored, $tokens);
        }

        $this->noteOrder($orderId, 'valid', null, null, [
            'cert_id'      => $stored['cert_id'],
            'authority_id' => $stored['authority_id'],
            'cert_url'     => (string) ($ready['certificate'] ?? ''),
        ]);

        $this->forgetChallenges($tokens);

        // Services that read the PKI tables pick the certificate up on reload.
        // The Web GUI and the Clientless Gateway serve *copies* written to disk,
        // so a certificate bound to either is written out again first —
        // otherwise a renewal would never reach them.
        GatewayCertificate::redeployBindings((int) $stored['cert_id'], $this->ipc);
        $this->ipc->call('cert.reload');

        return [
            'ok'          => true,
            'order_id'    => $orderId,
            'cert_id'     => $stored['cert_id'],
            'certificate' => $stored['certificate'],
            'renewed'     => $order['cert_id'] !== null,
            'reason'      => '',
            'message'     => $order['cert_id'] !== null
                ? 'Certificate renewed and installed.'
                : 'Certificate issued and installed.',
        ];
    }

    /**
     * Issue a certificate: begin, and for HTTP-01 complete it in the same call.
     *
     * HTTP-01 needs no human step — the appliance already serves the token — so a
     * page can offer one action. DNS-01 returns the TXT records for the operator
     * to publish and stops.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function request(array $input, int $attempts = 10, int $delayMs = 2000): array
    {
        $begun = $this->begin($input);
        if ($begun['ok'] !== true) {
            return $begun;
        }

        if ($begun['challenge_type'] !== Acme::CHALLENGE_HTTP) {
            return $begun;
        }

        $done = $this->complete((int) $begun['order_id'], $attempts, $delayMs);
        if ($done['ok'] !== true) {
            $done['order_id'] = $begun['order_id'];
        }

        return $done;
    }

    /** The key authorization the public route must answer for a pending token. */
    public function challengeResponse(string $token): ?string
    {
        // RFC 8555 §8.3: the token is base64url. Anything else is not a token this
        // appliance ever wrote, and the strict pattern is what makes reading a
        // file by request-supplied name safe.
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $token) !== 1) {
            return null;
        }

        $path = $this->challengePath($token);
        if (! is_file($path)) {
            return null;
        }

        // A token is only meaningful while an order is in flight; a stale file is
        // an answer to a challenge nobody is waiting on.
        if (filemtime($path) !== false && (time() - filemtime($path)) > self::CHALLENGE_TTL) {
            @unlink($path);

            return null;
        }

        $body = (string) @file_get_contents($path);

        return $body === '' ? null : $body;
    }

    // ---------------------------------------------------------------------
    // Renewal
    // ---------------------------------------------------------------------

    /**
     * ACME certificates whose renewal window has opened, soonest first.
     *
     * Derived from `valid_until` rather than stored: the certificate is the
     * authority on when it expires, and a stored copy would be one more thing to
     * keep in step.
     *
     * @return list<array<string, mixed>>
     */
    public function due(int $windowDays = self::RENEW_WINDOW_DAYS): array
    {
        $deadline = date('Y-m-d H:i:s', time() + max(1, $windowDays) * 86400);

        $rows = $this->db->table('pki_certificates c')
            ->select('c.id, c.name, c.common_name, c.san_dns, c.valid_until, c.revoked, o.id AS order_id, o.directory, o.challenge_type, o.domains, o.status AS order_status')
            ->join('pki_acme_orders o', 'o.cert_id = c.id', 'left')
            ->where('c.source', 'acme')
            ->where('c.revoked', 0)
            ->groupStart()
                ->where('c.valid_until <=', $deadline)
                ->orWhere('c.valid_until', null)
            ->groupEnd()
            ->orderBy('c.valid_until', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as $index => $row) {
            $rows[$index]['days_left'] = self::daysLeft((string) ($row['valid_until'] ?? ''));
        }

        return $rows;
    }

    /**
     * Renew one certificate in place.
     *
     * The same `pki_certificates` row is updated rather than a new one inserted:
     * every binding (Web GUI certificate id, tunnel, service) points at the id,
     * and adding a row would quietly orphan them on the next renewal.
     *
     * @return array<string, mixed>
     */
    public function renew(int $certId, int $attempts = 10, int $delayMs = 2000): array
    {
        $cert = $this->db->table('pki_certificates')->where('id', $certId)->get()->getRowArray();
        if ($cert === null) {
            return self::failure(Acme::REASON_NOT_CONFIGURED, 'no such certificate');
        }

        if (($cert['source'] ?? '') !== 'acme') {
            return self::failure(Acme::REASON_NOT_CONFIGURED, 'that certificate was not issued through ACME');
        }

        $order = $this->db->table('pki_acme_orders')->where('cert_id', $certId)->get()->getRowArray();

        $directory     = (string) ($order['directory'] ?? '');
        $challengeType = (string) ($order['challenge_type'] ?? Acme::CHALLENGE_HTTP);
        $domains       = $order !== null && trim((string) $order['domains']) !== ''
            ? self::splitDomains((string) $order['domains'])
            : array_values(array_filter(array_map('trim', explode(',', (string) ($cert['san_dns'] ?? '')))));

        if ($domains === []) {
            $domains = [(string) $cert['common_name']];
        }

        return $this->request([
            'directory'      => $directory,
            'email'          => $this->contactEmail($directory),
            'domains'        => $domains,
            'challenge_type' => $challengeType,
            'cert_type'      => (string) ($cert['cert_type'] ?? 'server'),
            'name'           => (string) $cert['name'],
            'cert_id'        => $certId,
        ], $attempts, $delayMs);
    }

    /**
     * Renew everything whose window has opened. Used by the scheduled sweep.
     *
     * @return array{attempted: int, renewed: int, failed: list<array<string, mixed>>}
     */
    public function renewDue(int $attempts = 10, int $delayMs = 2000): array
    {
        $failed  = [];
        $renewed = 0;
        $due     = $this->due();

        foreach ($due as $row) {
            $result = $this->renew((int) $row['id'], $attempts, $delayMs);

            if ($result['ok'] === true) {
                $renewed++;

                continue;
            }

            $failed[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'reason'  => (string) $result['reason'],
                'message' => (string) $result['message'],
            ];
        }

        return ['attempted' => count($due), 'renewed' => $renewed, 'failed' => $failed];
    }

    // ---------------------------------------------------------------------
    // The PKI store
    // ---------------------------------------------------------------------

    /**
     * Turn a downloaded chain plus its key into PKI rows.
     *
     * The chain's first certificate is the leaf and the rest is the issuer's
     * chain, which is what the authority row carries so `downloadCert`/`pem`
     * bundles — and anything reading `ca.certificate` — produce a usable file.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function store(array $input): array
    {
        $chain = (string) $input['chain'];
        $leaf  = X509::normalizePem($chain, 'CERTIFICATE');

        if ($leaf === null) {
            return self::failure(Acme::REASON_CERTIFICATE_FAILED, 'the CA returned no readable leaf certificate');
        }

        $parsed = X509::parseCertificate($leaf);
        if ($parsed === null) {
            return self::failure(Acme::REASON_CERTIFICATE_FAILED, 'the issued certificate could not be parsed');
        }

        if (! X509::keyMatchesCertificate((string) $input['key'], $leaf)) {
            return self::failure(Acme::REASON_CERTIFICATE_FAILED, 'the issued certificate does not match the key that requested it');
        }

        $issuers = self::issuerChain($chain);
        $domain  = (string) ($input['domains'][0] ?? $parsed['common_name']);

        $caModel = new PkiAuthorityModel();
        $ca      = $this->issuingAuthority($caModel, (string) $input['directory'], $issuers, $parsed['issuer_cn']);

        if ($ca === null) {
            return self::failure(Acme::REASON_CERTIFICATE_FAILED, 'could not record the issuing authority');
        }

        $type = self::certType((string) ($input['cert_type'] ?? 'server'));

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = 'Let\'s Encrypt: ' . $domain;
        }

        $data = [
            'ca_id'         => (int) $ca['id'],
            'cert_type'     => $type,
            'name'          => mb_substr($name, 0, 100),
            'common_name'   => mb_substr($domain, 0, 128),
            'organization'  => $parsed['organization'] !== '' ? $parsed['organization'] : 'MicronOS Enterprise',
            'san_dns'       => mb_substr(implode(',', (array) $input['domains']), 0, 255),
            'san_ip'        => '',
            'certificate'   => $leaf,
            'private_key'   => (string) $input['key'],
            'csr'           => null,
            'serial_number' => $parsed['serial_number'] !== '' ? $parsed['serial_number'] : '0',
            'valid_from'    => $parsed['valid_from'],
            'valid_until'   => $parsed['valid_until'],
            'revoked'       => 0,
            'revoked_at'    => null,
            'description'   => 'Issued by ' . self::directoryLabel((string) $input['directory']) . ' via ACME',
            'source'        => 'acme',
        ];

        $certModel = new PkiCertificateModel();
        $certId    = $input['cert_id'] === null ? null : (int) $input['cert_id'];

        if ($certId !== null && $certModel->find($certId) !== null) {
            $certModel->update($certId, $data);
        } else {
            $certId = (int) $certModel->insert($data);
        }

        if ($certId <= 0) {
            return self::failure(Acme::REASON_CERTIFICATE_FAILED, 'the certificate could not be stored: ' . implode('; ', $certModel->errors()));
        }

        return [
            'ok'           => true,
            'cert_id'      => $certId,
            'authority_id' => (int) $ca['id'],
            'certificate'  => $data,
            'reason'       => '',
            'message'      => '',
        ];
    }

    /**
     * The authority row an ACME certificate hangs from.
     *
     * It carries the issuer chain and **no private key** — this appliance cannot
     * sign with it, and `source = 'acme'` is what tells the rest of the module
     * that (an empty key already makes the CA unusable for signing, which is the
     * correct behaviour here).
     *
     * @param list<string> $issuers
     */
    private function issuingAuthority(PkiAuthorityModel $model, string $directory, array $issuers, string $issuerCn): ?array
    {
        $serviceName = self::directoryLabel($directory);
        $existing    = $model->where('source', 'acme')->where('common_name', $serviceName)->first();

        $chain = implode("\n", $issuers);
        $info  = $chain !== '' ? X509::parseCertificate($chain) : null;

        $data = [
            'name'          => $serviceName,
            'common_name'   => $serviceName,
            'organization'  => 'Let\'s Encrypt',
            'country'       => '',
            'state'         => '',
            'city'          => '',
            'certificate'   => $chain,
            'private_key'   => '',
            'serial_number' => (string) ($info['serial_number'] ?? '0'),
            'valid_from'    => $info['valid_from'] ?? null,
            'valid_until'   => $info['valid_until'] ?? null,
            'key_length'    => (int) ($info['key_length'] ?? 0),
            'digest'        => (string) ($info['digest'] ?? 'sha256'),
            'is_default'    => 0,
            'source'        => 'acme',
        ];

        if (is_array($existing)) {
            $model->update((int) $existing['id'], $data);

            return $model->find((int) $existing['id']);
        }

        $id = (int) $model->insert($data);

        return $id > 0 ? $model->find($id) : null;
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    /** One order row, or null. */
    public function order(int $orderId): ?array
    {
        $row = $this->db->table('pki_acme_orders')->where('id', $orderId)->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * The orders the page shows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(int $limit = 50): array
    {
        return $this->db->table('pki_acme_orders o')
            ->select('o.*, c.name AS cert_name, c.valid_until')
            ->join('pki_certificates c', 'c.id = o.cert_id', 'left')
            ->orderBy('o.id', 'DESC')
            ->limit(max(1, $limit))
            ->get()
            ->getResultArray();
    }

    /**
     * Insert or update the single order row for a certificate.
     *
     * One row per certificate, reused on renewal: the row is the record of which
     * directory and challenge type a certificate was issued through, and renewal
     * depends on that surviving.
     *
     * @param array<string, mixed> $data
     */
    private function storeOrder(array $data): int
    {
        $now              = date('Y-m-d H:i:s');
        $data['updated_at'] = $now;

        $certId  = $data['cert_id'] === null ? null : (int) $data['cert_id'];
        $existing = $certId === null
            ? null
            : $this->db->table('pki_acme_orders')->where('cert_id', $certId)->get()->getRowArray();

        if (is_array($existing)) {
            $this->db->table('pki_acme_orders')->where('id', (int) $existing['id'])->update($data);

            return (int) $existing['id'];
        }

        $data['created_at'] = $now;
        $this->db->table('pki_acme_orders')->insert($data);

        return (int) $this->db->insertID();
    }

    /** Update an order's state without touching the challenge material. */
    private function noteOrder(int $orderId, string $status, ?string $reason, ?string $error, array $extra = []): void
    {
        $this->db->table('pki_acme_orders')->where('id', $orderId)->update($extra + [
            'status'     => $status,
            'reason'     => $reason,
            'last_error' => $error,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Record a failure that happened before an order row existed. */
    private function recordOrderFailure(?int $certId, string $directory, array $domains, string $challengeType, string $reason, string $message): void
    {
        if ($certId === null) {
            return;
        }

        $this->storeOrder([
            'cert_id'        => $certId,
            'directory'      => $directory,
            'domains'        => implode(',', $domains),
            'challenge_type' => $challengeType,
            'status'         => 'failed',
            'reason'         => $reason,
            'last_error'     => $message,
        ]);
    }

    /**
     * Mark an order failed and return the same failure to the caller.
     *
     * A terminal failure also withdraws the challenge answers: this order will
     * never be validated, so a file still answering its token is only a standing
     * credential. The one path that deliberately keeps them is "the CA has not
     * finished yet", where the operator is expected to try again.
     *
     * @param list<string> $tokens
     */
    private function failOrder(int $orderId, array $result, array $tokens = []): array
    {
        $this->noteOrder($orderId, 'failed', (string) ($result['reason'] ?? ''), (string) ($result['message'] ?? ''));
        $this->forgetChallenges($tokens);

        return $result + ['order_id' => $orderId, 'status' => 'failed'];
    }

    /**
     * Remove the challenge files an order owned.
     *
     * Deleted as soon as the order finishes: a key authorization left behind is a
     * standing answer to that challenge for as long as the file exists, and the
     * only thing that makes it harmless is an order nobody is waiting on.
     *
     * @param list<string> $tokens
     */
    private function forgetChallenges(array $tokens): void
    {
        foreach ($tokens as $token) {
            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $token) === 1) {
                @unlink($this->challengePath($token));
            }
        }
    }

    /** @return list<string> */
    private function challengeFiles(): array
    {
        $files = glob($this->challengeDirectory() . '/*');

        return is_array($files) ? $files : [];
    }

    /** Tokens currently served, for the page's "waiting on" display. */
    public function pendingChallengeCount(): int
    {
        return count($this->challengeFiles());
    }

    // ---------------------------------------------------------------------
    // Challenge storage
    // ---------------------------------------------------------------------

    private function challengeDirectory(): string
    {
        $dir = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . self::CHALLENGE_PATH;

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return $dir;
        }

        return $dir;
    }

    private function challengePath(string $token): string
    {
        return $this->challengeDirectory() . DIRECTORY_SEPARATOR . $token;
    }

    /** Write one HTTP-01 answer. The body is exactly `token.thumbprint`. */
    private function writeChallenge(string $token, string $body): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $token) !== 1) {
            return;
        }

        @file_put_contents($this->challengePath($token), $body);
    }

    // ---------------------------------------------------------------------
    // Validation & helpers
    // ---------------------------------------------------------------------

    /**
     * Normalise and validate the identifiers of an order.
     *
     * @param mixed  $input
     * @param string $challengeType as the operator typed it, so a wildcard is only
     *                               allowed where it can actually be validated
     * @param string|null $error    why the input was refused
     *
     * @return list<string>
     */
    public static function normaliseDomains($input, string $challengeType, ?string &$error = null): array
    {
        $error = null;

        $raw = is_array($input) ? $input : preg_split('/[\s,]+/', (string) $input);
        $raw = is_array($raw) ? $raw : [];

        $domains = [];
        foreach ($raw as $candidate) {
            $domain = strtolower(trim((string) $candidate, " \t\n\r\0\x0B."));
            if ($domain === '') {
                continue;
            }

            $wildcard = str_starts_with($domain, '*.');
            $host     = $wildcard ? substr($domain, 2) : $domain;

            if (! self::isHostname($host)) {
                $error = "'" . $domain . "' is not a DNS name a certificate can cover";

                return [];
            }

            if ($wildcard && $challengeType !== Acme::CHALLENGE_DNS) {
                $error = "'" . $domain . "' is a wildcard: it can only be validated by DNS-01";

                return [];
            }

            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $error = "'" . $domain . "' is an IP address; ACME issues for DNS names";

                return [];
            }

            $domains[$domain] = true;
        }

        if ($domains === []) {
            $error ??= 'at least one domain is required';

            return [];
        }

        if (count($domains) > self::MAX_DOMAINS) {
            $error = 'an order may cover at most ' . self::MAX_DOMAINS . ' names';

            return [];
        }

        return array_keys($domains);
    }

    /** True for a plausible DNS name (RFC 1123 labels, at least one dot). */
    private static function isHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The challenge type to use, or null when the names rule one out.
     *
     * @param list<string> $domains
     */
    private static function challengeType(string $requested, array $domains): ?string
    {
        $type = $requested === Acme::CHALLENGE_DNS ? Acme::CHALLENGE_DNS : Acme::CHALLENGE_HTTP;

        foreach ($domains as $domain) {
            if (str_starts_with($domain, '*.') && $type !== Acme::CHALLENGE_DNS) {
                return null;
            }
        }

        return $type;
    }

    /** @return list<string> */
    private static function splitDomains(string $stored): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $stored)), static fn (string $d): bool => $d !== ''));
    }

    /**
     * The certificates in a downloaded chain, leaf first.
     *
     * @return list<string>
     */
    private static function issuerChain(string $chain): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $chain, $matches);

        $blocks = array_values(array_filter(
            array_map('trim', $matches[0] ?? []),
            static fn (string $block): bool => $block !== '',
        ));

        // Skip the leaf: the authority carries the issuers.
        return array_slice($blocks, 1);
    }

    /** The X.509 `cert_type` to record, refusing anything the store rejects. */
    private static function certType(string $requested): string
    {
        return in_array($requested, self::CERT_TYPES, true) ? $requested : 'server';
    }

    /** Whole days until a timestamp; negative once it has passed. */
    public static function daysLeft(string $validUntil): int
    {
        $expiry = strtotime($validUntil);

        return $expiry === false ? -1 : (int) floor(($expiry - time()) / 86400);
    }

    /** The CA's own explanation for a rejected authorization. */
    private static function authorizationDetail(array $authorization): string
    {
        foreach ((array) ($authorization['challenges'] ?? []) as $challenge) {
            $detail = (string) ($challenge['error']['detail'] ?? '');
            if ($detail !== '') {
                return $detail;
            }
        }

        return 'the challenge was rejected with no detail from the CA';
    }

    /** What the operator has to do next, per challenge type. */
    private static function instructions(string $challengeType, array $challenges): string
    {
        if ($challengeType === Acme::CHALLENGE_DNS) {
            $lines = [];
            foreach ($challenges as $challenge) {
                $lines[] = '_acme-challenge.' . $challenge['domain'] . ' TXT "' . $challenge['value'] . '"';
            }

            return "Publish these DNS records, then check the order:\n" . implode("\n", $lines);
        }

        return 'The appliance is serving ' . count($challenges) . ' validation file(s) on port 80; the CA is being asked to check them now.';
    }

    /** @return array{ok: false, reason: string, message: string} */
    private static function failure(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }
}

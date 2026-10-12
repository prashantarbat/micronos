<?php

namespace App\Libraries;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Exception;

/**
 * SsoClient — Enterprise Single Sign-On engine supporting OpenID Connect (OIDC)
 * with PKCE and SAML 2.0 Web Browser SSO profile.
 */
class SsoClient
{
    protected BaseConnection $db;
    protected UserModel $userModel;

    public function __construct(?BaseConnection $db = null, ?UserModel $userModel = null)
    {
        $this->db = $db ?? Database::connect();
        $this->userModel = $userModel ?? new UserModel();
    }

    // =========================================================================
    // OpenID Connect (OIDC) Helpers
    // =========================================================================

    /**
     * Generate PKCE code_verifier and code_challenge (RFC 7636).
     *
     * @return array{verifier: string, challenge: string}
     */
    public function generatePkce(): array
    {
        $verifierBytes = random_bytes(32);
        $verifier = $this->base64UrlEncode($verifierBytes);
        $challengeHash = hash('sha256', $verifier, true);
        $challenge = $this->base64UrlEncode($challengeHash);

        return [
            'verifier'  => $verifier,
            'challenge' => $challenge,
        ];
    }

    /**
     * Base64URL encoding (RFC 4648).
     */
    public function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decoding.
     */
    public function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Build the OIDC authorization redirection URL.
     */
    public function buildAuthorizationUrl(
        array $provider,
        string $redirectUri,
        string $state,
        string $nonce,
        ?string $codeChallenge = null
    ): string {
        $endpoint = ! empty($provider['auth_url']) ? $provider['auth_url'] : '';
        if (empty($endpoint)) {
            throw new Exception("Provider {$provider['name']} has no authorization URL configured.");
        }

        $params = [
            'client_id'             => $provider['client_id'] ?? '',
            'response_type'         => 'code',
            'redirect_uri'          => $redirectUri,
            'response_mode'         => 'query',
            'scope'                 => ! empty($provider['scopes']) ? $provider['scopes'] : 'openid profile email',
            'state'                 => $state,
            'nonce'                 => $nonce,
        ];

        if (! empty($codeChallenge)) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        $query = http_build_query($params);
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint . $separator . $query;
    }

    /**
     * Fetch discovery document from .well-known/openid-configuration.
     */
    public function discoverOidcEndpoints(string $discoveryUrl): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header'  => "User-Agent: MicronOS-SSO/1.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $content = @file_get_contents($discoveryUrl, false, $context);
        if ($content === false) {
            throw new Exception("Unable to fetch OIDC discovery document from {$discoveryUrl}");
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new Exception("Invalid JSON returned from OIDC discovery document.");
        }

        return [
            'issuer'                 => $data['issuer'] ?? '',
            'authorization_endpoint' => $data['authorization_endpoint'] ?? '',
            'token_endpoint'         => $data['token_endpoint'] ?? '',
            'userinfo_endpoint'      => $data['userinfo_endpoint'] ?? '',
            'jwks_uri'               => $data['jwks_uri'] ?? '',
        ];
    }

    /**
     * Parse unverified claims from a JWT token (e.g. id_token).
     */
    public function parseJwtClaims(string $jwtToken): array
    {
        $parts = explode('.', $jwtToken);
        if (count($parts) < 2) {
            return [];
        }

        $payloadJson = $this->base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson, true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Exchange authorization code for tokens at the token endpoint.
     */
    public function exchangeCodeForTokens(
        array $provider,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null
    ): array {
        $tokenUrl = $provider['token_url'] ?? '';
        if (empty($tokenUrl)) {
            throw new Exception("Provider {$provider['name']} has no token URL configured.");
        }

        $postData = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $provider['client_id'] ?? '',
            'client_secret' => $provider['client_secret'] ?? '',
        ];

        if (! empty($codeVerifier)) {
            $postData['code_verifier'] = $codeVerifier;
        }

        $body = http_build_query($postData);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n" .
                             "Accept: application/json\r\n" .
                             "User-Agent: MicronOS-SSO/1.0\r\n",
                'content' => $body,
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($tokenUrl, false, $context);
        if ($response === false) {
            throw new Exception("Failed to connect to token endpoint at {$tokenUrl}");
        }

        $data = json_decode($response, true);
        if (! is_array($data) || isset($data['error'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'Unknown token exchange error';
            throw new Exception("Token exchange failed: {$err}");
        }

        return $data;
    }

    /**
     * Fetch user claims from UserInfo endpoint using Access Token.
     */
    public function fetchUserInfo(array $provider, string $accessToken): array
    {
        $userinfoUrl = $provider['userinfo_url'] ?? '';
        if (empty($userinfoUrl)) {
            return [];
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer {$accessToken}\r\n" .
                             "Accept: application/json\r\n" .
                             "User-Agent: MicronOS-SSO/1.0\r\n",
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($userinfoUrl, false, $context);
        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // SAML 2.0 Helpers
    // =========================================================================

    /**
     * Generate SAML 2.0 SP EntityDescriptor Metadata XML.
     */
    public function generateSpMetadataXml(array $provider, string $acsUrl): string
    {
        $spEntityId = ! empty($provider['sp_entity_id']) ? $provider['sp_entity_id'] : base_url('auth/sso/metadata/' . $provider['slug']);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . htmlspecialchars($spEntityId, ENT_XML1) . '">' . "\n" .
            '  <md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">' . "\n" .
            '    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>' . "\n" .
            '    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>' . "\n" .
            '    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>' . "\n" .
            '    <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . htmlspecialchars($acsUrl, ENT_XML1) . '" index="1" isDefault="true"/>' . "\n" .
            '  </md:SPSSODescriptor>' . "\n" .
            '  <md:Organization>' . "\n" .
            '    <md:OrganizationName xml:lang="en">Micron OS Appliance</md:OrganizationName>' . "\n" .
            '    <md:OrganizationDisplayName xml:lang="en">Micron OS Gateway</md:OrganizationDisplayName>' . "\n" .
            '    <md:OrganizationURL xml:lang="en">' . htmlspecialchars(base_url(), ENT_XML1) . '</md:OrganizationURL>' . "\n" .
            '  </md:Organization>' . "\n" .
            '</md:EntityDescriptor>';
    }

    /**
     * Build SAML 2.0 AuthnRequest redirect URL (HTTP-Redirect binding).
     */
    public function buildSamlAuthnRequestUrl(array $provider, string $acsUrl, string $relayState): string
    {
        $idpSsoUrl = $provider['idp_sso_url'] ?? '';
        if (empty($idpSsoUrl)) {
            throw new Exception("Provider {$provider['name']} has no IdP SSO URL configured.");
        }

        $spEntityId = ! empty($provider['sp_entity_id']) ? $provider['sp_entity_id'] : base_url('auth/sso/metadata/' . $provider['slug']);
        $id = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');

        $authnRequestXml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ' .
            'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ' .
            'ID="' . $id . '" ' .
            'Version="2.0" ' .
            'IssueInstant="' . $issueInstant . '" ' .
            'Destination="' . htmlspecialchars($idpSsoUrl, ENT_XML1) . '" ' .
            'AssertionConsumerServiceURL="' . htmlspecialchars($acsUrl, ENT_XML1) . '" ' .
            'ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">' .
            '<saml:Issuer>' . htmlspecialchars($spEntityId, ENT_XML1) . '</saml:Issuer>' .
            '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>' .
            '</samlp:AuthnRequest>';

        $deflated = gzdeflate($authnRequestXml);
        $encoded = base64_encode($deflated);

        $params = [
            'SAMLRequest' => $encoded,
            'RelayState'  => $relayState,
        ];

        $separator = str_contains($idpSsoUrl, '?') ? '&' : '?';
        return $idpSsoUrl . $separator . http_build_query($params);
    }

    /**
     * Parse SAMLResponse XML from Base64 POST body.
     *
     * @return array{
     *   name_id: string,
     *   session_index: string,
     *   attributes: array<string, list<string>>,
     *   issuer: string
     * }
     */
    public function parseSamlResponse(string $samlResponseBase64, ?string $expectedIdpCert = null): array
    {
        $xmlContent = base64_decode($samlResponseBase64);
        if (! $xmlContent) {
            throw new Exception("Failed to base64 decode SAMLResponse.");
        }

        // Disable external entities for XXE prevention
        if (PHP_VERSION_ID < 80000) {
            libxml_disable_entity_loader(true);
        }
        $doc = new \DOMDocument();
        $doc->loadXML($xmlContent, LIBXML_NONET);
        if (PHP_VERSION_ID < 80000) {
            libxml_disable_entity_loader(false);
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        // Check Status Code
        $statusNodes = $xpath->query('//samlp:StatusCode/@Value');
        if ($statusNodes && $statusNodes->length > 0) {
            $status = $statusNodes->item(0)->nodeValue;
            if (! str_ends_with($status, ':Success')) {
                throw new Exception("SAML IdP returned error status: {$status}");
            }
        }

        // Issuer
        $issuerNodes = $xpath->query('//saml:Issuer');
        $issuer = ($issuerNodes && $issuerNodes->length > 0) ? trim($issuerNodes->item(0)->nodeValue) : '';

        // NameID
        $nameIdNodes = $xpath->query('//saml:Subject/saml:NameID');
        $nameId = ($nameIdNodes && $nameIdNodes->length > 0) ? trim($nameIdNodes->item(0)->nodeValue) : '';

        // SessionIndex
        $sessionIndexNodes = $xpath->query('//saml:AuthnStatement/@SessionIndex');
        $sessionIndex = ($sessionIndexNodes && $sessionIndexNodes->length > 0) ? $sessionIndexNodes->item(0)->nodeValue : '';

        // Attributes
        $attributes = [];
        $attributeNodes = $xpath->query('//saml:AttributeStatement/saml:Attribute');
        if ($attributeNodes) {
            foreach ($attributeNodes as $attrNode) {
                /** @var \DOMElement $attrNode */
                $attrName = $attrNode->getAttribute('Name');
                $friendlyName = $attrNode->getAttribute('FriendlyName');
                $key = ! empty($friendlyName) ? $friendlyName : $attrName;

                $values = [];
                $valueNodes = $xpath->query('saml:AttributeValue', $attrNode);
                if ($valueNodes) {
                    foreach ($valueNodes as $vNode) {
                        $values[] = trim($vNode->nodeValue);
                    }
                }

                $attributes[$key] = $values;
                if (! empty($attrName) && $attrName !== $key) {
                    $attributes[$attrName] = $values;
                }
            }
        }

        // Check Conditions (NotBefore / NotOnOrAfter)
        $conditionsNodes = $xpath->query('//saml:Conditions');
        if ($conditionsNodes && $conditionsNodes->length > 0) {
            /** @var \DOMElement $cond */
            $cond = $conditionsNodes->item(0);
            $notBefore = $cond->getAttribute('NotBefore');
            $notOnOrAfter = $cond->getAttribute('NotOnOrAfter');
            $skew = 120; // 2 minutes clock skew tolerance
            $now = time();

            if (! empty($notBefore) && strtotime($notBefore) > ($now + $skew)) {
                throw new Exception("SAML Assertion is not yet valid (NotBefore: {$notBefore}).");
            }
            if (! empty($notOnOrAfter) && strtotime($notOnOrAfter) < ($now - $skew)) {
                throw new Exception("SAML Assertion has expired (NotOnOrAfter: {$notOnOrAfter}).");
            }
        }

        if (empty($nameId)) {
            // Check if email attribute exists as fallback
            $emailAttr = $attributes['email'][0] ?? $attributes['mail'][0] ?? $attributes['User.email'][0] ?? null;
            if ($emailAttr) {
                $nameId = $emailAttr;
            } else {
                throw new Exception("No NameID or Email found in SAML Assertion.");
            }
        }

        return [
            'name_id'       => $nameId,
            'session_index' => $sessionIndex,
            'attributes'    => $attributes,
            'issuer'        => $issuer,
        ];
    }

    // =========================================================================
    // User Resolution, Role Mapping & JIT Provisioning
    // =========================================================================

    /**
     * Map arbitrary IdP claims/attributes into a normalized user profile and role.
     *
     * @return array{
     *   sub: string,
     *   username: string,
     *   email: string,
     *   full_name: string,
     *   role: string,
     *   groups: list<string>
     * }
     */
    /**
     * Safely extract a single scalar string from candidate claim keys.
     */
    protected function extractClaimString(array $claims, array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            if (isset($claims[$k])) {
                $val = $claims[$k];
                if (is_array($val)) {
                    $val = $val[0] ?? '';
                }
                $str = trim((string) $val);
                if ($str !== '') {
                    return $str;
                }
            }
        }
        return $default;
    }

    public function resolveProfileAndRole(array $provider, array $claims): array
    {
        // 1. Identify Subject
        $sub = $this->extractClaimString($claims, ['sub', 'name_id', 'oid', 'id']);

        // 2. Identify Email
        $email = $this->extractClaimString($claims, ['email', 'mail', 'upn', 'userPrincipalName']);
        if (empty($email) && filter_var($sub, FILTER_VALIDATE_EMAIL)) {
            $email = $sub;
        }

        // 3. Identify Username
        $username = $this->extractClaimString($claims, ['preferred_username', 'username']);
        if (empty($username) && ! empty($email)) {
            $username = explode('@', $email)[0];
        }
        if (empty($username)) {
            $username = 'sso_' . substr(md5($sub ?: bin2hex(random_bytes(4))), 0, 8);
        }
        // Sanitize username
        $username = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $username);

        // 4. Identify Full Name
        $fullName = $this->extractClaimString($claims, ['name', 'displayName']);
        if (empty($fullName)) {
            $given = $this->extractClaimString($claims, ['given_name', 'givenName']);
            $family = $this->extractClaimString($claims, ['family_name', 'surname']);
            $fullName = trim($given . ' ' . $family);
        }
        if (empty($fullName)) {
            $fullName = $username;
        }

        // 5. Extract groups
        $groups = [];
        $groupCandidateKeys = ['groups', 'roles', 'memberOf', 'Role', 'Group'];
        foreach ($groupCandidateKeys as $k) {
            if (isset($claims[$k])) {
                $gVal = $claims[$k];
                if (is_array($gVal)) {
                    foreach ($gVal as $gv) {
                        $groups[] = (string) $gv;
                    }
                } else {
                    $groups[] = (string) $gVal;
                }
            }
        }
        $groups = array_unique($groups);

        // 6. Map Role
        $role = $provider['default_role'] ?? 'Operator';
        $roleMapping = [];
        if (! empty($provider['role_mapping_json'])) {
            $decoded = json_decode($provider['role_mapping_json'], true);
            if (is_array($decoded)) {
                $roleMapping = $decoded;
            }
        }

        // Check if any group matches role mapping (case-insensitive)
        foreach ($groups as $group) {
            foreach ($roleMapping as $idpGroup => $targetRole) {
                if (strcasecmp(trim($group), trim($idpGroup)) === 0) {
                    $role = $targetRole;
                    break 2;
                }
            }
        }

        return [
            'sub'       => $sub,
            'username'  => $username,
            'email'     => $email,
            'full_name' => $fullName,
            'role'      => $role,
            'groups'    => array_values($groups),
        ];
    }

    /**
     * Find existing user or JIT provision a new user.
     */
    public function provisionOrUpdateUser(array $provider, array $profile): array
    {
        $authSource = 'sso:' . $provider['slug'];
        $now = date('Y-m-d H:i:s');

        // 1. Try to find user by sso_sub & auth_source
        $user = null;
        if (! empty($profile['sub'])) {
            $user = $this->userModel
                ->where('sso_sub', $profile['sub'])
                ->where('auth_source', $authSource)
                ->first();
        }

        // 2. Try email match
        if (! $user && ! empty($profile['email'])) {
            $user = $this->userModel
                ->where('LOWER(email)', strtolower($profile['email']))
                ->first();
        }

        // 3. Try username match
        if (! $user && ! empty($profile['username'])) {
            $user = $this->userModel
                ->where('LOWER(username)', strtolower($profile['username']))
                ->first();
        }

        if ($user) {
            // Existing user — update login metadata and links
            $updates = [
                'last_login_at' => $now,
                'failed_logins' => 0,
                'locked_until'  => null,
            ];
            if (empty($user['sso_sub']) && ! empty($profile['sub'])) {
                $updates['sso_sub'] = $profile['sub'];
                $updates['auth_source'] = $authSource;
            }
            if (! empty($profile['full_name']) && empty($user['full_name'])) {
                $updates['full_name'] = $profile['full_name'];
            }
            if (! empty($profile['email']) && empty($user['email'])) {
                $updates['email'] = $profile['email'];
            }

            // Sync role if configured and provider role mapping exists
            if (! empty($profile['role']) && ! empty($provider['role_mapping_json'])) {
                $updates['role'] = $profile['role'];
            }

            $this->userModel->update($user['id'], $updates);
            $this->syncUserRoleTable($user['id'], $updates['role'] ?? $user['role']);

            return array_merge($user, $updates);
        }

        // Check if auto-provisioning is enabled
        if (empty($provider['auto_provision'])) {
            throw new Exception("User '{$profile['username']}' is not registered on this appliance and JIT provisioning is disabled.");
        }

        // Create new user
        $newUserId = 'usr_' . bin2hex(random_bytes(8));

        // Ensure username uniqueness
        $baseUsername = $profile['username'];
        $candidateUsername = $baseUsername;
        $counter = 1;
        while ($this->userModel->where('LOWER(username)', strtolower($candidateUsername))->countAllResults() > 0) {
            $candidateUsername = $baseUsername . $counter;
            $counter++;
        }

        $newUser = [
            'id'            => $newUserId,
            'username'      => $candidateUsername,
            'password_hash' => '!SSO_MANAGED_ACCOUNT!',
            'email'         => $profile['email'] ?? null,
            'full_name'     => $profile['full_name'] ?? $candidateUsername,
            'role'          => $profile['role'] ?? ($provider['default_role'] ?? 'Operator'),
            'totp_enabled'  => 0,
            'auth_source'   => $authSource,
            'sso_sub'       => $profile['sub'],
            'failed_logins' => 0,
            'last_login_at' => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $this->userModel->insert($newUser);
        $this->syncUserRoleTable($newUserId, $newUser['role']);

        return $newUser;
    }

    /**
     * Keep user_roles table in sync with user's role name.
     */
    protected function syncUserRoleTable(string $userId, string $roleName): void
    {
        $rTable = $this->db->prefixTable('roles');
        $urTable = $this->db->prefixTable('user_roles');

        $roleRow = $this->db->query("SELECT id FROM {$rTable} WHERE LOWER(name) = ? OR LOWER(display_name) = ? LIMIT 1", [
            strtolower($roleName),
            strtolower($roleName),
        ])->getRowArray();

        if ($roleRow) {
            $roleId = $roleRow['id'];
            $existing = $this->db->query("SELECT id FROM {$urTable} WHERE user_id = ? AND role_id = ?", [
                $userId,
                $roleId,
            ])->getRowArray();

            if (! $existing) {
                // Remove other roles and assign primary
                $this->db->query("DELETE FROM {$urTable} WHERE user_id = ?", [$userId]);
                $this->db->query("INSERT INTO {$urTable} (user_id, role_id, assigned_at) VALUES (?, ?, ?)", [
                    $userId,
                    $roleId,
                    date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}

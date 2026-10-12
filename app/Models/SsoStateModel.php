<?php

namespace App\Models;

use CodeIgniter\Model;

class SsoStateModel extends Model
{
    protected $table            = 'sso_states';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'state_token',
        'provider_id',
        'nonce',
        'code_verifier',
        'relay_state',
        'expires_at',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Create and persist a new CSRF state & nonce record.
     */
    public function createState(string $providerId, ?string $codeVerifier = null, ?string $relayState = null): array
    {
        $this->purgeExpired();

        $id = 'st_' . bin2hex(random_bytes(8));
        $stateToken = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        $data = [
            'id'            => $id,
            'state_token'   => $stateToken,
            'provider_id'   => $providerId,
            'nonce'         => $nonce,
            'code_verifier' => $codeVerifier,
            'relay_state'   => $relayState,
            'expires_at'    => $expiresAt,
            'created_at'    => $now,
        ];

        $this->insert($data);

        return $data;
    }

    /**
     * Validate and consume (single-use) a state token.
     */
    public function consumeState(string $stateToken): ?array
    {
        $now = date('Y-m-d H:i:s');
        $record = $this->where('state_token', $stateToken)
                       ->where('expires_at >', $now)
                       ->first();

        if (! $record) {
            return null;
        }

        // Single-use token: consume immediately
        $this->delete($record['id']);

        return $record;
    }

    /**
     * Remove stale expired state records.
     */
    public function purgeExpired(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->where('expires_at <=', $now)->delete();
    }
}

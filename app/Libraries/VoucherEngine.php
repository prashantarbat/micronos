<?php

namespace App\Libraries;

use App\Models\CaptiveSessionModel;
use App\Models\CaptiveVoucherModel;
use App\Models\CaptiveZoneModel;
use Exception;

/**
 * VoucherEngine — High-performance voucher token generation, validation,
 * quota tracking, and printable ticket layout rendering.
 */
class VoucherEngine
{
    protected CaptiveVoucherModel $voucherModel;
    protected CaptiveSessionModel $sessionModel;
    protected CaptiveZoneModel $zoneModel;

    // Unambiguous uppercase alphanumeric character set (omits 0, O, 1, I, L)
    protected const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        ?CaptiveVoucherModel $voucherModel = null,
        ?CaptiveSessionModel $sessionModel = null,
        ?CaptiveZoneModel $zoneModel = null
    ) {
        $this->voucherModel = $voucherModel ?? new CaptiveVoucherModel();
        $this->sessionModel = $sessionModel ?? new CaptiveSessionModel();
        $this->zoneModel    = $zoneModel ?? new CaptiveZoneModel();
    }

    /**
     * Batch generates a new roll of unique hotspot vouchers.
     *
     * @param string      $zoneId             Target captive zone
     * @param int         $count              Number of vouchers to create (1-1000)
     * @param int         $durationMinutes    Session lifetime once activated
     * @param int         $quotaBytes         Total allowed byte transfer (0 = unlimited)
     * @param int         $bandwidthDownKbps  Download bandwidth cap (0 = unlimited)
     * @param int         $bandwidthUpKbps    Upload bandwidth cap (0 = unlimited)
     * @param int         $maxDevices         Concurrent devices per voucher
     * @param string|null $customRollPrefix   Optional custom roll prefix
     * @return array{roll_id: string, count: int, sample_code: string}
     */
    public function generateRoll(
        string $zoneId,
        int $count = 10,
        int $durationMinutes = 60,
        int $quotaBytes = 0,
        int $bandwidthDownKbps = 0,
        int $bandwidthUpKbps = 0,
        int $maxDevices = 1,
        ?string $customRollPrefix = null
    ): array {
        $count = max(1, min(1000, $count));
        $prefix = $customRollPrefix ? preg_replace('/[^A-Z0-9]/', '', strtoupper($customRollPrefix)) : 'ROLL';
        $rollId = sprintf('%s-%s-%s', $prefix, date('ymd'), strtoupper(bin2hex(random_bytes(2))));

        $now     = date('Y-m-d H:i:s');
        $batch   = [];
        $sample  = '';

        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCode();
            if ($i === 0) {
                $sample = $code;
            }

            $batch[] = [
                'zone_id'             => $zoneId,
                'roll_id'             => $rollId,
                'code'                => $code,
                'duration_minutes'    => $durationMinutes,
                'quota_bytes'         => $quotaBytes,
                'bandwidth_down_kbps' => $bandwidthDownKbps,
                'bandwidth_up_kbps'   => $bandwidthUpKbps,
                'max_devices'         => $maxDevices,
                'used_count'          => 0,
                'status'              => 'active',
                'first_used_at'       => null,
                'expires_at'          => null,
                'created_at'          => $now,
            ];
        }

        // Bulk insert batch
        $this->voucherModel->insertBatch($batch);

        return [
            'roll_id'     => $rollId,
            'count'       => $count,
            'sample_code' => $sample,
        ];
    }

    /**
     * Validates a voucher code and provisions an active session for the guest device.
     *
     * @param string $code       Voucher code entered by guest
     * @param string $ipAddress  Client IPv4 address
     * @param string $macAddress Client MAC address
     * @param string $zoneId     Current captive zone ID
     * @return array{success: bool, message: string, session?: array, voucher?: array}
     */
    public function validateAndActivate(
        string $code,
        string $ipAddress,
        string $macAddress,
        string $zoneId
    ): array {
        $cleanCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
        $voucher   = $this->voucherModel->where('zone_id', $zoneId)
            ->where('code', $cleanCode)
            ->first();

        if (!$voucher) {
            return [
                'success' => false,
                'message' => 'Invalid voucher code. Please check and try again.',
            ];
        }

        if (in_array($voucher['status'], ['revoked', 'depleted', 'expired'], true)) {
            return [
                'success' => false,
                'message' => "This voucher is {$voucher['status']} and can no longer be used.",
            ];
        }

        $nowTs = time();

        // Check if voucher already has an expiration deadline
        if (!empty($voucher['expires_at']) && strtotime($voucher['expires_at']) <= $nowTs) {
            $this->voucherModel->update($voucher['id'], ['status' => 'expired']);
            return [
                'success' => false,
                'message' => 'This voucher has expired.',
            ];
        }

        // Check concurrent device limit
        $activeSessions = $this->sessionModel->where('username', $cleanCode)
            ->where('status', 'authorized')
            ->countAllResults();

        if ($activeSessions >= (int) $voucher['max_devices']) {
            return [
                'success' => false,
                'message' => "Maximum device limit reached for this voucher ({$voucher['max_devices']} device limit).",
            ];
        }

        // Compute session expiration
        $durationSeconds = ((int) $voucher['duration_minutes']) * 60;
        $expiresAt       = date('Y-m-d H:i:s', $nowTs + $durationSeconds);

        // If first activation, record voucher first_used_at and hard expires_at
        if (empty($voucher['first_used_at'])) {
            $this->voucherModel->update($voucher['id'], [
                'first_used_at' => date('Y-m-d H:i:s', $nowTs),
                'expires_at'    => $expiresAt,
                'used_count'    => (int) $voucher['used_count'] + 1,
                'status'        => 'used',
            ]);
        } else {
            $this->voucherModel->update($voucher['id'], [
                'used_count' => (int) $voucher['used_count'] + 1,
            ]);
        }

        // Create authorized session
        $sessionId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $sessionData = [
            'id'           => $sessionId,
            'zone_id'      => $zoneId,
            'ip_address'   => $ipAddress,
            'mac_address'  => strtoupper($macAddress),
            'auth_type'    => 'voucher',
            'username'     => $cleanCode,
            'bytes_in'     => 0,
            'bytes_out'    => 0,
            'connected_at' => date('Y-m-d H:i:s', $nowTs),
            'expires_at'   => $expiresAt,
            'last_seen_at' => date('Y-m-d H:i:s', $nowTs),
            'status'       => 'authorized',
        ];

        $this->sessionModel->insert($sessionData);

        return [
            'success' => true,
            'message' => 'Voucher verified successfully. Internet access authorized.',
            'session' => $sessionData,
            'voucher' => $voucher,
        ];
    }

    /**
     * Generates a single cryptographically random voucher code in block format (e.g. ABCD-EFGH).
     */
    protected function generateUniqueCode(int $length = 8): string
    {
        $charsLen = strlen(self::CHARSET);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $idx = ord(random_bytes(1)) % $charsLen;
            $code .= self::CHARSET[$idx];
        }

        // Format as 4-character blocks (e.g. WX9K-87TY)
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }
}

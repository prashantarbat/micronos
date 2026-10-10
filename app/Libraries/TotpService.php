<?php

namespace App\Libraries;

/**
 * MicronOS RFC 6238 Time-Based One-Time Password (TOTP) Service
 * ==============================================================
 * Provides cryptographic 2FA secret generation, time-slice validation with drift
 * protection, and URI formatting for Google Authenticator / Microsoft Authenticator.
 */
class TotpService
{
    protected static string $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generates a random Base32 encoded 160-bit secret key.
     */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Verifies a 6-digit TOTP code against the secret with ±1 time step tolerance (90s window).
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $currentTime = null): bool
    {
        $currentTime = $currentTime ?? time();
        $timeSlice = (int) floor($currentTime / 30);
        $code = trim($code);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->calculateCode($secret, $timeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates the current 6-digit TOTP code for a given secret.
     */
    public function calculateCode(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = (int) floor(time() / 30);
        }

        $secretKey = $this->base32Decode($secret);

        // Pack time slice into 8-byte big-endian binary string
        $time = pack('N*', 0) . pack('N*', $timeSlice);

        // Calculate HMAC-SHA1
        $hmac = hash_hmac('sha1', $time, $secretKey, true);

        // Dynamic truncation (RFC 4226)
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashPart = substr($hmac, $offset, 4);

        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;
        $modulo = $value % 1000000;

        return str_pad((string) $modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generates an otpauth:// URI for authenticator apps.
     */
    public function getOtpAuthUrl(string $issuer, string $accountName, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );
    }

    /**
     * Decodes a Base32 encoded string into raw binary.
     */
    protected function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $l = strlen($b32);
        $n = 0;
        $j = 0;
        $binary = '';

        for ($i = 0; $i < $l; $i++) {
            $char = $b32[$i];
            $idx = strpos(self::$base32Chars, $char);
            if ($idx === false) {
                continue;
            }
            $n = ($n << 5) | $idx;
            $j += 5;

            if ($j >= 8) {
                $j -= 8;
                $binary .= chr(($n >> $j) & 0xFF);
            }
        }

        return $binary;
    }
}

<?php

namespace Tests\Unit;

use App\Libraries\WireguardCompiler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WireguardKeygenTest extends CIUnitTestCase
{
    public function testGenerateKeypairReturnsValidBase64Pair(): void
    {
        $pair = WireguardCompiler::generateKeypair();

        $this->assertArrayHasKey('private_key', $pair);
        $this->assertArrayHasKey('public_key', $pair);

        $priv = base64_decode($pair['private_key'], true);
        $pub  = base64_decode($pair['public_key'], true);

        $this->assertNotFalse($priv, 'Private key must be valid base64');
        $this->assertNotFalse($pub, 'Public key must be valid base64');
        $this->assertSame(32, strlen($priv), 'Private key raw bytes must be 32 bytes');
        $this->assertSame(32, strlen($pub), 'Public key raw bytes must be 32 bytes');
    }

    public function testPublicKeyFromPrivateKeyMatches(): void
    {
        $pair = WireguardCompiler::generateKeypair();
        $derivedPub = WireguardCompiler::publicKeyFromPrivateKey($pair['private_key']);

        $this->assertSame($pair['public_key'], $derivedPub, 'Derived public key must match generated public key');
    }

    public function testGeneratePresharedKeyReturns32Bytes(): void
    {
        $psk = WireguardCompiler::generatePresharedKey();
        $raw = base64_decode($psk, true);

        $this->assertNotFalse($raw, 'PSK must be valid base64');
        $this->assertSame(32, strlen($raw), 'PSK must be 32 bytes');
    }
}

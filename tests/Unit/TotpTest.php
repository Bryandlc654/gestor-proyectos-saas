<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testKnownRfcVector(): void
    {
        // RFC 6238, secreto "12345678901234567890" en Base32
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertTrue(Totp::verify($secret, '287082', 0, 59));
        $this->assertFalse(Totp::verify($secret, '000000', 0, 59));
    }

    public function testSecretAndRecoveryCodes(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $codes = Totp::generateRecoveryCodes(3);
        $this->assertCount(3, $codes);
        $hashes = json_encode(Totp::hashRecoveryCodes($codes));

        $remaining = Totp::consumeRecoveryCode($hashes, $codes[0]);
        $this->assertNotNull($remaining);
        $this->assertNull(Totp::consumeRecoveryCode($remaining, $codes[0]), 'No se puede reutilizar');
        $this->assertNotNull(Totp::consumeRecoveryCode($remaining, $codes[1]));
    }
}

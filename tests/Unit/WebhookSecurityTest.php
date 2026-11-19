<?php

namespace Tests\Unit;

use App\Service\ApiTokenService;
use App\Service\WebhookService;
use App\Support\WebhookUrl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WebhookSecurityTest extends TestCase
{
    public function testSigningSecretIsDeterministicAndNotTheTokenHash(): void
    {
        $hash = hash('sha256', 'vrt_example');
        $secret = ApiTokenService::webhookSecret($hash);

        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, ApiTokenService::webhookSecret($hash));
        $this->assertNotSame($hash, $secret);
    }

    public function testSignatureUsesTimestampAndExactBody(): void
    {
        $secret = 'secret-value';
        $timestamp = '1794326400';
        $payload = '{"event":"ticket.updated"}';

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $this->assertSame($expected, WebhookService::signature($secret, $timestamp, $payload));
        $this->assertNotSame(
            WebhookService::signature($secret, $timestamp, $payload),
            WebhookService::signature($secret, '1794326401', $payload)
        );
    }

    public function testPrivateAndLocalWebhookUrlsAreRejected(): void
    {
        foreach ([
            'http://example.com/hook',
            'http://127.0.0.1/hook',
            'http://localhost/hook',
            'http://10.0.0.1/hook',
            'http://192.168.1.10/hook',
            'http://169.254.169.254/latest/meta-data',
            'http://metadata.google.internal/hook',
            'ftp://example.com/hook',
            'https://user:pass@example.com/hook',
        ] as $url) {
            $this->assertFalse(WebhookUrl::isPublic($url), $url);
        }
    }

    public function testUnsafeSchemeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookUrl::normalize('javascript:alert(1)');
    }
}

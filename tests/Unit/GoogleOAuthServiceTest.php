<?php

namespace Tests\Unit;

use App\Service\GoogleOAuthService;
use PHPUnit\Framework\TestCase;

final class GoogleOAuthServiceTest extends TestCase
{
    private function service(): GoogleOAuthService
    {
        return new GoogleOAuthService(
            'client-id.apps.googleusercontent.com',
            'client-secret',
            'https://verticeplatform.com/google-callback'
        );
    }

    public function testConfigurationRequiresAllCredentials(): void
    {
        $this->assertTrue($this->service()->isConfigured());
        $this->assertFalse((new GoogleOAuthService('', 'secret', 'https://example.com/cb'))->isConfigured());
        $this->assertFalse((new GoogleOAuthService('id', '', 'https://example.com/cb'))->isConfigured());
        $this->assertFalse((new GoogleOAuthService('id', 'secret', ''))->isConfigured());
    }

    public function testAuthorizationUrlContainsRequiredParameters(): void
    {
        $url = $this->service()->authorizationUrl('state-token-123', 'persona@example.com');
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('https://verticeplatform.com/google-callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('state-token-123', $query['state']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('persona@example.com', $query['login_hint']);
    }

    public function testEmptyIdTokenIsRejectedBeforeAnyNetworkCall(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->validateIdToken('');
    }

    public function testEmptyAccessTokenIsRejectedBeforeAnyNetworkCall(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->fetchUserInfo('');
    }
}

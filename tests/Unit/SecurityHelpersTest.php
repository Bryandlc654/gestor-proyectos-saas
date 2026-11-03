<?php

namespace Tests\Unit;

use App\Security\Csrf;
use App\Security\UrlValidator;
use App\Support\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public static function urls(): array
    {
        return [
            'https'                 => ['https://figma.com/file/abc', true],
            'http'                  => ['http://example.com', true],
            'javascript'            => ['javascript:alert(1)', false],
            'javascript disfrazado' => ['javascript://comentario%0Aalert(1)', false],
            'data'                  => ['data:text/html,<script>alert(1)</script>', false],
            'vbscript'              => ['vbscript:msgbox(1)', false],
            'ftp'                   => ['ftp://example.com/file', false],
            'sin esquema'           => ['example.com', false],
            'vacía'                 => ['', false],
        ];
    }

    #[DataProvider('urls')]
    public function testUrlValidator(string $url, bool $expected): void
    {
        $this->assertSame($expected, UrlValidator::isSafe($url));
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $token = Csrf::token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, Csrf::token());
    }

    public function testCsrfVerify(): void
    {
        $token = Csrf::token();
        $this->assertTrue(Csrf::verify($token));
        $this->assertFalse(Csrf::verify(null));
        $this->assertFalse(Csrf::verify(''));
        $this->assertFalse(Csrf::verify(str_repeat('0', 64)));
    }

    public function testCsrfFailsWithoutSessionToken(): void
    {
        $this->assertFalse(Csrf::verify('cualquier-cosa'));
    }

    public function testHtmlEscapesQuotesAndTags(): void
    {
        $this->assertSame('&lt;b&gt; &quot;x&quot; &#039;y&#039; &amp;', Html::e('<b> "x" \'y\' &'));
        $this->assertSame('', Html::e(null));
    }
}

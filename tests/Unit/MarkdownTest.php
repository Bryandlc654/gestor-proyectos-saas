<?php

namespace Tests\Unit;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function testRendersBasicBlocksAndInlineFormatting(): void
    {
        $html = Markdown::render("# Título\n\nTexto con **negrita** y *cursiva* y `código`.\n\n- Uno\n- Dos");

        $this->assertStringContainsString('<h1>Título</h1>', $html);
        $this->assertStringContainsString('<strong>negrita</strong>', $html);
        $this->assertStringContainsString('<em>cursiva</em>', $html);
        $this->assertStringContainsString('<code>código</code>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>Uno</li>', $html);
    }

    public function testEscapesRawHtml(): void
    {
        $html = Markdown::render('<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAllowsSafeLinksAndRejectsJavascriptLinks(): void
    {
        $this->assertStringContainsString('href="https://example.com"', Markdown::render('[Web](https://example.com)'));
        $this->assertStringNotContainsString('href="javascript:', Markdown::render('[Malo](javascript:alert(1))'));
    }

    public function testRendersCodeBlocksWithoutInterpretingHtml(): void
    {
        $html = Markdown::render("```\n<b>no html</b>\n```");

        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('&lt;b&gt;no html&lt;/b&gt;', $html);
    }
}

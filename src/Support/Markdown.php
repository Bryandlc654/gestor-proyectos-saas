<?php

namespace App\Support;

/**
 * Renderizador Markdown mínimo y seguro (sin dependencias externas).
 * Escapa todo el HTML antes de aplicar un subconjunto de sintaxis:
 * encabezados, listas, citas, bloques de código, negrita, cursiva, código y enlaces http/https/mailto.
 */
final class Markdown
{
    public static function render(?string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$markdown);
        $lines = explode("\n", $text);
        $html = [];
        $paragraph = [];
        $list = null; // ul | ol
        $code = null;

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . self::inline(implode("\n", $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$list, &$html): void {
            if ($list !== null) {
                $html[] = '</' . $list . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            if ($code !== null) {
                if (preg_match('/^```/', trim($line))) {
                    $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $code = null;
                } else {
                    $code[] = $line;
                }
                continue;
            }

            if (preg_match('/^```/', trim($line))) {
                $flushParagraph();
                $closeList();
                $code = [];
                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches)) {
                $flushParagraph();
                $closeList();
                $level = strlen($matches[1]);
                $html[] = '<h' . $level . '>' . self::inline($matches[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $matches)) {
                $flushParagraph();
                $closeList();
                $html[] = '<blockquote>' . self::inline($matches[1]) . '</blockquote>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $line, $matches)) {
                $flushParagraph();
                if ($list !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $list = 'ul';
                }
                $html[] = '<li>' . self::inline($matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.*)$/', $line, $matches)) {
                $flushParagraph();
                if ($list !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $list = 'ol';
                }
                $html[] = '<li>' . self::inline($matches[1]) . '</li>';
                continue;
            }

            $closeList();
            $paragraph[] = $line;
        }

        if ($code !== null) {
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }
        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $escaped = preg_replace_callback('/`([^`]+)`/', static fn(array $m): string => '<code>' . $m[1] . '</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $escaped) ?? $escaped;

        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|mailto:)#i', $url)) {
                return $m[0];
            }
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
        }, $escaped) ?? $escaped;

        return nl2br($escaped);
    }
}

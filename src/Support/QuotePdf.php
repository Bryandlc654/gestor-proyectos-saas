<?php

namespace App\Support;

/**
 * Generador PDF para cotizaciones (sin dependencias externas).
 * Incluye cabecera con logo de la agencia, tabla de ítems, totales, notas y pie de página.
 */
final class QuotePdf
{
    private const PAGE_W = 595.0;
    private const PAGE_H = 842.0;
    private const MARGIN = 40.0;

    private const NAVY = [0.043, 0.145, 0.271];
    private const BLUE = [0.055, 0.400, 1.0];
    private const CYAN = [0.082, 0.741, 0.957];
    private const TEXT = [0.200, 0.255, 0.333];
    private const MUTED = [0.392, 0.455, 0.545];
    private const BORDER = [0.886, 0.910, 0.941];
    private const LIGHT = [0.973, 0.980, 0.988];
    private const WHITE = [1.0, 1.0, 1.0];

    /**
     * @param array<string, mixed> $quote
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $agency
     */
    public static function render(array $quote, array $items, array $agency): string
    {
        $logo = self::prepareLogo((string)($agency['logo_path'] ?? ''));
        $currency = strtoupper((string)($quote['currency'] ?: 'USD'));

        $pages = [];
        $page = [];
        $top = self::header($page, $quote, $agency, $logo);
        $top = self::parties($page, $quote, $top);
        $top += 14;
        [$page, $top] = self::tableHeader($page, $top, $currency);

        $rowIndex = 1;
        foreach ($items as $item) {
            $description = trim((string)($item['description'] ?? ''));
            $lines = self::wrap($description, 250, 8.5);
            $rowHeight = max(22.0, count($lines) * 11.0 + 10.0);

            if ($top + $rowHeight > 742) {
                self::footer($page, $agency);
                $pages[] = $page;
                $page = [];
                $top = self::continuationHeader($page, $quote, $agency, $logo);
                [$page, $top] = self::tableHeader($page, $top, $currency);
            }

            $y = $top;
            if ($rowIndex % 2 === 0) {
                $page[] = self::rect(self::MARGIN, self::ty($y + $rowHeight), self::PAGE_W - self::MARGIN * 2, $rowHeight, self::LIGHT);
            }
            $page[] = self::text(self::MARGIN + 8, self::ty($y + 14), 8.5, (string)$rowIndex, self::MUTED);
            foreach ($lines as $lineIndex => $line) {
                $page[] = self::text(self::MARGIN + 30, self::ty($y + 14 + $lineIndex * 11), 8.5, $line, self::TEXT);
            }
            $page[] = self::textRight(self::MARGIN + 352, self::ty($y + 14), 8.5, self::quantity((float)($item['quantity'] ?? 0)), self::TEXT);
            $page[] = self::textRight(self::MARGIN + 452, self::ty($y + 14), 8.5, self::money((float)($item['unit_price'] ?? 0)), self::TEXT);
            $page[] = self::textRight(self::MARGIN + 507, self::ty($y + 14), 8.5, self::money((float)($item['total'] ?? 0)), self::TEXT, true);
            $page[] = self::line(self::MARGIN, self::ty($y + $rowHeight), self::PAGE_W - self::MARGIN, self::ty($y + $rowHeight), self::BORDER, 0.4);

            $top += $rowHeight;
            $rowIndex++;
        }

        // Si la tabla termina muy abajo, los totales y las notas pasan a una página nueva.
        if ($top + 175 > 760) {
            self::footer($page, $agency);
            $pages[] = $page;
            $page = [];
            $top = self::continuationHeader($page, $quote, $agency, $logo);
        }

        $page[] = self::line(self::MARGIN, self::ty($top), self::PAGE_W - self::MARGIN, self::ty($top), self::NAVY, 0.8);

        $totalsTop = $top + 20;
        $left = self::MARGIN;
        $page[] = self::text($left, self::ty($totalsTop), 7.5, 'NOTAS', self::MUTED, true);
        $notes = trim((string)($quote['notes'] ?? ''));
        $noteLines = $notes !== '' ? self::wrap($notes, 245, 8) : ['Sin notas adicionales.'];
        foreach (array_slice($noteLines, 0, 5) as $index => $line) {
            $page[] = self::text($left, self::ty($totalsTop + 14 + $index * 10), 8, $line, self::TEXT);
        }

        $terms = trim((string)($quote['terms'] ?? ''));
        if ($terms !== '') {
            $termsTop = $totalsTop + 14 + count(array_slice($noteLines, 0, 5)) * 10 + 8;
            $page[] = self::text($left, self::ty($termsTop), 7.5, 'CONDICIONES', self::MUTED, true);
            foreach (array_slice(self::wrap($terms, 245, 7.5), 0, 6) as $index => $line) {
                $page[] = self::text($left, self::ty($termsTop + 13 + $index * 9.5), 7.5, $line, self::TEXT);
            }
        }

        $rightX = 330;
        $totals = [
            ['Subtotal', (float)($quote['subtotal'] ?? 0)],
        ];
        if ((float)($quote['discount_amount'] ?? 0) > 0) {
            $discountLabel = ($quote['discount_type'] ?? 'none') === 'percent'
                ? 'Descuento (' . self::quantity((float)($quote['discount_value'] ?? 0)) . '%)'
                : 'Descuento';
            $totals[] = [$discountLabel, -(float)($quote['discount_amount'] ?? 0)];
        }
        if ((float)($quote['tax_amount'] ?? 0) > 0) {
            $totals[] = ['Impuesto (' . self::quantity((float)($quote['tax_rate'] ?? 0)) . '%)', (float)($quote['tax_amount'] ?? 0)];
        }

        $lineTop = $totalsTop;
        foreach ($totals as [$label, $amount]) {
            $page[] = self::text($rightX, self::ty($lineTop), 8.5, $label, self::MUTED);
            $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty($lineTop), 8.5, self::money($amount), self::TEXT);
            $lineTop += 15;
        }

        $page[] = self::rect($rightX, self::ty($lineTop + 8 + 27), self::PAGE_W - self::MARGIN - $rightX, 27, self::NAVY);
        $page[] = self::text($rightX + 10, self::ty($lineTop + 25), 9, 'TOTAL ' . $currency, self::WHITE, true);
        $page[] = self::textRight(self::PAGE_W - self::MARGIN - 10, self::ty($lineTop + 25), 12, self::money((float)($quote['total'] ?? 0)), self::WHITE, true);

        self::footer($page, $agency);
        $pages[] = $page;

        return self::build($pages, $logo);
    }

    /** @param array<int, string> $page */
    private static function header(array &$page, array $quote, array $agency, ?array $logo): float
    {
        $page[] = self::rect(0, self::ty(8), self::PAGE_W, 8, self::NAVY);

        $x = self::MARGIN;
        $top = 34;
        if ($logo !== null) {
            $scale = min(160 / $logo['width'], 46 / $logo['height']);
            $w = $logo['width'] * $scale;
            $h = $logo['height'] * $scale;
            $page[] = self::image($x, self::ty($top + $h), $w, $h);
        } else {
            $page[] = self::text($x, self::ty($top + 15), 15, self::agencyName($agency), self::NAVY, true);
        }

        $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty(43), 21, 'COTIZACIÓN', self::NAVY, true);
        $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty(60), 9, 'N° ' . (string)($quote['quote_number'] ?? ''), self::BLUE, true);
        $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty(75), 8, 'Fecha: ' . self::date($quote['issue_date'] ?? null), self::MUTED);
        if (!empty($quote['valid_until'])) {
            $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty(87), 8, 'Válida hasta: ' . self::date($quote['valid_until']), self::MUTED);
        }

        $page[] = self::rect(self::MARGIN, self::ty(112), 220, 2.5, self::NAVY);
        $page[] = self::rect(self::MARGIN + 220, self::ty(112), 150, 2.5, self::BLUE);
        $page[] = self::rect(self::MARGIN + 370, self::ty(112), self::PAGE_W - self::MARGIN * 2 - 370, 2.5, self::CYAN);

        return 132;
    }

    /** @param array<int, string> $page */
    private static function continuationHeader(array &$page, array $quote, array $agency, ?array $logo): float
    {
        $page[] = self::rect(0, self::ty(8), self::PAGE_W, 8, self::NAVY);
        if ($logo !== null) {
            $scale = min(100 / $logo['width'], 28 / $logo['height']);
            $page[] = self::image(self::MARGIN, self::ty(30 + $logo['height'] * $scale), $logo['width'] * $scale, $logo['height'] * $scale);
        } else {
            $page[] = self::text(self::MARGIN, self::ty(34), 11, self::agencyName($agency, 28), self::NAVY, true);
        }
        $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty(30), 9, 'COTIZACIÓN ' . (string)($quote['quote_number'] ?? '') . ' · continuación', self::MUTED);

        return 56;
    }

    /** @param array<int, string> $page */
    private static function parties(array &$page, array $quote, float $top): float
    {
        $page[] = self::text(self::MARGIN, self::ty($top), 7.5, 'CLIENTE', self::MUTED, true);
        $page[] = self::text(self::MARGIN, self::ty($top + 16), 11.5, (string)($quote['client_name'] ?? ''), self::NAVY, true);
        $lineTop = $top + 30;
        foreach (['client_company', 'client_email', 'client_phone', 'client_address'] as $field) {
            $value = trim((string)($quote[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach (self::wrap($value, 240, 8) as $line) {
                $page[] = self::text(self::MARGIN, self::ty($lineTop), 8.5, $line, self::TEXT);
                $lineTop += 10.5;
            }
        }

        $rightX = 330;
        $page[] = self::text($rightX, self::ty($top), 7.5, 'PROYECTO', self::MUTED, true);
        $projectName = trim((string)($quote['project_name'] ?? 'Sin proyecto asociado'));
        $page[] = self::text($rightX, self::ty($top + 15), 9, $projectName, self::TEXT, true);
        $page[] = self::text($rightX, self::ty($top + 31), 7.5, 'ESTADO', self::MUTED, true);
        $page[] = self::text($rightX, self::ty($top + 45), 9, self::status((string)($quote['status'] ?? 'borrador')), self::BLUE, true);

        return max($lineTop, $top + 60);
    }

    /** @param array<int, string> $page */
    private static function tableHeader(array &$page, float $top, string $currency): array
    {
        $page[] = self::rect(self::MARGIN, self::ty($top + 24), self::PAGE_W - self::MARGIN * 2, 24, self::NAVY);
        $page[] = self::text(self::MARGIN + 8, self::ty($top + 16), 7.5, '#', self::WHITE, true);
        $page[] = self::text(self::MARGIN + 30, self::ty($top + 16), 7.5, 'DESCRIPCIÓN', self::WHITE, true);
        $page[] = self::textRight(self::MARGIN + 352, self::ty($top + 16), 7.5, 'CANT.', self::WHITE, true);
        $page[] = self::textRight(self::MARGIN + 452, self::ty($top + 16), 7.5, 'PRECIO (' . $currency . ')', self::WHITE, true);
        $page[] = self::textRight(self::MARGIN + 507, self::ty($top + 16), 7.5, 'IMPORTE', self::WHITE, true);

        return [$page, $top + 24];
    }

    /** @param array<int, string> $page */
    private static function footer(array &$page, array $agency): void
    {
        $page[] = self::line(self::MARGIN, self::ty(785), self::PAGE_W - self::MARGIN, self::ty(785), self::BORDER, 0.5);
        $page[] = self::text(self::MARGIN, self::ty(800), 7.5, self::agencyName($agency, 40), self::MUTED, true);
        $page[] = self::textRight(self::PAGE_W - self::MARGIN, self::ty(800), 7.5, 'Generado con Vértice · Página %%PAGE%%', self::MUTED);
    }

    private static function prepareLogo(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return null;
        }
        $scale = min(320 / $width, 120 / $height, 2);
        $targetW = max(1, (int)round($width * $scale));
        $targetH = max(1, (int)round($height * $scale));
        $canvas = imagecreatetruecolor($targetW, $targetH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 90);
        $data = (string)ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        if ($data === '') {
            return null;
        }

        return ['data' => $data, 'width' => $targetW, 'height' => $targetH];
    }

    /** @param string[] $pages */
    private static function build(array $pages, ?array $logo): string
    {
        $pageCount = count($pages);
        $next = 5;
        $imageId = null;
        if ($logo !== null) {
            $imageId = $next++;
        }
        $pageIds = [];
        $contentIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pageIds[] = $next++;
            $contentIds[] = $next++;
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn(int $id) => $id . ' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        if ($logo !== null && $imageId !== null) {
            $objects[$imageId] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($logo['data']) . " >>\nstream\n"
                . $logo['data'] . "\nendstream";
        }

        $resources = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($imageId !== null ? ' /XObject << /Im1 ' . $imageId . ' 0 R >>' : '');
        foreach ($pages as $index => $content) {
            $content = implode("\n", $content);
            $content = str_replace('%%PAGE%%', (string)($index + 1) . ' / ' . $pageCount, $content);
            $objects[$pageIds[$index]] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H
                . '] /Resources << ' . $resources . ' >> /Contents ' . $contentIds[$index] . ' 0 R >>';
            $objects[$contentIds[$index]] = '<< /Length ' . (strlen($content) + 1) . " >>\nstream\n" . $content . "\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private static function image(float $x, float $y, float $w, float $h): string
    {
        return sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $w, $h, $x, $y);
    }

    /** @param array{0:float,1:float,2:float} $color */
    private static function rect(float $x, float $y, float $w, float $h, array $color): string
    {
        return sprintf('%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $color[0], $color[1], $color[2], $x, $y, $w, $h);
    }

    /** @param array{0:float,1:float,2:float} $color */
    private static function line(float $x1, float $y1, float $x2, float $y2, array $color, float $width = 0.5): string
    {
        return sprintf('%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S', $color[0], $color[1], $color[2], $width, $x1, $y1, $x2, $y2);
    }

    /** @param array{0:float,1:float,2:float} $color */
    private static function text(float $x, float $y, float $size, string $text, array $color, bool $bold = false): string
    {
        return sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $color[0], $color[1], $color[2], $x, $y, self::escape($text));
    }

    /** @param array{0:float,1:float,2:float} $color */
    private static function textRight(float $right, float $y, float $size, string $text, array $color, bool $bold = false): string
    {
        $estimated = mb_strlen($text) * $size * 0.52;
        return self::text(max(self::MARGIN, $right - $estimated), $y, $size, $text, $color, $bold);
    }

    private static function ty(float $top): float
    {
        return self::PAGE_H - $top;
    }

    private static function escape(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        $text = $converted !== false ? $converted : $text;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }

    private static function wrap(string $text, float $width, float $size): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return [''];
        }
        $maxChars = max(8, (int)floor($width / ($size * 0.52)));
        $words = explode(' ', $text);
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            while (mb_strlen($word) > $maxChars) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $maxChars);
                $word = mb_substr($word, $maxChars);
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private static function quantity(float $value): string
    {
        $formatted = number_format($value, 2, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private static function date(?string $date): string
    {
        if (empty($date)) {
            return '—';
        }
        $ts = strtotime($date);
        return $ts === false ? (string)$date : date('d/m/Y', $ts);
    }

    private static function status(string $status): string
    {
        return match ($status) {
            'enviada' => 'Enviada',
            'aceptada' => 'Aceptada',
            'rechazada' => 'Rechazada',
            default => 'Borrador',
        };
    }

    /** @param array<string, mixed> $agency */
    private static function agencyName(array $agency, int $max = 34): string
    {
        $name = trim((string)($agency['name'] ?? ''));
        if ($name === '') {
            return 'Vértice';
        }
        return mb_strlen($name) > $max ? mb_substr($name, 0, $max - 1) . '…' : $name;
    }
}

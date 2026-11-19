<?php

namespace App\Support;

/**
 * Generador PDF mínimo para tablas (sin dependencias externas).
 * Usa Helvetica y codificación WinAnsi; suficiente para reportes CSV/PDF.
 */
final class SimplePdf
{
    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;
    private const MARGIN = 36;
    private const ROW_HEIGHT = 17;
    private const HEADER_HEIGHT = 22;
    private const BAND_HEIGHT = 66;

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, scalar|null>> $rows
     * @param array<int, float> $widths
     */
    public static function table(string $title, array $headers, array $rows, array $widths = []): string
    {
        $usableWidth = self::PAGE_WIDTH - (self::MARGIN * 2);
        $columnCount = max(1, count($headers));
        if (count($widths) !== $columnCount || array_sum($widths) <= 0) {
            $widths = array_fill(0, $columnCount, $usableWidth / $columnCount);
        } else {
            $factor = $usableWidth / array_sum($widths);
            $widths = array_map(static fn(float $width) => $width * $factor, $widths);
        }

        $rowsPerPage = 38;
        $chunks = array_chunk($rows, $rowsPerPage);
        if (empty($chunks)) {
            $chunks = [[]];
        }
        $totalPages = count($chunks);
        $generated = date('d/m/Y H:i');
        $meta = number_format(count($rows), 0, ',', '.') . ' registros · Generado ' . $generated;

        $pages = [];
        foreach ($chunks as $pageIndex => $chunk) {
            $commands = [];
            $bandTop = self::PAGE_HEIGHT - self::BAND_HEIGHT;

            $commands[] = self::fillRect(0, $bandTop, self::PAGE_WIDTH, self::BAND_HEIGHT, 0.043, 0.133, 0.278);
            $commands[] = self::fillRect(0, $bandTop, self::PAGE_WIDTH, 4, 0.055, 0.4, 1);
            $pageTitle = $pageIndex === 0 ? $title : $title . ' (continuación)';
            $commands[] = self::text(self::MARGIN, self::PAGE_HEIGHT - 38, 15, self::truncateTitle($pageTitle), 'F2', 1, 1, 1);
            $commands[] = self::text(self::MARGIN, self::PAGE_HEIGHT - 54, 8, $meta, 'F1', 0.78, 0.84, 0.92);

            $y = $bandTop - 22;

            $commands[] = self::fillRect(self::MARGIN, $y - self::HEADER_HEIGHT, $usableWidth, self::HEADER_HEIGHT, 0.937, 0.953, 0.976);
            $x = self::MARGIN;
            foreach ($headers as $index => $header) {
                $commands[] = self::text($x + 4, $y - 14, 8, self::truncate((string)$header, $widths[$index] - 8), 'F2', 0.2, 0.25, 0.33);
                $x += $widths[$index];
            }
            $y -= self::HEADER_HEIGHT;
            $commands[] = self::line(self::MARGIN, $y, self::PAGE_WIDTH - self::MARGIN, $y, 0.796, 0.835, 0.882);

            if ($chunk === []) {
                $commands[] = self::text(self::MARGIN + 4, $y - 22, 9, 'Sin registros para mostrar con los filtros actuales.', 'F1', 0.45, 0.51, 0.6);
            }

            foreach ($chunk as $rowIndex => $row) {
                $rowTop = $y;
                if ($rowIndex % 2 === 1) {
                    $commands[] = self::fillRect(self::MARGIN, $rowTop - self::ROW_HEIGHT, $usableWidth, self::ROW_HEIGHT, 0.976, 0.98, 0.988);
                }
                $x = self::MARGIN;
                foreach (array_values($row) as $index => $value) {
                    if ($index >= $columnCount) {
                        break;
                    }
                    $commands[] = self::text($x + 4, $rowTop - 12, 7.8, self::truncate((string)($value ?? ''), $widths[$index] - 8), 'F1', 0.13, 0.18, 0.25);
                    $x += $widths[$index];
                }
                $y -= self::ROW_HEIGHT;
                $commands[] = self::line(self::MARGIN, $y, self::PAGE_WIDTH - self::MARGIN, $y, 0.898, 0.918, 0.945);
            }

            $commands[] = self::line(self::MARGIN, 52, self::PAGE_WIDTH - self::MARGIN, 52, 0.796, 0.835, 0.882);
            $commands[] = self::text(self::MARGIN, 38, 7.5, 'Vértice · ' . $generated, 'F1', 0.55, 0.6, 0.67);
            $pageLabel = 'Página ' . ($pageIndex + 1) . ' de ' . $totalPages;
            $commands[] = self::textRight(self::PAGE_WIDTH - self::MARGIN, 38, 7.5, $pageLabel, 'F1', 0.55, 0.6, 0.67);

            $pages[] = implode("\n", $commands);
        }

        return self::build($pages);
    }

    private static function truncateTitle(string $value): string
    {
        return mb_strlen($value) > 74 ? mb_substr($value, 0, 73) . '…' : $value;
    }

    private static function truncate(string $value, float $width): string
    {
        $max = max(3, (int)floor($width / 4.35));
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }

    private static function fillRect(float $x, float $y, float $width, float $height, float $r, float $g, float $b): string
    {
        return sprintf('%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $r, $g, $b, $x, $y, $width, $height);
    }

    private static function text(float $x, float $y, float $size, string $text, string $font = 'F1', float $r = 0, float $g = 0, float $b = 0): string
    {
        return sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET', $font, $size, $r, $g, $b, $x, $y, self::escape($text));
    }

    private static function textRight(float $right, float $y, float $size, string $text, string $font = 'F1', float $r = 0, float $g = 0, float $b = 0): string
    {
        $width = mb_strlen($text) * $size * 0.52;
        return self::text($right - $width, $y, $size, $text, $font, $r, $g, $b);
    }

    private static function line(float $x1, float $y, float $x2, ?float $y2 = null, float $r = 0, float $g = 0, float $b = 0): string
    {
        $y2 = $y2 ?? $y;
        return sprintf('%.3F %.3F %.3F RG 0.6 w %.2F %.2F m %.2F %.2F l S', $r, $g, $b, $x1, $y, $x2, $y2);
    }

    private static function escape(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        $text = $converted !== false ? $converted : $text;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }

    /** @param array<int, string> $pages */
    private static function build(array $pages): string
    {
        $pageCount = count($pages);
        $pageIds = [];
        $contentIds = [];
        $next = 5;
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

        foreach ($pages as $index => $content) {
            $objects[$pageIds[$index]] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
                . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT
                . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentIds[$index] . ' 0 R >>';
            $objects[$contentIds[$index]] = '<< /Length ' . (strlen($content) + 1) . " >>\nstream\n" . $content . "\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
        return $pdf;
    }
}

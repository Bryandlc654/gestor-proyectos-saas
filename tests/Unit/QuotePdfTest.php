<?php

namespace Tests\Unit;

use App\Support\QuotePdf;
use PHPUnit\Framework\TestCase;

final class QuotePdfTest extends TestCase
{
    private function quote(array $overrides = []): array
    {
        return array_merge([
            'quote_number' => 'COT-2026-001',
            'issue_date' => '2026-11-09',
            'valid_until' => '2026-11-24',
            'client_name' => 'María López',
            'client_company' => 'Acme S.A.',
            'client_email' => 'maria@acme.com',
            'client_phone' => '+1 555 123 4567',
            'client_address' => 'Av. Principal 123',
            'project_name' => 'Rediseño web',
            'status' => 'enviada',
            'currency' => 'USD',
            'subtotal' => 100,
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'notes' => 'Notas de prueba',
            'terms' => 'Condiciones de prueba',
        ], $overrides);
    }

    public function testRendersAValidSinglePagePdfWithoutLogo(): void
    {
        $pdf = QuotePdf::render(
            $this->quote(),
            [['description' => 'Servicio de diseño', 'quantity' => 1, 'unit_price' => 100, 'total' => 100]],
            ['name' => 'Agencia Demo', 'logo_path' => '']
        );

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith('%%EOF', $pdf);
        $this->assertStringContainsString('COT-2026-001', $pdf);
        $this->assertStringContainsString('(Agencia Demo)', $pdf);
        $this->assertSame(1, preg_match_all('/\/Type \/Page /', $pdf));
        $this->assertStringContainsString('1 / 1)', $pdf);
    }

    public function testLongQuotesPaginateAndKeepFooterPageNumbers(): void
    {
        $items = [];
        for ($i = 1; $i <= 45; $i++) {
            $items[] = [
                'description' => 'Servicio ' . $i . ' con entregables y seguimiento',
                'quantity' => 1,
                'unit_price' => 100,
                'total' => 100,
            ];
        }

        $pdf = QuotePdf::render(
            $this->quote(['total' => 4500, 'subtotal' => 4500]),
            $items,
            ['name' => 'Agencia Demo', 'logo_path' => '']
        );

        $pages = preg_match_all('/\/Type \/Page /', $pdf);
        $this->assertGreaterThan(1, $pages);
        $this->assertStringContainsString('1 / ' . $pages . ')', $pdf);
        $this->assertStringContainsString($pages . ' / ' . $pages . ')', $pdf);
        $this->assertStringContainsString("xref\n0 ", $pdf);
    }
}

<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * An invoice does not change, and that is enforced rather than agreed.
 *
 * German law requires it to be immutable once issued: a correction is a second
 * document, never an edit. A model that quietly allowed `$invoice->update(...)`
 * would make the whole series worthless as evidence — and nothing about the row
 * would show that it had happened, which is the part that matters. A rule
 * enforced by a convention is a rule until somebody is in a hurry.
 */
class AnInvoiceDoesNotChangeTest extends TestCase
{
    private function rechnung(array $werte = []): Invoice
    {
        return Invoice::create(array_merge([
            'number' => 'RE2026-08-001',
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Bärbel Öztürk-Weiß',
            'net_cent' => 10000,
            'tax_cent' => 1900,
            'gross_cent' => 11900,
        ], $werte));
    }

    #[Test]
    public function it_refuses_to_be_edited(): void
    {
        $rechnung = $this->rechnung();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/credit note/');

        $rechnung->update(['buyer_name' => 'Jemand anders']);
    }

    #[Test]
    public function it_refuses_even_a_harmless_looking_change(): void
    {
        // Es gibt keine harmlose Änderung an einem Beweisstück. Ein erlaubtes
        // Feld ist der Anfang einer Gewohnheit, Felder zu erlauben.
        $rechnung = $this->rechnung();

        $this->expectException(\RuntimeException::class);

        $rechnung->update(['meta' => ['notiz' => 'nur eine Notiz']]);
    }

    #[Test]
    public function it_refuses_to_be_deleted(): void
    {
        // Eine Nummer, die verschwindet, ist eine Lücke in der Reihe.
        $rechnung = $this->rechnung();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/gap in the series/');

        $rechnung->delete();
    }

    #[Test]
    public function a_credit_note_is_a_second_document_and_points_at_the_first(): void
    {
        $original = $this->rechnung();
        $storno = $this->rechnung([
            'number' => 'RE2026-08-002',
            'reverses_invoice_id' => $original->id,
        ]);

        $this->assertTrue($storno->isCreditNote());
        $this->assertFalse($original->fresh()->isCreditNote());
        $this->assertSame($original->id, $storno->reverses->id);
    }

    #[Test]
    public function the_amounts_of_a_credit_note_are_not_negative(): void
    {
        // Ein Storno mit Minus vor jeder Zahl liest sich als Rechenoperation.
        // Eines, das sagt was es ist, liest sich als Tatsache — und die Spalten
        // bleiben vorzeichenlos, so wie es die Migration festlegt.
        $original = $this->rechnung();
        $storno = $this->rechnung([
            'number' => 'RE2026-08-002',
            'reverses_invoice_id' => $original->id,
        ]);

        $this->assertGreaterThan(0, $storno->gross_cent);
    }
}

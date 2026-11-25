<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Inclusive prices with a discount: what the lines and the tax add up to is what
 * was paid, to the cent.
 *
 * The discount comes off the gross price first, the tax is split out of what is
 * left, and the unit price is rounded up so that the printed line goes up. The
 * rounding cent lands in the discount. Nothing may be lost on the way: the
 * invoice total is the money that arrived.
 */
class InclusiveTaxWithDiscountTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    /**
     * Ein Kauf mit Positionen: Einzelpreis brutto, Menge und Nachlass je Position.
     * Der bezahlte Betrag ist, was an der Kasse angekommen ist.
     */
    private function bezahltMitPositionen(int $einzelBrutto, int $menge, int $nachlass, ?int $bezahlt = null): Payment
    {
        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs', 'amount_cent' => $bezahlt ?? $einzelBrutto * $menge - $nachlass,
            'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Wer',
            'country' => 'DE', 'paid_at' => now(),
        ]);

        $zahlung->items()->create([
            'product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => $einzelBrutto,
            'quantity' => $menge, 'discount_cent' => $nachlass, 'kind' => 'primary',
        ]);

        return $zahlung->fresh(['items']);
    }

    #[Test]
    public function the_lines_and_the_tax_add_up_to_what_was_paid_at_any_quantity_and_discount(): void
    {
        // Gemessen an Zahlen, bei denen der Nachlass nicht rund auf den Cent
        // zurueckrechnet: 1234 x 7 mit 567 Nachlass, 1999 x 12 mit 1. Unter
        // 250 EUR bleiben, sonst verlangt die Rechnung Kaeuferangaben (§ 14 UStG).
        $faelle = [
            [1000, 1, 1],
            [1000, 1, 999],
            [1000, 2, 1],
            [999, 3, 1],
            [1000, 3, 1],
            [1234, 7, 567],
            [1999, 12, 1],
        ];

        foreach ($faelle as [$einzel, $menge, $nachlass]) {
            $zahlung = $this->bezahltMitPositionen($einzel, $menge, $nachlass);
            $bezahlt = $einzel * $menge - $nachlass;

            $rechnung = app(InvoiceWriter::class)->forPayment($zahlung);
            $fall = "{$menge} x {$einzel} mit Nachlass {$nachlass}";

            $this->assertSame($bezahlt, $rechnung->gross_cent, "Brutto der Rechnung ({$fall})");
            $this->assertSame($bezahlt, $rechnung->net_cent + $rechnung->tax_cent, "Netto + Steuer der Rechnung ({$fall})");

            $summeBrutto = 0;
            $summeSteuer = 0;

            foreach ($rechnung->items as $zeile) {
                $this->assertSame(
                    $zeile->gross_cent,
                    $zeile->net_cent + $zeile->tax_cent,
                    "Netto + Steuer einer Zeile ({$fall})",
                );
                $this->assertSame(
                    $zeile->net_cent,
                    $zeile->unit_net_cent * $zeile->quantity - $zeile->discount_cent,
                    "die gedruckte Zeile geht auf ({$fall})",
                );

                $summeBrutto += $zeile->gross_cent;
                $summeSteuer += $zeile->tax_cent;
            }

            $this->assertSame($bezahlt, $summeBrutto, "Zeilen brutto zusammen ({$fall})");
            $this->assertSame($rechnung->tax_cent, $summeSteuer, "Steuer der Zeilen zusammen ({$fall})");
        }
    }

    #[Test]
    public function a_discount_is_shown_net_not_as_the_gross_amount_the_buyer_saved(): void
    {
        // 1 x 10,00 EUR brutto, 1,19 EUR Nachlass, 19 %: bezahlt 8,81 EUR brutto,
        // netto 7,40. Der Nachlass auf der Zeile ist der Nettoanteil (1,00),
        // nicht die 1,19 brutto; sonst stuende ein Bruttobetrag in einer
        // Nettospalte.
        $zahlung = $this->bezahltMitPositionen(1000, 1, 119);

        $zeile = app(InvoiceWriter::class)->forPayment($zahlung)->items->first();

        $this->assertSame(740, $zeile->net_cent);
        $this->assertSame(100, $zeile->discount_cent);
        $this->assertSame(840, $zeile->unit_net_cent);
    }

    #[Test]
    public function a_discount_on_the_payment_itself_adds_up_to_what_was_paid(): void
    {
        // Ohne Positionen: der Nachlass steht am Zahlungssatz selbst.
        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_nachlass',
            'product' => 'kurs', 'amount_cent' => 11900, 'discount_cent' => 1000,
            'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Wer',
            'country' => 'DE', 'paid_at' => now(),
        ]);

        $rechnung = app(InvoiceWriter::class)->forPayment($zahlung->fresh());

        $this->assertSame(11900, $rechnung->gross_cent);
        $this->assertSame(11900, $rechnung->net_cent + $rechnung->tax_cent);
        $this->assertSame(
            11900,
            array_sum(array_column($rechnung->items->toArray(), 'gross_cent')),
        );
    }

    #[Test]
    public function a_discount_larger_than_the_price_writes_no_negative_line(): void
    {
        // Verhalten unklar: der Code begrenzt den Bruttobetrag einer Position
        // mit max(0, ...). Ob ein Nachlass ueber dem Preis ueberhaupt vorkommen
        // darf, sagt der Code nicht. Festgehalten wird, was jetzt passiert:
        // keine Zeile mit negativem Betrag, und die Rechnung steht bei null.
        $zahlung = $this->bezahltMitPositionen(1000, 1, 1500, bezahlt: 0);

        $rechnung = app(InvoiceWriter::class)->forPayment($zahlung);

        $this->assertSame(0, $rechnung->gross_cent);
        $this->assertCount(1, $rechnung->items, 'Die Schleife unten darf nicht leer laufen.');

        foreach ($rechnung->items as $zeile) {
            $this->assertGreaterThanOrEqual(0, $zeile->gross_cent, 'Brutto einer Zeile ist negativ');
            $this->assertGreaterThanOrEqual(0, $zeile->net_cent, 'Netto einer Zeile ist negativ');
            $this->assertGreaterThanOrEqual(0, $zeile->tax_cent, 'Steuer einer Zeile ist negativ');
        }
    }
}

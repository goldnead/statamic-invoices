<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * The line of prose under the total.
 *
 * Where a rate is not the ordinary one, the law wants it said on the invoice —
 * § 14a UStG for reverse charge, § 19 for the small-business rule. That makes
 * this note a mandatory part of the document rather than a courtesy, and an
 * invoice can carry more than one of them: exempt tuition beside a course at
 * 19% is an ordinary basket for this customer.
 */
class TaxNoteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 11900, 'digital' => true],
            'unterricht' => ['name' => 'Einzelunterricht', 'amount_cent' => 6000, 'digital' => true],
        ]);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard', 'unterricht' => 'exempt_teaching'],
            'exemptions' => [
                'exempt_teaching' => [
                    'reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.',
                    'legal_basis' => '§ 4 Nr. 20 Buchst. a UStG',
                ],
            ],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    private function zahlung(): Payment
    {
        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_1', 'product' => 'kurs',
            'amount_cent' => 17900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'country' => 'DE', 'paid_at' => now(),
        ]);

        $zahlung->items()->createMany([
            ['product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 11900, 'quantity' => 1, 'kind' => 'primary'],
            ['product' => 'unterricht', 'name' => 'Einzelunterricht', 'amount_cent' => 6000, 'quantity' => 1, 'kind' => 'bump'],
        ]);

        return $zahlung->fresh(['items']);
    }

    #[Test]
    public function an_exempt_line_beside_a_taxed_one_gets_its_note(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        $this->assertStringContainsString('§ 4 Nr. 20', (string) $rechnung->tax_note);
    }

    #[Test]
    public function the_ordinary_rate_says_nothing_below_the_total(): void
    {
        // „Umsatzsteuer 19 %" steht schon in der Tabelle. Es darunter zu
        // wiederholen macht Rauschen an genau der Stelle, an der ein Leser nach
        // einer Ausnahme sucht.
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        $this->assertStringNotContainsString('19 %', (string) $rechnung->tax_note);
    }

    #[Test]
    public function an_invoice_with_only_ordinary_lines_has_no_note_at_all(): void
    {
        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_2', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'country' => 'DE', 'paid_at' => now(),
        ]);

        $this->assertNull(app(InvoiceWriter::class)->forPayment($zahlung)->tax_note);
    }

    #[Test]
    public function the_exempt_line_carries_no_tax(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        $befreit = $rechnung->items->firstWhere('product', 'unterricht');

        $this->assertSame(0, $befreit->tax_rate_bp);
        $this->assertSame(0, $befreit->tax_cent);
        $this->assertSame(6000, $befreit->net_cent, 'eine befreite Zeile ist netto gleich brutto');
    }
}

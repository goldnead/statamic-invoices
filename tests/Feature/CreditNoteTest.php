<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A correction is a second document.
 *
 * That rule is what makes a number series usable as evidence, and the model
 * enforces it by refusing every edit. This is the right way to do the thing the
 * model refuses.
 */
class CreditNoteTest extends TestCase
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

    private function bezahlt(): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_1', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE', 'paid_at' => now(),
        ]);
    }

    #[Test]
    public function it_takes_the_next_number_and_points_at_what_it_reverses(): void
    {
        $zahlung = $this->bezahlt();
        $schreiber = app(InvoiceWriter::class);

        $original = $schreiber->forPayment($zahlung);
        $storno = $schreiber->creditNoteFor($zahlung->fresh());

        $this->assertNotSame($original->number, $storno->number);
        $this->assertSame($original->id, $storno->reverses_invoice_id);
        $this->assertSame($original->number, $storno->meta['reverses_number']);
        $this->assertTrue($storno->isCreditNote());
    }

    #[Test]
    public function it_copies_the_tax_rather_than_working_it_out_again(): void
    {
        // Der Satz, der galt, ist der Satz, der galt. Ihn einen Monat später
        // neu nachzuschlagen könnte einen anderen ergeben — und dann heben sich
        // die beiden Dokumente nicht auf.
        $zahlung = $this->bezahlt();
        $schreiber = app(InvoiceWriter::class);

        $original = $schreiber->forPayment($zahlung);

        config(['invoices.tax.zones' => [['countries' => ['DE'], 'rates' => ['standard' => 2500]]]]);

        $storno = $schreiber->creditNoteFor($zahlung->fresh());

        $this->assertSame($original->tax_cent, $storno->tax_cent);
        $this->assertSame(1900, $storno->items->first()->tax_rate_bp);
    }

    #[Test]
    public function the_same_refund_announced_twice_writes_one_credit_note(): void
    {
        $zahlung = $this->bezahlt();
        $schreiber = app(InvoiceWriter::class);

        $schreiber->forPayment($zahlung);

        $this->assertNotNull($schreiber->creditNoteFor($zahlung->fresh()));
        $this->assertNull($schreiber->creditNoteFor($zahlung->fresh()));

        $this->assertSame(2, Invoice::count(), 'Original plus genau ein Storno');
    }

    #[Test]
    public function there_is_nothing_to_reverse_without_an_invoice(): void
    {
        $this->assertNull(app(InvoiceWriter::class)->creditNoteFor($this->bezahlt()));
    }
}

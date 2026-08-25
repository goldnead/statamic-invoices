<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Exceptions\ProductIncomplete;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The six things a review proved wrong, each pinned so it stays fixed.
 *
 * Every one of them was demonstrated rather than suspected — two invoices for
 * one payment on MySQL, a line that did not add up at quantity 3, an invoice
 * whose items could be rewritten underneath it. They are here because a fix
 * without a test is a fix until the next refactor.
 */
class TheCriticsFindingsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 1000, 'digital' => true],
            'ohne-angabe' => ['name' => 'Unbestimmt', 'amount_cent' => 1000],
        ]);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard', 'ohne-angabe' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    private function zahlung(array $werte = [], int $menge = 1): Payment
    {
        $zahlung = Payment::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs', 'amount_cent' => 1000 * $menge, 'currency' => 'EUR',
            'status' => Payment::STATUS_PAID, 'email' => 'wer@example.com',
            'country' => 'DE', 'paid_at' => now(),
        ], $werte));

        if ($menge > 1) {
            $zahlung->items()->create([
                'product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 1000,
                'quantity' => $menge, 'kind' => 'primary',
            ]);
        }

        return $zahlung->fresh(['items']);
    }

    #[Test]
    public function one_payment_can_only_ever_have_one_invoice(): void
    {
        // Bewiesen auf MySQL: fünf gleichzeitige Aufrufe schrieben zwei
        // Rechnungen über denselben Betrag. Der Blick vor der Transaktion hilft
        // nicht — zwei Zustellungen lesen beide nichts. Der Index hilft.
        $zahlung = $this->zahlung();

        Invoice::create([
            'number' => 'RE-X-1', 'payment_id' => $zahlung->id, 'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(), 'currency' => 'EUR', 'net_cent' => 1, 'tax_cent' => 0, 'gross_cent' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::create([
            'number' => 'RE-X-2', 'payment_id' => $zahlung->id, 'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(), 'currency' => 'EUR', 'net_cent' => 1, 'tax_cent' => 0, 'gross_cent' => 1,
        ]);
    }

    #[Test]
    public function a_credit_note_may_share_the_payment_but_only_one_of_it(): void
    {
        $zahlung = $this->zahlung();
        $schreiber = app(InvoiceWriter::class);

        $schreiber->forPayment($zahlung);
        $storno = $schreiber->creditNoteFor($zahlung->fresh());

        $this->assertNotNull($storno, 'ein Storno darf dieselbe Zahlung tragen');
        $this->assertNull($schreiber->creditNoteFor($zahlung->fresh()), 'aber nur eines');
    }

    #[Test]
    public function the_printed_line_adds_up_at_any_quantity(): void
    {
        // Gemessen und falsch: 3 x 10 € brutto druckte „3 × 8,40 €" über einem
        // Netto von 25,21 €. Eine Rechnung, deren sichtbare Zeile nicht
        // aufgeht, ist unbrauchbar, ohne falsch auszusehen.
        foreach ([1, 2, 3, 7, 13] as $menge) {
            $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung([], $menge));

            foreach ($rechnung->items as $zeile) {
                $this->assertSame(
                    $zeile->net_cent,
                    $zeile->unit_net_cent * $zeile->quantity - $zeile->discount_cent,
                    "Einzelpreis × {$menge} − Nachlass ergibt nicht das Netto",
                );
            }

            $this->assertSame(
                $rechnung->net_cent + $rechnung->tax_cent,
                $rechnung->gross_cent,
            );
        }
    }

    #[Test]
    public function a_line_cannot_be_added_to_an_invoice_that_exists(): void
    {
        // Der Weg zur verfälschten Rechnung: eine erfundene Position anhängen,
        // während der Kopf seine Summe behält. Die Vorlage druckt beides.
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        $this->expectException(\RuntimeException::class);

        $rechnung->items()->create([
            'product' => 'erfunden', 'name' => 'Erfunden', 'quantity' => 1,
            'unit_net_cent' => 99999, 'net_cent' => 99999, 'tax_rate_bp' => 1900,
            'tax_cent' => 18999, 'gross_cent' => 118998,
        ]);
    }

    #[Test]
    public function a_line_cannot_be_changed_or_removed(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());
        $zeile = $rechnung->items->first();

        try {
            $zeile->update(['net_cent' => 1]);
            $this->fail('eine Position liess sich ändern');
        } catch (\RuntimeException) {
        }

        try {
            $zeile->delete();
            $this->fail('eine Position liess sich löschen');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, InvoiceItem::count());
    }

    #[Test]
    public function a_product_that_does_not_say_whether_it_is_digital_gets_no_invoice(): void
    {
        // `?? true` machte aus einer Schallplatte eine digitale Leistung und
        // druckte den falschen Pflichthinweis — unveränderlich.
        $this->expectException(ProductIncomplete::class);

        app(InvoiceWriter::class)->forPayment($this->zahlung(['product' => 'ohne-angabe']));
    }

    #[Test]
    public function the_counter_holds_even_without_a_brand(): void
    {
        // `brand_id` war NULL-fähig, und ein Unique-Index bindet bei NULL
        // nicht: zwei Zählerzeilen für dieselbe Reihe gingen durch, auf jeder
        // Installation ohne brand-context.
        $spalten = DB::select('PRAGMA table_info(invoice_counters)');
        $marke = collect($spalten)->firstWhere('name', 'brand_id');

        $this->assertSame(1, $marke->notnull, 'brand_id ist wieder NULL-fähig');
    }
}

<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A paid payment becomes an invoice, and everything on it is frozen there.
 *
 * The rate, the reason, the buyer, the seller — all read once and written as
 * text, because every one of them can change and the invoice may not. Looking
 * anything up at render time would mean an old invoice quietly showing today's
 * answer.
 */
class FromPaymentToInvoiceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => [
                'kurs' => 'standard',
                'noten' => 'reduced',
            ],
            'zones' => [
                ['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
            ],
        ]);
    }

    private function zahlung(array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 11900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => now(),
        ], $werte));
    }

    #[Test]
    public function a_paid_payment_becomes_one_invoice(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        $this->assertNotNull($rechnung);
        $this->assertMatchesRegularExpression('/^RE\d{4}-\d{2}-\d{3}$/', $rechnung->number);
        $this->assertSame(11900, $rechnung->gross_cent);
        $this->assertSame(10000, $rechnung->net_cent, 'Netto aus Brutto bei 19 %');
        $this->assertSame(1900, $rechnung->tax_cent);
    }

    #[Test]
    public function two_deliveries_of_the_same_payment_write_one_invoice(): void
    {
        // Zwei Zustellungen desselben Webhooks kommen zusammen an. Ein zweites
        // Dokument mit einer zweiten Nummer waere ein Beleg zu viel.
        $zahlung = $this->zahlung();
        $schreiber = app(InvoiceWriter::class);

        $erste = $schreiber->forPayment($zahlung);
        $zweite = $schreiber->forPayment($zahlung->fresh());

        $this->assertSame($erste->id, $zweite->id);
        $this->assertSame(1, Invoice::count());
    }

    #[Test]
    public function an_unpaid_payment_gets_nothing(): void
    {
        $this->assertNull(
            app(InvoiceWriter::class)->forPayment($this->zahlung(['status' => Payment::STATUS_OPEN])),
        );
    }

    #[Test]
    public function two_rates_in_one_order_are_two_lines(): void
    {
        // Genau der Fall, für den der Positionsrabatt in payments 1.9 da ist:
        // Noten mit 7 %, Kurs mit 19 %, auf einer Rechnung.
        $zahlung = $this->zahlung(['amount_cent' => 13400]);
        $zahlung->items()->createMany([
            ['product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 11900, 'quantity' => 1, 'kind' => 'primary'],
            ['product' => 'noten', 'name' => 'Noten', 'amount_cent' => 1500, 'quantity' => 1, 'kind' => 'bump'],
        ]);

        $rechnung = app(InvoiceWriter::class)->forPayment($zahlung->fresh(['items']));

        $saetze = $rechnung->items->pluck('tax_rate_bp')->sort()->values()->all();

        $this->assertSame([700, 1900], $saetze);
        $this->assertSame(13400, $rechnung->gross_cent);
        $this->assertSame(
            $rechnung->net_cent + $rechnung->tax_cent,
            $rechnung->gross_cent,
            'Netto und Steuer ergeben nicht den Bruttobetrag',
        );
    }

    #[Test]
    public function the_seller_is_frozen_onto_the_document(): void
    {
        // Eine Rechnung, die sich ändert, wenn jemand eine Einstellung bearbeitet,
        // ist keine.
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        config(['invoices.seller.name' => 'Ein ganz anderer Name']);

        $this->assertSame('Nordlicht Studio', $rechnung->fresh()->seller['name']);
    }

    #[Test]
    public function a_rate_that_cannot_be_determined_writes_no_invoice_at_all(): void
    {
        // Der wichtigste Test des Addons. Ein falscher Satz auf einem
        // Steuerdokument sieht aus wie eine Antwort: still falsch,
        // unterschrieben, und beim Kunden.
        config(['invoices.tax.zones' => []]);

        try {
            app(InvoiceWriter::class)->forPayment($this->zahlung());
            $this->fail('Es wurde eine Rechnung mit geratenem Satz geschrieben.');
        } catch (RateUndetermined $e) {
            $this->assertSame(0, Invoice::count());
            $this->assertNotEmpty($e->lines);
        }
    }

    #[Test]
    public function a_payment_without_a_country_is_not_guessed_at_either(): void
    {
        // Bestehende Zahlungen haben kein Land — die Spalte ist neu. Das darf
        // keine Rechnung mit dem Satz des Verkäuferlandes erzeugen.
        config(['invoices.tax.assume_country_when_missing' => null]);

        $this->expectException(RateUndetermined::class);

        app(InvoiceWriter::class)->forPayment($this->zahlung(['country' => null]));
    }
}

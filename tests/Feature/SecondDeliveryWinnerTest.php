<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zwei Zustellungen derselben Zahlung rennen. Die zweite sieht beim Prüfen
 * keine Rechnung, und ihr Insert schlägt am Unique-Index (payment_id, kind) fehl.
 * Dann ist die Rechnung der ersten die Antwort: genau eine Rechnung existiert,
 * und die zweite bekommt sie zurück, statt zu werfen.
 */
class SecondDeliveryWinnerTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_lost_race_on_the_payment_returns_the_winners_invoice_and_writes_no_second(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00'));

        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_rennen', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE', 'paid_at' => Carbon::parse('2026-08-10 10:00'),
        ]);

        // Der Gewinner: die erste Zustellung hat schon geschrieben.
        $gewinner = app(InvoiceWriter::class)->forPayment($zahlung);
        $this->assertNotNull($gewinner);

        // Die zweite Zustellung hat den Vorab-Check vor dem Schreiben des Gewinners
        // gemacht: sie sieht nichts. Ab dann verhält sich der Schreiber normal.
        $this->app->instance(InvoiceWriter::class, new class($this->app->make(NumberSeries::class)) extends InvoiceWriter
        {
            private bool $erstePruefungNoch = true;

            protected function existing(Payment $payment, string $kind): ?Invoice
            {
                if ($this->erstePruefungNoch) {
                    $this->erstePruefungNoch = false;

                    return null;
                }

                return parent::existing($payment, $kind);
            }
        });

        $antwort = app(InvoiceWriter::class)->forPayment($zahlung->fresh());

        $this->assertNotNull($antwort);
        $this->assertSame($gewinner->getKey(), $antwort->getKey(), 'Die Antwort ist die Rechnung des Gewinners.');
        $this->assertSame(1, Invoice::query()->where('payment_id', $zahlung->getKey())->where('kind', Invoice::KIND_INVOICE)->count());

        // Der Verlierer hat keine Nummer verbrannt: die nächste Rechnung ist die
        // Nummer nach der des Gewinners, ohne Lücke.
        $naechste = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_danach', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE', 'paid_at' => Carbon::parse('2026-08-10 11:00'),
        ]);
        $folge = app(InvoiceWriter::class)->forPayment($naechste);

        $this->assertSame('RE2026-08-001', $gewinner->number);
        $this->assertSame('RE2026-08-002', $folge->number);
    }
}

<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Storno nennt das Leistungsdatum der Rechnung, die es storniert, nicht den
 * Tag, an dem es selbst ausgestellt wird. Sonst steht auf zwei Belegen, die sich
 * aufheben sollen, zwei verschiedene Leistungszeiten.
 */
class CreditNoteServiceDateTest extends TestCase
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

    #[Test]
    public function a_credit_note_for_a_trial_invoice_carries_the_service_period_and_no_service_day(): void
    {
        config()->set('statamic-payments.products', [
            'probeabo' => [
                'name' => 'Probeabo', 'amount_cent' => 900, 'digital' => true,
                'interval' => '1 month', 'trial_days' => 14, 'trial_amount_cent' => 100,
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'UTC'));
        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_probe', 'product' => 'probeabo',
            'amount_cent' => 100, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'chor@example.com', 'name' => 'Testchor 2 e.V.',
            'country' => 'DE', 'paid_at' => Carbon::parse('2026-11-10 10:00', 'UTC'),
        ]);
        $schreiber = app(InvoiceWriter::class);

        $original = $schreiber->forPayment($zahlung);
        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-23'], $original->meta['service_period'] ?? null, 'Vorbedingung');

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00', 'UTC'));
        $storno = $schreiber->creditNoteFor($zahlung->fresh());

        $this->assertSame($original->meta['service_period'], $storno->meta['service_period'] ?? null);
        $this->assertArrayNotHasKey('service_on', $storno->meta);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bezahlt(string $bezahltAm): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_leistung', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE', 'paid_at' => Carbon::parse($bezahltAm),
        ]);
    }

    #[Test]
    public function the_credit_note_carries_the_service_date_of_the_invoice_not_its_own_issue_day(): void
    {
        // Rechnung am 10.08., Storno am 03.09. ausgestellt.
        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00'));
        $zahlung = $this->bezahlt('2026-08-10 10:00');
        $schreiber = app(InvoiceWriter::class);

        $original = $schreiber->forPayment($zahlung);

        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00'));
        $storno = $schreiber->creditNoteFor($zahlung->fresh());

        $this->assertSame('2026-08-10', $original->meta['issued_on'], 'Vorbedingung: die Rechnung steht am 10.08.');
        $this->assertSame('2026-09-03', $storno->meta['issued_on'], 'Vorbedingung: das Storno steht am 03.09.');

        $this->assertSame($original->meta['issued_on'], $storno->meta['service_on']);
        $this->assertNotSame($storno->meta['issued_on'], $storno->meta['service_on']);
    }
}
